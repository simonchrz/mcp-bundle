<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\McpBundle\Security;

use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Capability\Registry\ToolReference;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class SecurityReferenceHandler implements ReferenceHandlerInterface
{
    public function __construct(
        private readonly ReferenceHandlerInterface $inner,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    public function handle(ElementReference $reference, array $arguments): mixed
    {
        if ($reference instanceof ToolReference) {
            $this->checkAccess($reference);
        }

        return $this->inner->handle($reference, $arguments);
    }

    private function checkAccess(ToolReference $reference): void
    {
        $handler = $reference->handler;

        if (!\is_array($handler) || 2 !== \count($handler)) {
            return;
        }

        [$class, $method] = $handler;

        try {
            $reflection = new \ReflectionMethod($class, $method);
        } catch (\ReflectionException) {
            return;
        }

        $attributes = $reflection->getAttributes(IsGranted::class);
        foreach ($attributes as $attribute) {
            $isGranted = $attribute->newInstance();
            if (!$this->authorizationChecker->isGranted($isGranted->attribute, $isGranted->subject)) {
                throw new AccessDeniedException(\sprintf('Access denied to tool "%s".', $reference->tool->name));
            }
        }
    }
}
