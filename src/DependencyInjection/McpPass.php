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
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

final class McpPass implements CompilerPassInterface
{
    use PriorityTaggedServiceTrait;

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('mcp.server.builder')) {
            return;
        }

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

        $this->configureSecurity($container);
    }

    private function configureSecurity(ContainerBuilder $container): void
    {
        if ($container->hasParameter('mcp.security.enabled') && false === $container->getParameter('mcp.security.enabled')) {
            return;
        }

        if (!$container->hasDefinition('security.authorization_checker') && !$container->hasAlias('security.authorization_checker')) {
            return;
        }

        $container->setDefinition('mcp.is_granted_checker', (new Definition(IsGrantedChecker::class))
            ->setArguments([new Reference('security.authorization_checker')]));

        $container->setDefinition(FilteredListToolsHandler::class, (new Definition(FilteredListToolsHandler::class))
            ->setArguments([
                new Reference('mcp.registry'),
                new Reference('mcp.is_granted_checker'),
            ])
            ->setAutoconfigured(true)
            ->addTag('mcp.request_handler'));
    }
}
