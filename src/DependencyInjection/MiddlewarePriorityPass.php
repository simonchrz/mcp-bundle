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

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class MiddlewarePriorityPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('mcp.http.middleware')) {
            return;
        }

        /** @var list<string> $middlewareOrder */
        $middlewareOrder = $container->getParameter('mcp.http.middleware');

        if ([] === $middlewareOrder) {
            return;
        }

        // Build a class-to-priority map: first in list = highest priority
        $count = \count($middlewareOrder);
        $priorityByClass = [];
        foreach ($middlewareOrder as $i => $class) {
            $priorityByClass[$class] = $count - $i;
        }

        foreach ($container->findTaggedServiceIds('mcp.middleware') as $serviceId => $tags) {
            $definition = $container->getDefinition($serviceId);
            $class = $definition->getClass() ?? $serviceId;

            if (!isset($priorityByClass[$class])) {
                continue;
            }

            // Remove existing mcp.middleware tags and re-add with priority
            $definition->clearTag('mcp.middleware');
            $definition->addTag('mcp.middleware', ['priority' => $priorityByClass[$class]]);
        }
    }
}
