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
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * @implements RequestHandlerInterface<ListToolsResult>
 */
final class FilteredListToolsHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly RegistryInterface $registry,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly TokenStorageInterface $tokenStorage,
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

        if (!$this->hasAuthenticatedUser()) {
            $tools = array_values(array_filter(
                $allTools->references,
                fn (mixed $item) => $item instanceof Tool,
            ));

            return new Response($request->getId(), new ListToolsResult($tools));
        }

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

        [$class, $method] = $handler;

        try {
            $reflection = new \ReflectionMethod($class, $method);
        } catch (\ReflectionException) {
            return true;
        }

        $attributes = $reflection->getAttributes(IsGranted::class);
        foreach ($attributes as $attribute) {
            $isGranted = $attribute->newInstance();
            if (!$this->authorizationChecker->isGranted($isGranted->attribute, $isGranted->subject)) {
                return false;
            }
        }

        return true;
    }

    private function hasAuthenticatedUser(): bool
    {
        $token = $this->tokenStorage->getToken();

        return null !== $token && !$token instanceof NullToken;
    }
}
