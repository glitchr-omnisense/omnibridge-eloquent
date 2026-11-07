<?php

namespace Omnibridge\Eloquent\Tests\Support;

/**
 * What a flow wrote in a MySQL database, every table of it (plan "omnibase agnostic", step F7c, third part): a
 * fingerprint of each row before the flow (its primary key, an MD5 of all its columns), the same after, and the rows
 * inserted, updated (their columns after) and deleted (their keys) read from the difference - so two databases copied
 * from one dump and run through the same flow, one on each backend, are compared column by column, nothing chosen in
 * advance. The identifiers stay as written (both copies start at the same auto-increments); the times written during
 * the flow are given relative to its start, and the values made at random (a password's hash, a token, a uuid, a
 * vault's cipher) are given as what they are, by their column.
 */
final class RowChanges
{
    /** @var array<string, array{key: list<string>, columns: list<string>}> */
    private array $tables = [];

    /** @param \PDO $pdo the database the flow writes */
    public function __construct(private readonly \PDO $pdo)
    {
        $schema = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        $columns = $pdo->prepare('SELECT table_name, column_name FROM information_schema.columns WHERE table_schema = ? ORDER BY table_name, ordinal_position');
        $columns->execute([$schema]);
        foreach ($columns->fetchAll(\PDO::FETCH_NUM) as [$table, $column]) {
            $this->tables[$table]['columns'][] = $column;
        }
        $keys = $pdo->prepare('SELECT table_name, column_name FROM information_schema.key_column_usage WHERE table_schema = ? AND constraint_name = \'PRIMARY\' ORDER BY table_name, ordinal_position');
        $keys->execute([$schema]);
        foreach ($keys->fetchAll(\PDO::FETCH_NUM) as [$table, $column]) {
            $this->tables[$table]['key'][] = $column;
        }
        $views = $pdo->prepare('SELECT table_name FROM information_schema.tables WHERE table_schema = ? AND table_type <> \'BASE TABLE\'');
        $views->execute([$schema]);
        foreach ($views->fetchAll(\PDO::FETCH_COLUMN) as $view) {
            unset($this->tables[$view]);
        }
        foreach ($this->tables as $table => $shape) {
            $this->tables[$table]['key'] = $shape['key'] ?? $shape['columns'];
        }
        ksort($this->tables);
    }

    /** @return array<string, array<string, string>> each table's rows: key => fingerprint */
    public function snapshot(): array
    {
        $snapshot = [];
        foreach ($this->tables as $table => $shape) {
            $hash = 'MD5(CONCAT_WS(0x1f, '.implode(', ', array_map(static fn (string $c): string => 'COALESCE(HEX(`'.$c.'`), \'-\')', $shape['columns'])).'))';
            $rows = $this->pdo->query('SELECT '.$this->key($shape['key']).' AS k, '.$hash.' AS h FROM `'.$table.'`')->fetchAll(\PDO::FETCH_KEY_PAIR);
            $snapshot[$table] = array_map('strval', $rows);
        }

        return $snapshot;
    }

    /**
     * The rows that differ between two snapshots, normalised.
     *
     * @return array<string, array{inserted?: list<array<string, mixed>>, updated?: list<array<string, mixed>>, deleted?: list<string>}>
     */
    public function changes(array $before, array $after, \DateTimeImmutable $start): array
    {
        $changes = [];
        foreach ($this->tables as $table => $shape) {
            $old = $before[$table] ?? [];
            $new = $after[$table] ?? [];
            $inserted = array_keys(array_diff_key($new, $old));
            $deleted = array_keys(array_diff_key($old, $new));
            $updated = [];
            foreach (array_intersect_key($new, $old) as $key => $hash) {
                if ($old[$key] !== $hash) {
                    $updated[] = $key;
                }
            }
            if ($inserted) {
                $changes[$table]['inserted'] = $this->rows($table, $inserted, $start, true);
            }
            if ($updated) {
                $changes[$table]['updated'] = $this->rows($table, $updated, $start, false);
            }
            if ($deleted) {
                sort($deleted);
                $changes[$table]['deleted'] = array_map('strval', $deleted);
            }
        }

        return $changes;
    }

