<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\McpBundle\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

final class SymfonySecurityMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly string $rolesClaim = 'roles',
        private readonly string $firewall = 'mcp',
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $claims = $request->getAttribute('oauth.claims');
        if (!\is_array($claims)) {
            return $handler->handle($request);
        }

        $subject = $claims['sub'] ?? null;
        $email = $claims['email'] ?? null;

        if (!\is_string($subject) || '' === $subject || !\is_string($email) || '' === $email) {
            return $handler->handle($request);
        }

        $roles = ['ROLE_MCP_USER'];
        $claimRoles = $claims[$this->rolesClaim] ?? [];
        if (\is_array($claimRoles)) {
            $roles = array_merge($roles, array_filter($claimRoles, 'is_string'));
        }

        $user = new InMemoryUser($email, null, $roles);
        $token = new PostAuthenticationToken($user, $this->firewall, $user->getRoles());
        $this->tokenStorage->setToken($token);

        return $handler->handle($request);
    }
}
