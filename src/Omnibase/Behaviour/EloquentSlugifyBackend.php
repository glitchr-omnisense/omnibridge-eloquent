<?php

namespace Omnibridge\Eloquent\Omnibase\Behaviour;

use Base\Database\Attribute\Backend\SlugifyBackend;
use Omnibridge\Eloquent\EloquentUnitOfWork;

/**
 * #[Slugify]'s part on omnibridge/eloquent (plan "omnibase agnostic", step F7c): the writes of the running flush - its
 * insertions, then its updates, in the order the unit of work scheduled them, as DoctrineSlugifyBackend lists
 * Doctrine's -, and whether the database still holds a unique index on the slug's column alone (read from the
 * database's indexes through Illuminate's schema builder, once per process and column).
 */
final class EloquentSlugifyBackend implements SlugifyBackend
{
    /** @var array<string, bool> table.column => whether a unique index holds that column alone */
    private static array $uniqueAlone = [];

    public function __construct(private readonly EloquentUnitOfWork $unitOfWork)
    {
    }

    public function pendingWrites(): array
    {
        return [...$this->unitOfWork->scheduledInsertions(), ...$this->unitOfWork->scheduledUpdates()];
    }

    public function uniqueAlone(string $class, string $property): bool
    {
        try {
            $map = $this->unitOfWork->store()->mapping()->of($class);
            $field = $map->entity->fields[$property] ?? null;
            if (null === $field) {
                return false;
            }
            $table = $map->tableOf($property);
            $column = $field->column;
            $key = $table.'.'.$column;
            if (\array_key_exists($key, self::$uniqueAlone)) {
                return self::$uniqueAlone[$key];
            }

            self::$uniqueAlone[$key] = false;
            foreach ($this->unitOfWork->store()->connection()->getSchemaBuilder()->getIndexes($table) as $index) {
                if (($index['unique'] ?? false) && !($index['primary'] ?? false) && array_map('strtolower', $index['columns'] ?? []) === [strtolower($column)]) {
                    self::$uniqueAlone[$key] = true;
                }
            }

            return self::$uniqueAlone[$key];
        } catch (\Throwable) {
            return false;
        }
    }
}
