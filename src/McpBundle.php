<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\McpBundle;

use Http\Discovery\Psr17Factory;
use Mcp\Capability\Attribute\McpPrompt;
use Mcp\Capability\Attribute\McpResource;
use Mcp\Capability\Attribute\McpResourceTemplate;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Registry\Loader\LoaderInterface;
use Mcp\Server\Handler\Notification\NotificationHandlerInterface;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Transport\Http\Middleware\AuthorizationMiddleware;
use Mcp\Server\Transport\Http\Middleware\ClientRegistrationMiddleware;
use Mcp\Server\Transport\Http\Middleware\OAuthProxyMiddleware;
use Mcp\Server\Transport\Http\Middleware\OAuthRequestMetaMiddleware;
use Mcp\Server\Transport\Http\Middleware\ProtectedResourceMetadataMiddleware;
use Mcp\Server\Transport\Http\OAuth\ClientRegistrarInterface;
use Mcp\Server\Transport\Http\OAuth\AuthorizationTokenValidatorInterface;
use Mcp\Server\Transport\Http\OAuth\JwksProvider;
use Mcp\Server\Transport\Http\OAuth\JwtTokenValidator;
use Mcp\Server\Transport\Http\OAuth\OidcDiscovery;
use Mcp\Server\Transport\Http\OAuth\ProtectedResourceMetadata;
use Psr\Http\Server\MiddlewareInterface;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\Psr16SessionStore;
use Symfony\AI\McpBundle\Command\McpCommand;
use Symfony\AI\McpBundle\Controller\McpController;
use Symfony\AI\McpBundle\DependencyInjection\McpPass;
use Symfony\AI\McpBundle\DependencyInjection\MiddlewarePriorityPass;
use Symfony\AI\McpBundle\Handler\FilteredListToolsHandler;
use Symfony\AI\McpBundle\Middleware\SymfonySecurityMiddleware;
use Symfony\AI\McpBundle\Test\TestSecurityMiddleware;
use Symfony\AI\McpBundle\Profiler\DataCollector;
use Symfony\AI\McpBundle\Profiler\TraceableRegistry;
use Symfony\AI\McpBundle\Routing\RouteLoader;
use Symfony\AI\McpBundle\Security\SecurityReferenceHandler;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class McpBundle extends AbstractBundle
{
    private const DEFAULT_OAUTH_ROUTES = [
        '/.well-known/oauth-protected-resource',
        '/.well-known/oauth-authorization-server',
        '/authorize',
        '/token',
        '/register',
    ];

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->import('../config/options.php');
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import('../config/services.php');

        $builder->setParameter('mcp.app', $config['app']);
        $builder->setParameter('mcp.version', $config['version']);
        $builder->setParameter('mcp.description', $config['description']);
        $builder->setParameter('mcp.website_url', $config['website_url']);
        $builder->setParameter('mcp.icons', $config['icons']);
        $builder->setParameter('mcp.pagination_limit', $config['pagination_limit']);
        $builder->setParameter('mcp.instructions', $config['instructions']);
        $isTestEnv = 'test' === $builder->getParameter('kernel.environment');
        $oauthEnabled = ($config['http']['oauth']['enabled'] ?? false) && !$isTestEnv;
        $securityMiddleware = $config['http']['security_middleware'] ?? null;

        if (null === $securityMiddleware && $isTestEnv && !$oauthEnabled) {
            $securityMiddleware = TestSecurityMiddleware::class;
            $builder->register(TestSecurityMiddleware::class)
                ->setArguments([new Reference('security.token_storage')])
                ->setAutoconfigured(true);
        }

        $middleware = $config['http']['middleware'];
        if ([] === $middleware && $oauthEnabled) {
            $middleware = self::getDefaultOAuthMiddleware($securityMiddleware);
        } elseif ([] === $middleware && null !== $securityMiddleware) {
            $middleware = [$securityMiddleware];
        }
        $builder->setParameter('mcp.http.middleware', $middleware);

        $routes = $config['http']['routes'];
        if ([] === $routes && $oauthEnabled) {
            $routes = self::DEFAULT_OAUTH_ROUTES;
        }
        $builder->setParameter('mcp.http.routes', $routes);
        $builder->setParameter('mcp.discovery.scan_dirs', $config['discovery']['scan_dirs']);
        $builder->setParameter('mcp.discovery.exclude_dirs', $config['discovery']['exclude_dirs']);

        $this->registerMcpAttributes($builder);

        $builder->registerForAutoconfiguration(LoaderInterface::class)
            ->addTag('mcp.loader');

        $builder->registerForAutoconfiguration(RequestHandlerInterface::class)
            ->addTag('mcp.request_handler');

        $builder->registerForAutoconfiguration(NotificationHandlerInterface::class)
            ->addTag('mcp.notification_handler');

        $builder->registerForAutoconfiguration(MiddlewareInterface::class)
            ->addTag('mcp.middleware');

        $referenceHandler = $config['reference_handler'];
        if (null === $referenceHandler && ($config['http']['oauth']['enabled'] ?? false)) {
            $referenceHandler = 'mcp.security_reference_handler';
        }
        if (null !== $referenceHandler) {
            $builder->getDefinition('mcp.server.builder')
                ->addMethodCall('setReferenceHandler', [new Reference($referenceHandler)]);
        }

        if ($builder->getParameter('kernel.debug')) {
            $traceableRegistry = (new Definition('mcp.traceable_registry'))
                ->setClass(TraceableRegistry::class)
                ->setArguments([new Reference('.inner')])
                ->setDecoratedService('mcp.registry')
                ->addTag('kernel.reset', ['method' => 'reset']);
            $builder->setDefinition('mcp.traceable_registry', $traceableRegistry);

            $dataCollector = (new Definition(DataCollector::class))
                ->setArguments([new Reference('mcp.traceable_registry')])
                ->addTag('data_collector', ['id' => 'mcp']);
            $builder->setDefinition('mcp.data_collector', $dataCollector);
        }

        if (isset($config['client_transports'])) {
            $this->configureClient($config['client_transports'], $config['http'], $builder);
        }
    }

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new McpPass());
        $container->addCompilerPass(new MiddlewarePriorityPass());
    }

    /**
     * @return list<string>
     */
    private static function getDefaultOAuthMiddleware(?string $securityMiddleware = null): array
    {
        return [
            ProtectedResourceMetadataMiddleware::class,
            ClientRegistrationMiddleware::class,
            OAuthProxyMiddleware::class,
            AuthorizationMiddleware::class,
            $securityMiddleware ?? SymfonySecurityMiddleware::class,
            OAuthRequestMetaMiddleware::class,
        ];
    }

    private function registerMcpAttributes(ContainerBuilder $builder): void
    {
        $mcpAttributes = [
            McpTool::class => 'mcp.tool',
            McpPrompt::class => 'mcp.prompt',
            McpResource::class => 'mcp.resource',
            McpResourceTemplate::class => 'mcp.resource_template',
        ];

        foreach ($mcpAttributes as $attributeClass => $tag) {
            $builder->registerAttributeForAutoconfiguration(
                $attributeClass,
                static function (ChildDefinition $definition, object $attribute, \Reflector $reflector) use ($tag): void {
                    $definition->addTag($tag);
                }
            );
        }
    }

    /**
     * @param array{stdio: bool, http: bool}                                                                                                                                                                                                                                                                                          $transports
     * @param array{path: string, routes: list<string>, security_middleware: string|null, session: array{store: string, directory: string, cache_pool: string, prefix: string, ttl: int}, middleware: list<string>, oauth: array{enabled: bool, issuer: string, base_url: string, client_id: string, roles_claim: string, scopes: list<string>}} $httpConfig
     */
    private function configureClient(array $transports, array $httpConfig, ContainerBuilder $container): void
    {
        if (!$transports['stdio'] && !$transports['http']) {
            return;
        }

        // Register PSR factories
        $container->register('mcp.psr17_factory', Psr17Factory::class);

        $container->register('mcp.psr_http_factory', PsrHttpFactory::class)
            ->setArguments([
                new Reference('mcp.psr17_factory'),
                new Reference('mcp.psr17_factory'),
                new Reference('mcp.psr17_factory'),
                new Reference('mcp.psr17_factory'),
            ]);

        $container->register('mcp.http_foundation_factory', HttpFoundationFactory::class);

        // Configure session store based on HTTP config
        $this->configureSessionStore($httpConfig['session'], $container);

        if ($transports['stdio']) {
            $container->register('mcp.server.command', McpCommand::class)
                ->setArguments([
                    new Reference('mcp.server'),
                    new Reference('logger'),
                ])
                ->addTag('console.command')
                ->addTag('monolog.logger', ['channel' => 'mcp']);
        }

        if ($transports['http']) {
            $container->register('mcp.server.controller', McpController::class)
                ->setArguments([
                    new Reference('mcp.server'),
                    new Reference('mcp.psr_http_factory'),
                    new Reference('mcp.http_foundation_factory'),
                    new Reference('mcp.psr17_factory'),
                    new Reference('mcp.psr17_factory'),
                    new TaggedIteratorArgument('mcp.middleware'),
                    new Reference('logger'),
                ])
                ->setPublic(true)
                ->addTag('controller.service_arguments')
                ->addTag('monolog.logger', ['channel' => 'mcp']);
        }

        $container->register('mcp.server.route_loader', RouteLoader::class)
            ->setArguments([
                $transports['http'],
                $httpConfig['path'],
                '%mcp.http.routes%',
            ])
            ->addTag('routing.loader');

        $container->register(FilteredListToolsHandler::class)
            ->setArguments([
                new Reference('mcp.registry'),
                new Reference('security.authorization_checker'),
                new Reference('security.token_storage'),
            ])
            ->setAutoconfigured(true);

        if ($httpConfig['oauth']['enabled']) {
            $this->configureOAuth($httpConfig['oauth'], $container);
        }
    }

    /**
     * @param array{issuer: string, base_url: string, client_id: string, roles_claim: string, scopes: list<string>} $oauthConfig
     */
    private function configureOAuth(array $oauthConfig, ContainerBuilder $container): void
    {
        foreach (['issuer', 'base_url', 'client_id'] as $required) {
            if (null === ($oauthConfig[$required] ?? null) || '' === $oauthConfig[$required]) {
                throw new \LogicException(\sprintf('The "mcp.http.oauth.%s" option is required when OAuth is enabled.', $required));
            }
        }

        $container->register('mcp.oauth.discovery', OidcDiscovery::class)
            ->setArguments([
                null, // PSR-18 HttpClient, auto-discovered
                new Reference('mcp.psr17_factory'),
                new Reference('Psr\SimpleCache\CacheInterface'),
            ]);

        $container->register('mcp.oauth.jwks_provider', JwksProvider::class)
            ->setArguments([
                new Reference('mcp.oauth.discovery'),
                null, // PSR-18 HttpClient, auto-discovered
                new Reference('mcp.psr17_factory'),
                new Reference('Psr\SimpleCache\CacheInterface'),
            ]);

        $container->register('mcp.oauth.token_validator', JwtTokenValidator::class)
            ->setArguments([
                $oauthConfig['issuer'],
                $oauthConfig['client_id'],
                new Reference('mcp.oauth.jwks_provider'),
            ]);
        $container->setAlias(AuthorizationTokenValidatorInterface::class, 'mcp.oauth.token_validator');

        $container->register('mcp.oauth.resource_metadata', ProtectedResourceMetadata::class)
            ->setArguments([
                [$oauthConfig['base_url']],
                $oauthConfig['scopes'],
            ]);

        $container->register(ProtectedResourceMetadataMiddleware::class)
            ->setArguments([new Reference('mcp.oauth.resource_metadata')])
            ->setAutoconfigured(true);

        $container->register(OAuthProxyMiddleware::class)
            ->setArguments([
                $oauthConfig['issuer'],
                $oauthConfig['base_url'],
                new Reference('mcp.oauth.discovery'),
            ])
            ->setAutoconfigured(true);

        $container->register(AuthorizationMiddleware::class)
            ->setArguments([
                new Reference('mcp.oauth.token_validator'),
                new Reference('mcp.oauth.resource_metadata'),
            ])
            ->setAutoconfigured(true);

        $container->register(OAuthRequestMetaMiddleware::class)
            ->setAutoconfigured(true);

        $container->register(ClientRegistrationMiddleware::class)
            ->setArguments([
                new Reference(ClientRegistrarInterface::class),
                $oauthConfig['base_url'],
            ])
            ->setAutoconfigured(true);

        $container->register(SymfonySecurityMiddleware::class)
            ->setArguments([
                new Reference('security.token_storage'),
                $oauthConfig['roles_claim'],
            ])
            ->setAutoconfigured(true);

        $container->register('mcp.security_reference_handler', SecurityReferenceHandler::class)
            ->setArguments([
                new Reference('mcp.reference_handler'),
                new Reference('security.authorization_checker'),
            ]);
    }

    /**
     * @param array{store: string, directory: string, cache_pool: string, prefix: string, ttl: int} $sessionConfig
     */
    private function configureSessionStore(array $sessionConfig, ContainerBuilder $container): void
    {
        if ('memory' === $sessionConfig['store']) {
            $container->register('mcp.session.store', InMemorySessionStore::class)
                ->setArguments([$sessionConfig['ttl']]);
        } elseif ('cache' === $sessionConfig['store']) {
            $cachePoolId = $sessionConfig['cache_pool'];

            // Create the default cache pool as a PSR-16 wrapper around cache.app if it doesn't exist
            if ('cache.mcp.sessions' === $cachePoolId && !$container->hasDefinition($cachePoolId) && !$container->hasAlias($cachePoolId)) {
                $container->register($cachePoolId, Psr16Cache::class)
                    ->setArguments([new Reference('cache.app')]);
            }

            $container->register('mcp.session.store', Psr16SessionStore::class)
                ->setArguments([
                    new Reference($sessionConfig['cache_pool']),
                    $sessionConfig['prefix'],
                    $sessionConfig['ttl'],
                ]);
        } else {
            $container->register('mcp.session.store', FileSessionStore::class)
                ->setArguments([$sessionConfig['directory'], $sessionConfig['ttl']]);
        }
    }
}
