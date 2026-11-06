<?php

namespace Omnibridge\Eloquent\Omnibase\Repository\User;

use Base\Entity\User\Passkey;
use Base\Repository\User\Passkeys;
use Omnibridge\Eloquent\Omnibase\EloquentEntities;

/**
 * omnibridge/doctrine's User\PasskeyRepository on Eloquent (plan "omnibase agnostic", step F7b): the same finders, compiled by
 * EloquentFinder. The WebAuthn credential records (PasskeyRecords) are omnibridge/doctrine's: they write (step F7c).
 *
 * @extends EloquentEntities<Passkey>
 */
class PasskeyRepository extends EloquentEntities implements Passkeys
{
    public function findOneByCredential(string $publicKeyCredentialId): ?Passkey
    {
        // Bound with the column's type (passkey_base64): the bytes as the base64 text kept.
        return $this->findOneBy(['publicKeyCredentialId' => $publicKeyCredentialId]);
    }
}
