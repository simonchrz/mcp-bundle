<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\McpBundle\DependencyInjection;

use Symfony\AI\McpBundle\Handler\FilteredListToolsHandler;
use Symfony\AI\McpBundle\Security\IsGrantedChecker;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PriorityTaggedServiceTrait;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class McpPass implements CompilerPassInterface
{
    use PriorityTaggedServiceTrait;

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('mcp.server.builder')) {
            return;
        }

        $this->registerSecurity($container);
        $this->registerServiceLocator($container);
    }

    private function registerSecurity(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('security.authorization_checker') && !$container->hasAlias('security.authorization_checker')) {
            return;
        }

        if (!$container->hasDefinition('mcp.is_granted_checker')) {
            $container->register('mcp.is_granted_checker', IsGrantedChecker::class)
                ->setArguments([new Reference('security.authorization_checker')]);
        }

        if (!$container->hasDefinition(FilteredListToolsHandler::class)) {
            $container->register(FilteredListToolsHandler::class, FilteredListToolsHandler::class)
                ->setArguments([
                    new Reference('mcp.registry'),
                    new Reference('mcp.is_granted_checker'),
                    new Reference('security.token_storage'),
                ])
                ->addTag('mcp.request_handler');
        }
    }

    private function registerServiceLocator(ContainerBuilder $container): void
    {
        $allMcpServices = [];
        $mcpTags = ['mcp.tool', 'mcp.prompt', 'mcp.resource', 'mcp.resource_template'];

        foreach ($mcpTags as $tag) {
            $taggedServices = $container->findTaggedServiceIds($tag);
            $allMcpServices = array_merge($allMcpServices, $taggedServices);
        }

        if ([] === $allMcpServices) {
            return;
        }

        $serviceReferences = [];
        foreach (array_keys($allMcpServices) as $serviceId) {
            $serviceReferences[$serviceId] = new Reference($serviceId);
        }

        $serviceLocatorRef = ServiceLocatorTagPass::register($container, $serviceReferences);
        $container->getDefinition('mcp.server.builder')->addMethodCall('setContainer', [$serviceLocatorRef]);

        if ($container->hasDefinition('mcp.reference_handler')) {
            $container->getDefinition('mcp.reference_handler')->setArgument(0, $serviceLocatorRef);
        }
    }
}