    /** @param list<string> $key */
    private function key(array $key): string
    {
        return 1 === \count($key) ? '`'.$key[0].'`' : 'CONCAT_WS(\'/\', '.implode(', ', array_map(static fn (string $c): string => '`'.$c.'`', $key)).')';
    }

    /**
     * @param list<int|string> $keys
     *
     * @return list<array<string, mixed>>
     */
    private function rows(string $table, array $keys, \DateTimeImmutable $start, bool $inserted): array
    {
        $rows = [];
        foreach (array_chunk($keys, 500) as $chunk) {
            $statement = $this->pdo->prepare('SELECT * FROM `'.$table.'` WHERE '.$this->key($this->tables[$table]['key']).' IN ('.implode(', ', array_fill(0, \count($chunk), '?')).') ORDER BY '.$this->key($this->tables[$table]['key']));
            $statement->execute(array_map('strval', $chunk));
            foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $rows[] = self::normalise($table, $row, $start, $inserted);
            }
        }
        usort($rows, static fn (array $a, array $b): int => strnatcmp(implode('/', array_map('strval', array_values($a))), implode('/', array_map('strval', array_values($b)))));

        return $rows;
    }

    /**
     * A row as compared: the times of the flow relative to its start; in a row the flow inserted, what was made at
     * random given by its kind - a password's hash, a uuid (alone or in a text, as a link's anchor), a random hash, a
     * comment's slug (its random suffix), a vaulted setting's cipher (its clear value is read back by the flow). An
     * updated row keeps its values but its times: a uuid or a slug changed there is a difference.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public static function normalise(string $table, array $row, \DateTimeImmutable $start, bool $inserted = true): array
    {
        foreach ($row as $column => $value) {
            if (!\is_string($value)) {
                continue;
            }
            if (1 === preg_match('/^\$(2y|argon2i|argon2id)\$/', $value)) {
                $row[$column] = '<password hash>';
            } elseif (1 === preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $value)) {
                $row[$column] = self::moment($value, $start);
            } elseif (!$inserted) {
                $row[$column] = preg_replace_callback('/\d{4}-\d\d-\d\d[T ]\d\d:\d\d:\d\d(\.\d+)?([+-]\d\d:?\d\d|Z)?/', static fn (array $m): string => self::moment($m[0], $start), $value);
            } elseif ('hash' === $column) {
                $row[$column] = '<random '.\strlen($value).'>';
            } elseif ('userToken' === $table && 'value' === $column) {
                $row[$column] = '<random>';
            } elseif ('slug' === $column && 1 === preg_match('/^(comment(-reply)?)-[a-z0-9]{6}$/', $value, $m)) {
                $row[$column] = $m[1].'-<random 6>';
            } elseif ('layoutSettingIntl' === $table && 'value' === $column && 1 === preg_match('/^"[A-Za-z0-9+\/=]{40,}"$/', $value)) {
                $row[$column] = '<vault cipher>';
            } else {
                $value = preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', '<uuid>', $value);
                $row[$column] = preg_replace_callback('/\d{4}-\d\d-\d\d[T ]\d\d:\d\d:\d\d(\.\d+)?([+-]\d\d:?\d\d|Z)?/', static fn (array $m): string => self::moment($m[0], $start), $value);
            }
        }

        return $row;
    }

    /** A time written during the flow, in minutes from its start (rounded to five); an older one as it is. */
    private static function moment(string $value, \DateTimeImmutable $start): string
    {
        try {
            $moment = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return $value;
        }
        $seconds = $moment->getTimestamp() - $start->getTimestamp();
        if ($seconds < -300) {
            return $value;
        }

        return \sprintf('<start%+dm>', 5 * (int) round($seconds / 300));
    }
}
