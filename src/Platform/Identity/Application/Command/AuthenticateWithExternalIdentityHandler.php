<?php

declare(strict_types=1);

namespace App\Platform\Identity\Application\Command;

use App\Platform\Identity\Domain\Email;
use App\Platform\Identity\Domain\ExternalAuthenticationRejected;
use App\Platform\Identity\Domain\ExternalIdentities;
use App\Platform\Identity\Domain\ExternalIdentity;
use App\Platform\Identity\Domain\ExternalIdentityIdGenerator;
use App\Platform\Identity\Domain\IdentityTransaction;
use App\Platform\Identity\Domain\User;
use App\Platform\Identity\Domain\UserIdGenerator;
use App\Platform\Identity\Domain\Users;
use Psr\Clock\ClockInterface;

final readonly class AuthenticateWithExternalIdentityHandler
{
    public function __construct(private Users $users, private ExternalIdentities $externalIdentities, private UserIdGenerator $userIds, private ExternalIdentityIdGenerator $externalIdentityIds, private IdentityTransaction $transaction, private ClockInterface $clock)
    {
    }

    public function __invoke(AuthenticateWithExternalIdentity $command): User
    {
        $subject = trim($command->subject);
        if ('' === $subject) {
            throw new ExternalAuthenticationRejected('El proveedor no ha devuelto una identidad válida.');
        }

        return $this->transaction->run(function () use ($command, $subject): User {
            $linkedIdentity = $this->externalIdentities->byProviderSubject($command->provider, $subject);
            if (null !== $linkedIdentity) {
                $linkedUser = $this->users->byId($linkedIdentity->userId);
                if (null === $linkedUser) {
                    throw new ExternalAuthenticationRejected('La identidad externa está vinculada a una cuenta inexistente.');
                }

                return $linkedUser;
            }

            if (!$command->emailVerified) {
                throw new ExternalAuthenticationRejected('Google no ha confirmado el correo electrónico.');
            }

            $email = new Email($command->email);
            $user = $this->users->byEmail($email);
            if (null === $user) {
                $user = new User($this->userIds->next(), $email, null, false, false, $this->clock->now());
                $this->users->save($user);
            }

            $identityForUser = $this->externalIdentities->byUserAndProvider($user->id, $command->provider);
            if (null !== $identityForUser && $identityForUser->providerSubject !== $subject) {
                throw new ExternalAuthenticationRejected('La cuenta ya tiene otra identidad de este proveedor.');
            }

            if (null === $identityForUser) {
                $this->externalIdentities->save(new ExternalIdentity($this->externalIdentityIds->next(), $user->id, $command->provider, $subject, $email, $this->clock->now()));
            }

            return $user;
        });
    }
}
