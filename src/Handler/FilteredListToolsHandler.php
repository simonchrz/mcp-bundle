<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\McpBundle\Handler;

use Mcp\Capability\RegistryInterface;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\ListToolsRequest;
use Mcp\Schema\Result\ListToolsResult;
use Mcp\Schema\Tool;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;
use Symfony\AI\McpBundle\Security\IsGrantedCheckerInterface;

/**
 * @implements RequestHandlerInterface<ListToolsResult>
 */
final class FilteredListToolsHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly RegistryInterface $registry,
        private readonly IsGrantedCheckerInterface $isGrantedChecker,
    ) {
    }

    public function supports(Request $request): bool
    {
        return $request instanceof ListToolsRequest;
    }

    public function handle(Request $request, SessionInterface $session): Response
    {
        \assert($request instanceof ListToolsRequest);

        $allTools = $this->registry->getTools();

        $filtered = [];
        foreach ($allTools->references as $item) {
            if ($item instanceof Tool && $this->isAccessible($item)) {
                $filtered[] = $item;
            }
        }

        return new Response(
            $request->getId(),
            new ListToolsResult($filtered),
        );
    }

    private function isAccessible(Tool $tool): bool
    {
        $reference = $this->registry->getTool($tool->name);
        $handler = $reference->handler;

        if (!\is_array($handler)) {
            return true;
        }

        return $this->isGrantedChecker->isGranted($handler);
    }
}
