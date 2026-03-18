<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\McpBundle\Session;

use Mcp\Server\Session\SessionStoreInterface;
use Symfony\Component\Uid\Uuid;

final class FrameworkSessionStore implements SessionStoreInterface
{
    public function __construct(
        private readonly \SessionHandlerInterface $handler,
        private readonly string $prefix = 'mcp-',
    ) {
    }

    public function exists(Uuid $id): bool
    {
        if ($this->handler instanceof \SessionUpdateTimestampHandlerInterface) {
            return $this->handler->validateId($this->getKey($id));
        }

        return '' !== $this->handler->read($this->getKey($id));
    }

    public function read(Uuid $id): string|false
    {
        $data = $this->handler->read($this->getKey($id));

        return '' === $data ? false : $data;
    }

    public function write(Uuid $id, string $data): bool
    {
        return $this->handler->write($this->getKey($id), $data);
    }

    public function destroy(Uuid $id): bool
    {
        return $this->handler->destroy($this->getKey($id));
    }

    public function gc(): array
    {
        // Framework session handlers manage their own expiry (Redis TTL, gc_maxlifetime, etc.).
        // SessionHandlerInterface::gc() returns int|false (count), not the session IDs
        // required by SessionStoreInterface::gc(), so delegation is not possible.
        return [];
    }

    private function getKey(Uuid $id): string
    {
        return $this->prefix.$id;
    }
}
