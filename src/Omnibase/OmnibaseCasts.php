<?php

namespace Omnibridge\Eloquent\Omnibase;

use Base\Database\Schema\EntitySchema;
use Base\Database\Type\EnumType;
use Base\Database\Type\SetType;
use Omnibridge\Eloquent\Casts;

/**
 * omnibase's column types read as omnibridge/doctrine's DBAL types read them (plan "omnibase agnostic", step F7b):
 *
 * - datetime and datetimetz in UTC, utc_datetime and utc_datetime_immutable (Casts::utc());
 * - `array`: PHP's serialization (omnibridge/doctrine's ArrayType);
 * - the passkeys' columns: passkey_base64 (base64 of the bytes), passkey_aaguid (a string), passkey_trust_path (JSON);
 * - each enumeration and set of the core, the application and the folders given (EntitySchema::types()), through
 *   the enumeration's own toPHPValue()/toDatabaseValue(), as EnumTypeAdapter calls them;
 * - the application's own types: EntitySchema::columnTypes()'s aliases and the column_types option.
 */
final class OmnibaseCasts
{
    /** @param array<string, mixed> $options EntitySchema's options (project_dir, enumerations, types, column_types) */
    public static function create(array $options = []): Casts
    {
        $casts = Casts::utc(EntitySchema::columnTypes($options))
            ->with('array', static function (mixed $value): mixed {
                set_error_handler(static function (int $code, string $message): bool {
                    if (\E_DEPRECATED === $code || \E_USER_DEPRECATED === $code) {
                        return false;
                    }
                    throw new \UnexpectedValueException('array: '.$message);
                });
                try {
                    return unserialize((string) $value);
                } finally {
                    restore_error_handler();
                }
            }, static fn (mixed $value): string => serialize($value))
            ->with('passkey_base64', static fn (mixed $value): string => false !== ($bytes = base64_decode((string) $value, true)) ? $bytes : throw new \UnexpectedValueException('passkey_base64: no base64 value.'), static fn (mixed $value): string => base64_encode((string) $value))
            ->with('passkey_aaguid', static fn (mixed $value): string => (string) $value, static fn (mixed $value): string => (string) $value)
            ->with('passkey_trust_path', static fn (mixed $value): mixed => '' === $value ? null : json_decode((string) $value, true, 512, \JSON_THROW_ON_ERROR), static fn (mixed $value): string => json_encode($value, \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR));

        foreach (EntitySchema::types($options) as $name => $enumeration) {
            if (is_subclass_of($enumeration, EnumType::class)) {
                // A set reads NULL as no value ([]), as EnumTypeAdapter hands it to SetType::toPHPValue().
                $casts = $casts->with($name, static fn (mixed $value): mixed => $enumeration::toPHPValue($value), static fn (mixed $value): mixed => $enumeration::toDatabaseValue($value), is_subclass_of($enumeration, SetType::class));
            }
        }

        return $casts;
    }
}
