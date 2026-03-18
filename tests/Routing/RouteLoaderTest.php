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

        $expectedNames = [
            '_mcp_well_known_oauth_protected_resource',
            '_mcp_well_known_oauth_authorization_server',
            '_mcp_authorize',
            '_mcp_token',
            '_mcp_register',
        ];

        foreach ($expectedNames as $i => $name) {
            $route = $collection->get($name);
            $this->assertNotNull($route, \sprintf('Route %s should exist', $name));
            $this->assertSame($additionalRoutes[$i], $route->getPath());
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
