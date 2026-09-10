<?php

declare(strict_types=1);

namespace App\Platform\Identity\Domain;

final class PostAuthenticationDestinationResolver
{
    public function resolve(bool $worker, bool $supervisor, ?string $targetPath = null): string
    {
        if (null !== $targetPath && $this->isSafeInternalPath($targetPath)) {
            return $targetPath;
        }

        if ($worker) {
            return '/app';
        }

        return $supervisor ? '/supervisor' : '/onboarding';
    }

    private function isSafeInternalPath(string $path): bool
    {
        if (!str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '\\') || preg_match('/[\x00-\x1F\x7F]/', $path)) {
            return false;
        }

        $parts = parse_url($path);

        return false !== $parts && !isset($parts['scheme'], $parts['host']);
    }
}
