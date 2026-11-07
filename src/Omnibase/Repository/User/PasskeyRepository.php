<?php

namespace Omnibridge\Eloquent\Omnibase\Repository\User;

use Base\Contract\PasskeyRecords;
use Base\Entity\User\Passkey;
use Base\Repository\User\Passkeys;
use Omnibridge\Eloquent\Omnibase\EloquentEntities;
use Symfony\Component\Uid\Uuid;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\TrustPathDenormalizer;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\TrustPath\TrustPath;

/**
 * The passkeys on the store (plan "omnibase agnostic", steps F7b and F7c): the core's Passkeys, and PasskeyRecords -
 * webauthn-symfony-bundle's credential repository where it is installed (CredentialRecordRepositoryInterface and
 * CanSaveCredentialRecord), an interface that asks nothing elsewhere - as omnibridge/doctrine's PasskeyRepository
 * answers them: a credential found by its identifier, a user's credentials, a credential saved through the unit of
 * work (the passkey's counters updated, or a new passkey of the user its handle names).
 */
class PasskeyRepository extends EloquentEntities implements Passkeys, PasskeyRecords
{
    public function findOneByCredential(string $publicKeyCredentialId): ?Passkey
    {
        // Bound with the column's type (passkey_base64): the bytes as the base64 text kept.
        return $this->findOneBy(['publicKeyCredentialId' => $publicKeyCredentialId]);
    }

    /** @return list<CredentialRecord> */
    public function findAllForUserEntity(PublicKeyCredentialUserEntity $publicKeyCredentialUserEntity): array
    {
        return array_map(self::credentialRecord(...), $this->findBy(['userHandle' => $publicKeyCredentialUserEntity->id]));
    }

    public function findOneByCredentialId(string $publicKeyCredentialId): ?CredentialRecord
    {
        $passkey = $this->findOneByCredential($publicKeyCredentialId);

        return $passkey ? self::credentialRecord($passkey) : null;
    }

    public function saveCredentialRecord(CredentialRecord $credentialRecord): void
    {
        $unitOfWork = $this->unitOfWork();
        if ($passkey = $this->findOneByCredential($credentialRecord->publicKeyCredentialId)) {
            self::fill($passkey, $credentialRecord);
            $unitOfWork->flush();

            return;
        }

        $userClass = class_exists(\App\Entity\User::class) ? \App\Entity\User::class : \Base\Entity\User::class;
        $user = $unitOfWork->store()->find($userClass, (int) $credentialRecord->userHandle);
        if (!$user) {
            throw new \RuntimeException('Cannot save passkey: unknown user handle.');
        }

        $unitOfWork->persist(Passkey::createFromCredentialRecord($credentialRecord, $user));
        $unitOfWork->flush();
    }

    public static function credentialRecord(Passkey $passkey): CredentialRecord
    {
        $trustPath = (new TrustPathDenormalizer())->denormalize($passkey->trustPath, TrustPath::class);

        return CredentialRecord::create(
            $passkey->publicKeyCredentialId,
            $passkey->type,
            $passkey->transports,
            $passkey->attestationType,
            $trustPath,
            Uuid::fromString($passkey->aaguid),
            $passkey->credentialPublicKey,
            $passkey->userHandle,
            $passkey->counter,
            $passkey->otherUI,
            $passkey->backupEligible,
            $passkey->backupStatus,
            $passkey->uvInitialized,
        );
    }

    public static function fill(Passkey $passkey, CredentialRecord $record): Passkey
    {
        $passkey->publicKeyCredentialId = $record->publicKeyCredentialId;
        $passkey->type = $record->type;
        $passkey->transports = $record->transports;
        $passkey->attestationType = $record->attestationType;
        $passkey->trustPath = (new TrustPathDenormalizer())->normalize($record->trustPath);
        $passkey->aaguid = $record->aaguid->toRfc4122();
        $passkey->credentialPublicKey = $record->credentialPublicKey;
        $passkey->userHandle = $record->userHandle;
        $passkey->counter = $record->counter;
        $passkey->otherUI = $record->otherUI;
        $passkey->backupEligible = $record->backupEligible;
        $passkey->backupStatus = $record->backupStatus;
        $passkey->uvInitialized = $record->uvInitialized;

        return $passkey;
    }
}
