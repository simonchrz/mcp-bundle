<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\McpBundle\Tests\Routing;

use PHPUnit\Framework\TestCase;
use Symfony\AI\McpBundle\Routing\RouteLoader;
use Symfony\Component\Routing\Exception\LogicException;

class RouteLoaderTest extends TestCase
{
    public function testDefaultRouteRegistered()
    {
        $loader = new RouteLoader(true, '/_mcp');
        $collection = $loader->load(null, 'mcp');

        $this->assertCount(1, $collection);
        $this->assertNotNull($collection->get('_mcp_endpoint'));
        $this->assertSame('/_mcp', $collection->get('_mcp_endpoint')->getPath());
    }

    public function testAdditionalRoutesRegistered()
    {
        $additionalRoutes = [
            '/.well-known/oauth-protected-resource',
            '/.well-known/oauth-authorization-server',
            '/authorize',
            '/token',
            '/register',
        ];

        $loader = new RouteLoader(true, '/_mcp', $additionalRoutes);
        $collection = $loader->load(null, 'mcp');

        $this->assertCount(6, $collection);
        $this->assertNotNull($collection->get('_mcp_endpoint'));

        foreach ($additionalRoutes as $i => $path) {
            $route = $collection->get('_mcp_route_'.$i);
            $this->assertNotNull($route, \sprintf('Route _mcp_route_%d should exist', $i));
            $this->assertSame($path, $route->getPath());
            $this->assertSame('mcp.server.controller::handle', $route->getDefault('_controller'));
        }
    }

    public function testHttpDisabledReturnsEmptyCollection()
    {
        $loader = new RouteLoader(false, '/_mcp', ['/authorize']);
        $collection = $loader->load(null, 'mcp');

        $this->assertCount(0, $collection);
    }

    public function testDoubleLoadThrowsException()
    {
        $loader = new RouteLoader(true, '/_mcp');
        $loader->load(null, 'mcp');

        $this->expectException(LogicException::class);
        $loader->load(null, 'mcp');
    }

    public function testSupportsOnlyMcpType()
    {
        $loader = new RouteLoader(true, '/_mcp');

        $this->assertTrue($loader->supports(null, 'mcp'));
        $this->assertFalse($loader->supports(null, 'other'));
    }
}
