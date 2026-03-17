<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\McpBundle\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\AI\McpBundle\DependencyInjection\MiddlewarePriorityPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

class MiddlewarePriorityPassTest extends TestCase
{
    public function testPriorityAssignedByOrder()
    {
        $container = new ContainerBuilder();
        $container->setParameter('mcp.http.middleware', [
            'App\Middleware\First',
            'App\Middleware\Second',
            'App\Middleware\Third',
        ]);

        $first = new Definition('App\Middleware\First');
        $first->addTag('mcp.middleware');
        $container->setDefinition('middleware.first', $first);

        $second = new Definition('App\Middleware\Second');
        $second->addTag('mcp.middleware');
        $container->setDefinition('middleware.second', $second);

        $third = new Definition('App\Middleware\Third');
        $third->addTag('mcp.middleware');
        $container->setDefinition('middleware.third', $third);

        (new MiddlewarePriorityPass())->process($container);

        $this->assertSame([['priority' => 3]], $container->getDefinition('middleware.first')->getTag('mcp.middleware'));
        $this->assertSame([['priority' => 2]], $container->getDefinition('middleware.second')->getTag('mcp.middleware'));
        $this->assertSame([['priority' => 1]], $container->getDefinition('middleware.third')->getTag('mcp.middleware'));
    }

    public function testMiddlewareNotInListKeepsOriginalTag()
    {
        $container = new ContainerBuilder();
        $container->setParameter('mcp.http.middleware', [
            'App\Middleware\Listed',
        ]);

        $listed = new Definition('App\Middleware\Listed');
        $listed->addTag('mcp.middleware');
        $container->setDefinition('middleware.listed', $listed);

        $unlisted = new Definition('App\Middleware\Unlisted');
        $unlisted->addTag('mcp.middleware', ['priority' => 99]);
        $container->setDefinition('middleware.unlisted', $unlisted);

        (new MiddlewarePriorityPass())->process($container);

        $this->assertSame([['priority' => 1]], $container->getDefinition('middleware.listed')->getTag('mcp.middleware'));
        $this->assertSame([['priority' => 99]], $container->getDefinition('middleware.unlisted')->getTag('mcp.middleware'));
    }

    public function testEmptyMiddlewareListIsNoop()
    {
        $container = new ContainerBuilder();
        $container->setParameter('mcp.http.middleware', []);

        $definition = new Definition('App\Middleware\Some');
        $definition->addTag('mcp.middleware', ['priority' => 5]);
        $container->setDefinition('middleware.some', $definition);

        (new MiddlewarePriorityPass())->process($container);

        $this->assertSame([['priority' => 5]], $container->getDefinition('middleware.some')->getTag('mcp.middleware'));
    }

    public function testNoParameterIsNoop()
    {
        $container = new ContainerBuilder();

        $definition = new Definition('App\Middleware\Some');
        $definition->addTag('mcp.middleware');
        $container->setDefinition('middleware.some', $definition);

        (new MiddlewarePriorityPass())->process($container);

        $this->assertSame([[]], $container->getDefinition('middleware.some')->getTag('mcp.middleware'));
    }
}
