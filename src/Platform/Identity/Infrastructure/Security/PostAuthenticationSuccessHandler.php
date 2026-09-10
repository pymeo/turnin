<?php

declare(strict_types=1);

namespace App\Platform\Identity\Infrastructure\Security;

use App\Platform\Identity\Domain\PostAuthenticationDestinationResolver;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

final readonly class PostAuthenticationSuccessHandler implements AuthenticationSuccessHandlerInterface
{
    use TargetPathTrait;

    public function __construct(private PostAuthenticationDestinationResolver $destination)
    {
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token): RedirectResponse
    {
        return $this->redirectFor($request, $token, 'main');
    }

    public function redirectFor(Request $request, TokenInterface $token, string $firewallName): RedirectResponse
    {
        $user = $token->getUser();
        if (!$user instanceof SecurityUser) {
            return new RedirectResponse('/login');
        }

        $targetPath = null;
        if ($request->hasSession()) {
            $targetPath = $this->getTargetPath($request->getSession(), $firewallName);
            $this->removeTargetPath($request->getSession(), $firewallName);
        }

        return new RedirectResponse($this->destination->resolve($user->hasWorkerProfile(), $user->hasSupervisorProfile(), $this->sameOriginPath($request, $targetPath)));
    }

    private function sameOriginPath(Request $request, ?string $target): ?string
    {
        if (null === $target || '' === $target) {
            return null;
        }
        if (str_starts_with($target, '/') && !str_starts_with($target, '//')) {
            return $target;
        }

        $parts = parse_url($target);
        if (false === $parts || !isset($parts['host']) || !hash_equals(mb_strtolower($request->getHost()), mb_strtolower((string) $parts['host']))) {
            return null;
        }
        if (isset($parts['scheme']) && !hash_equals($request->getScheme(), mb_strtolower((string) $parts['scheme']))) {
            return null;
        }

        $path = (string) ($parts['path'] ?? '/');

        return isset($parts['query']) ? $path.'?'.$parts['query'] : $path;
    }
}
