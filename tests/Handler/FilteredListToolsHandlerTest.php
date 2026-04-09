<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\McpBundle\Tests\Handler;

use Mcp\Capability\Registry\ToolReference;
use Mcp\Capability\RegistryInterface;
use Mcp\Schema\Page;
use Mcp\Schema\Request\ListToolsRequest;
use Mcp\Schema\Result\ListToolsResult;
use Mcp\Schema\Tool;
use Mcp\Server\Session\SessionInterface;
use PHPUnit\Framework\TestCase;
use Symfony\AI\McpBundle\Handler\FilteredListToolsHandler;
use Symfony\AI\McpBundle\Security\IsGrantedCheckerInterface;

final class FilteredListToolsHandlerTest extends TestCase
{
    public function testSupportsListToolsRequest(): void
    {
        $handler = new FilteredListToolsHandler(
            $this->createStub(RegistryInterface::class),
            $this->createStub(IsGrantedCheckerInterface::class),
        );

        $this->assertTrue($handler->supports((new ListToolsRequest())->withId('1')));
    }

    public function testPublicToolsVisibleWithoutAuthentication(): void
    {
        $tool = new Tool('public_tool', ['type' => 'object', 'properties' => [], 'required' => null], null, null);
        $ref = new ToolReference($tool, [self::class, 'dummyAllowed']);

        $registry = $this->createStub(RegistryInterface::class);
        $registry->method('getTools')->willReturn(new Page([$tool], null));
        $registry->method('getTool')->willReturn($ref);

        $checker = $this->createStub(IsGrantedCheckerInterface::class);
        $checker->method('isGranted')->willReturn(true);

        $handler = new FilteredListToolsHandler($registry, $checker);
        $response = $handler->handle((new ListToolsRequest())->withId('1'), $this->createStub(SessionInterface::class));

        $this->assertInstanceOf(ListToolsResult::class, $response->result);
        $this->assertCount(1, $response->result->tools);
        $this->assertSame('public_tool', $response->result->tools[0]->name);
    }

    public function testProtectedToolsHiddenFromUnauthorizedUsers(): void
    {
        $tool = new Tool('protected_tool', ['type' => 'object', 'properties' => [], 'required' => null], null, null);
        $ref = new ToolReference($tool, [self::class, 'dummyAllowed']);

        $registry = $this->createStub(RegistryInterface::class);
        $registry->method('getTools')->willReturn(new Page([$tool], null));
        $registry->method('getTool')->willReturn($ref);

        $checker = $this->createStub(IsGrantedCheckerInterface::class);
        $checker->method('isGranted')->willReturn(false);

        $handler = new FilteredListToolsHandler($registry, $checker);
        $response = $handler->handle((new ListToolsRequest())->withId('1'), $this->createStub(SessionInterface::class));

        $this->assertCount(0, $response->result->tools);
    }

    public function testFiltersToolsByAuthorization(): void
    {
        $allowedTool = new Tool('allowed', ['type' => 'object', 'properties' => [], 'required' => null], null, null);
        $deniedTool = new Tool('denied', ['type' => 'object', 'properties' => [], 'required' => null], null, null);

        $allowedRef = new ToolReference($allowedTool, [self::class, 'dummyAllowed']);
        $deniedRef = new ToolReference($deniedTool, [self::class, 'dummyDenied']);

        $registry = $this->createStub(RegistryInterface::class);
        $registry->method('getTools')->willReturn(new Page([$allowedTool, $deniedTool], null));
        $registry->method('getTool')->willReturnCallback(
            static fn (string $name) => match ($name) {
                'allowed' => $allowedRef,
                'denied' => $deniedRef,
                default => throw new \Mcp\Exception\InvalidArgumentException('Unknown tool: '.$name),
            }
        );

        $checker = $this->createStub(IsGrantedCheckerInterface::class);
        $checker->method('isGranted')->willReturnCallback(
            static fn (array $handler) => 'dummyAllowed' === $handler[1]
        );

        $handler = new FilteredListToolsHandler($registry, $checker);
        $response = $handler->handle((new ListToolsRequest())->withId('1'), $this->createStub(SessionInterface::class));

        $this->assertCount(1, $response->result->tools);
        $this->assertSame('allowed', $response->result->tools[0]->name);
    }

    public function testAllowsToolWithNonArrayHandler(): void
    {
        $tool = new Tool('closure_tool', ['type' => 'object', 'properties' => [], 'required' => null], null, null);
        $ref = new ToolReference($tool, static fn () => null);

        $registry = $this->createStub(RegistryInterface::class);
        $registry->method('getTools')->willReturn(new Page([$tool], null));
        $registry->method('getTool')->willReturn($ref);

        $handler = new FilteredListToolsHandler($registry, $this->createStub(IsGrantedCheckerInterface::class));
        $response = $handler->handle((new ListToolsRequest())->withId('1'), $this->createStub(SessionInterface::class));

        $this->assertCount(1, $response->result->tools);
        $this->assertSame('closure_tool', $response->result->tools[0]->name);
    }

    public static function dummyAllowed(): void
    {
    }

    public static function dummyDenied(): void
    {
    }
}
