<?php

namespace Omnibridge\Eloquent\Omnibase\Finder;

use Base\Database\Exception\TableNotFound;
use Illuminate\Database\QueryException;

/**
 * A read of the finder on a table the database does not have: the core's TableNotFound in place of Illuminate's
 * QueryException (kept as the previous one), as omnibridge/doctrine gives it in place of Doctrine's
 * TableNotFoundException. Any other failure is left as it is.
 *
 * @internal
 */
final class DatabaseErrors
{
    /** SQLSTATE of a missing table: MySQL and MariaDB (42S02), PostgreSQL (42P01). SQLite says it in its message. */
    private const MISSING_TABLE = ['42S02', '42P01'];

    /**
     * @template T
     *
     * @param \Closure(): T $read
     *
     * @return T
     */
    public static function reading(\Closure $read): mixed
    {
        try {
            return $read();
        } catch (QueryException $failure) {
            throw self::isMissingTable($failure) ? TableNotFound::from($failure) : $failure;
        }
    }

    public static function isMissingTable(QueryException $failure): bool
    {
        $state = $failure->errorInfo[0] ?? $failure->getCode();

        return \in_array(\is_scalar($state) ? (string) $state : '', self::MISSING_TABLE, true) || str_contains($failure->getMessage(), 'no such table');
    }
}
