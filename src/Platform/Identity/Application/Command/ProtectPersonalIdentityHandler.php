<?php

declare(strict_types=1);

namespace App\Platform\Identity\Application\Command;

use App\Platform\Identity\Domain\IdentityEvidence;
use App\Platform\Identity\Domain\IdentityTransaction;
use App\Platform\Identity\Domain\PersonalDataCipher;
use App\Platform\Identity\Domain\PersonalIdentifierFingerprinter;
use App\Platform\Identity\Domain\PersonalProfiles;
use App\Platform\Identity\Domain\SpanishIdentityDocument;
use App\Platform\Identity\Domain\SpanishPhoneNumber;
use App\Platform\Identity\Domain\UsageIdentities;
use App\Platform\Identity\Domain\UsageIdentity;
use App\Platform\Identity\Domain\UsageIdentityIdGenerator;
use App\Platform\Identity\Domain\UserId;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;

final readonly class ProtectPersonalIdentityHandler
{
    public function __construct(private PersonalProfiles $profiles, private UsageIdentities $usageIdentities, private PersonalIdentifierFingerprinter $fingerprinter, private PersonalDataCipher $cipher, private UsageIdentityIdGenerator $ids, private IdentityTransaction $transaction, private ClockInterface $clock)
    {
    }

    public function __invoke(ProtectPersonalIdentity $command): void
    {
        $this->transaction->run(function () use ($command): void {
            $userId = new UserId($command->userId);
            $profile = $this->profiles->byUserId($userId);
            if (null === $profile) {
                throw new InvalidArgumentException('Completa primero tu nombre.');
            }
            $document = new SpanishIdentityDocument($command->identityDocument);
            $phone = new SpanishPhoneNumber($command->phone);
            $documentFingerprint = $this->fingerprinter->fingerprint($document->normalized);
            $phoneFingerprint = $this->fingerprinter->fingerprint($phone->e164);
            $existing = $this->usageIdentities->byUserId($userId);
            if (null === $existing) {
                $this->usageIdentities->save(new UsageIdentity($this->ids->next(), $userId, $documentFingerprint, $phoneFingerprint, IdentityEvidence::PROVIDED, $this->clock->now()));
            } elseif (!hash_equals($existing->identityDocumentFingerprint, $documentFingerprint) || !hash_equals($existing->phoneFingerprint, $phoneFingerprint)) {
                throw new InvalidArgumentException('Para cambiar tus datos identificativos contacta con soporte.');
            }
            $profile->protectPhone($this->cipher->encrypt($phone->e164), $this->clock->now());
            $this->profiles->save($profile);
        });
    }
}
