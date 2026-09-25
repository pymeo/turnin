<?php

declare(strict_types=1);

namespace App\Workforce\Infrastructure\Security;

use App\Workforce\Domain\Supervision\SupervisorLinkTokens;

/**
 * 256-bit tokens, URL-safe, never stored in plain. See the port for why the
 * verification token is derived rather than random.
 */
final readonly class HmacSupervisorLinkTokens implements SupervisorLinkTokens
{
    public function __construct(private string $supervisorLinkSecret)
    {
    }

    public function newInvitationToken(): array
    {
        $token = $this->encode(random_bytes(32));

        return ['token' => $token, 'hash' => $this->hash($token)];
    }

    public function verificationToken(string $assignmentId): string
    {
        return $this->encode(hash_hmac('sha256', 'supervisor-verification:'.$assignmentId, $this->supervisorLinkSecret, true));
    }

    public function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    private function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
