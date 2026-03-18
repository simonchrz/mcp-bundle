<?php

namespace Symfony\AI\McpBundle\Tests\Session;

use PHPUnit\Framework\TestCase;
use Symfony\AI\McpBundle\Session\FrameworkSessionStore;
use Symfony\Component\Uid\Uuid;

final class FrameworkSessionStoreTest extends TestCase
{
    private const PREFIX = 'mcp-';

    public function testReadReturnsDataFromHandler(): void
    {
        $id = Uuid::v4();
        $handler = self::createMock(\SessionHandlerInterface::class);
        $handler->expects(self::once())
            ->method('read')
            ->with(self::PREFIX.$id)
            ->willReturn('session-data');

        $store = new FrameworkSessionStore($handler, self::PREFIX);

        self::assertSame('session-data', $store->read($id));
    }

    public function testReadReturnsFalseForEmptyString(): void
    {
        $handler = self::createStub(\SessionHandlerInterface::class);
        $handler->method('read')->willReturn('');

        $store = new FrameworkSessionStore($handler, self::PREFIX);

        self::assertFalse($store->read(Uuid::v4()));
    }

    public function testWriteDelegatesToHandler(): void
    {
        $id = Uuid::v4();
        $handler = self::createMock(\SessionHandlerInterface::class);
        $handler->expects(self::once())
            ->method('write')
            ->with(self::PREFIX.$id, 'data')
            ->willReturn(true);

        $store = new FrameworkSessionStore($handler, self::PREFIX);

        self::assertTrue($store->write($id, 'data'));
    }

    public function testDestroyDelegatesToHandler(): void
    {
        $id = Uuid::v4();
        $handler = self::createMock(\SessionHandlerInterface::class);
        $handler->expects(self::once())
            ->method('destroy')
            ->with(self::PREFIX.$id)
            ->willReturn(true);

        $store = new FrameworkSessionStore($handler, self::PREFIX);

        self::assertTrue($store->destroy($id));
    }

    public function testExistsUsesValidateIdWhenAvailable(): void
    {
        $id = Uuid::v4();
        $handler = self::createMock(SessionHandlerWithTimestamp::class);
        $handler->expects(self::once())
            ->method('validateId')
            ->with(self::PREFIX.$id)
            ->willReturn(true);
        $handler->expects(self::never())->method('read');

        $store = new FrameworkSessionStore($handler, self::PREFIX);

        self::assertTrue($store->exists($id));
    }

    public function testExistsFallsBackToReadWithoutValidateId(): void
    {
        $handler = self::createMock(\SessionHandlerInterface::class);
        $handler->expects(self::once())
            ->method('read')
            ->willReturn('data');

        $store = new FrameworkSessionStore($handler, self::PREFIX);

        self::assertTrue($store->exists(Uuid::v4()));
    }

    public function testExistsReturnsFalseForEmptyRead(): void
    {
        $handler = self::createStub(\SessionHandlerInterface::class);
        $handler->method('read')->willReturn('');

        $store = new FrameworkSessionStore($handler, self::PREFIX);

        self::assertFalse($store->exists(Uuid::v4()));
    }

    public function testGcReturnsEmptyArray(): void
    {
        $handler = self::createMock(\SessionHandlerInterface::class);
        $handler->expects(self::never())->method('gc');

        $store = new FrameworkSessionStore($handler, self::PREFIX);

        self::assertSame([], $store->gc());
    }

    public function testCustomPrefix(): void
    {
        $id = Uuid::v4();
        $handler = self::createMock(\SessionHandlerInterface::class);
        $handler->expects(self::once())
            ->method('read')
            ->with('custom_'.$id)
            ->willReturn('data');

        $store = new FrameworkSessionStore($handler, 'custom_');

        self::assertSame('data', $store->read($id));
    }
}

abstract class SessionHandlerWithTimestamp implements \SessionHandlerInterface, \SessionUpdateTimestampHandlerInterface
{
}
