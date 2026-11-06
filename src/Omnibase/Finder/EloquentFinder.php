<?php

namespace Omnibridge\Eloquent\Omnibase\Finder;

use Base\Database\Repository\Finder\Group;
use Base\Database\Repository\Finder\Key;
use Base\Database\Repository\Finder\Parser;
use Base\Database\Repository\Finder\Query;
use Base\Database\Repository\UnsupportedQueryException;
use Base\Service\Model\IntlDateTime;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Omnibridge\Eloquent\EntityMap;
use Omnibridge\Eloquent\EntityStore;
use Omnibridge\Schema\Relation;

/**
 * omnibase's finder language on Illuminate's query builder (plan "omnibase agnostic", step F7b): the core's
 * Finder\Parser reads a finder's name and arguments (read()), this class compiles the Query into the store's query
 * of the class and runs it (execute()) - what omnibridge/doctrine's ServiceEntityParser does on Doctrine, word for
 * word, so that one name gives the same rows on both persistences (FinderParityTest):
 *
 * - every word of a criterion: EqualTo (= or IN for a list, an empty list no condition), NotEqualTo, GreaterThan,
 *   GreaterEqualTo, LowerThan, LowerEqualTo; the dates YoungerThan, YoungerEqualTo, OlderThan, OlderEqualTo, Within
 *   (an age or an offset from now, to the minute), IsOver, IsNotOver; StartingWith, EndingWith and their Not (LIKE,
 *   the underscore escaped), Partial (LIKE as given), Insensitive (LOWER()), Null, Empty, True, False and their Not;
 *   ClosestTo and FarestTo (ABS(x - value) ordering the rows); InstanceOf, ClassOf and their Not (the
 *   discriminator); But; WithRoute; Model (a Query-by-Example); a JSON field compared as Doctrine's JSON_CONTAINS();
 * - a criterion on a to-one or to-many association, or a path through one ("translatable.state"), through a LEFT
 *   JOIN of it - the store's conditions (the trash and vault scopes) in the join's ON, as Doctrine's SQL filters, a
 *   subclass target's discriminator there too; a translation's (Translatable's `translations`) on the translation's
 *   own foreign key in the root table of its hierarchy, as omnibridge/doctrine's TranslatableWalker rewrites it;
 * - a key criterion (Finder\Key): the association's own foreign key, no join; a group (Finder\Group): its members
 *   in parentheses;
 * - the requests: find (One, All, Randomly, AtMost<N>, Last<N>, LastOne, Previous<N>, PreviousOne, Next<N>,
 *   NextOne), count and distinctCount (For<column>, grouped: the rows with their count), lengthOf<column> (LENGTH(),
 *   or a collection's size), sumOf, minOf, maxOf;
 * - order (a field, an association's field, a list of values - FIELD() -, id => "rand" - RAND() -, a selected
 *   value's name, an expression of Expressions), limit, offset, grouping, selectAs.
 *
 * What it cannot express is refused aloud with UnsupportedQueryException - MemberOf (omnibridge/doctrine compiles it
 * to `e MEMBER OF <class>`, which DQL refuses too), a Partial association, a list compared Partial, a path deeper than
 * one association, a criterion on a JSON field through a path, an expression it does not translate; what Doctrine
 * refuses (an unknown separator, an operator a list or a JSON field does not take, an Older/Younger date) is refused
 * with the same message. Cache (the cache... names) and Eagerly change no row: the store has no result cache, and
 * reads associations lazily.
 */
class EloquentFinder
{
    private const COUNT_ALL = Parser::COUNT_ALL;
    private const COUNT_DISTINCT = Parser::COUNT_DISTINCT;

    private ?Parser $parser = null;

    private readonly EntitySubject $subject;

    /** @var array<string, list<string>> the call's criteria's words, "field:index" => words */
    private array $options = [];

    private ?string $separator = null;

    private ?string $column = null;

    private int $joined = 0;

    /**
     * @param \Closure(mixed): (array<string, mixed>|null|false)|null $routeParameters a host's request => its route parameters (WithRoute), false for anything else
     */
    public function __construct(
        private readonly EntityStore $store,
        private readonly EntityMap $map,
        private readonly ?\Closure $routeParameters = null,
    ) {
        $this->subject = new EntitySubject($map);
    }

    public function subject(): EntitySubject
    {
        return $this->subject;
    }

    /**
     * Reads a finder's name and arguments into the finder language's neutral form (Finder\Parser), nothing run.
     *
     * @param list<mixed> $arguments
     */
    public function read(string $method, array $arguments): Query
    {
        return ($this->parser ??= new Parser($this->subject, $this->routeParameters ?? self::routeParameters(...)))->parse($method, $arguments);
    }

    /**
     * Reads a finder's name and arguments, then compiles and runs it.
     *
     * @param list<mixed> $arguments
     */
    public function parse(string $method, array $arguments): mixed
    {
        return $this->execute($this->read($method, $arguments));
    }

    /**
     * Compiles a finder call read by Finder\Parser and runs it: the internal request it names (__findBy, __findOneBy,
     * __countBy...) with its arguments, the criteria's words, the separator and the column set for the time of the call.
     */
    public function execute(Query $query): mixed
    {
        $this->options = $query->options;
        $this->separator = $query->separator;
        if (null !== $query->column) {
            $this->column = $query->column;
        }

        try {
            return $this->{$query->method}(...$query->arguments);
        } finally {
            $this->options = [];
            $this->separator = null;
        }
    }

    /**
     * A host's request for WithRoute: Symfony's Request or kernel event (their attributes' _route_params), Laravel's
     * Request (its route's parameters); false for anything else, which the Parser refuses.
     *
     * @return array<string, mixed>|null|false
     */
    public static function routeParameters(mixed $argument): array|null|false
    {
        if (\is_object($argument) && method_exists($argument, 'getRequest') && is_a($argument, 'Symfony\Component\HttpKernel\Event\KernelEvent')) {
            $argument = $argument->getRequest();
        }
        if (is_a($argument, 'Symfony\Component\HttpFoundation\Request')) {
            return $argument->attributes->get('_route_params');
        }
        if (is_a($argument, 'Illuminate\Http\Request')) {
            return $argument->route()?->parameters();
        }

        return false;
    }

    // The internal requests, as ServiceEntityParser declares them (their arguments typed alike: a call that Doctrine's
    // refuses with a TypeError is refused here the same way).

    protected function __findBy(array $criteria = [], ?array $orderBy = null, $limit = null, $offset = null, ?array $groupBy = null, ?array $selectAs = null): ?FinderQuery
    {
        return $this->getQuery($criteria, $orderBy, $limit, $offset, $groupBy, $selectAs);
    }

    protected function __findRandomlyBy(array $criteria = [], ?array $orderBy = null, ?int $limit = null, ?int $offset = null, ?array $groupBy = null, ?array $selectAs = null): ?FinderQuery
    {
        return $this->__findBy($criteria, array_merge(['id' => 'rand'], $orderBy ?? []), $limit, $offset, $groupBy, $selectAs);
    }

    protected function __findAll(?array $orderBy = null, ?array $groupBy = null, ?array $selectAs = null): ?FinderQuery
    {
        return $this->__findBy([], $orderBy, null, null, $groupBy, $selectAs);
    }

    protected function __findOneBy(array $criteria = [], ?array $orderBy = null, ?int $offset = null, ?array $groupBy = null, ?array $selectAs = null)
    {
        return $this->__findBy($criteria, $orderBy, 1, $offset, $groupBy, $selectAs)->getOneOrNullResult();
    }

    protected function __findLastOneBy(array $criteria = [], ?array $orderBy = null, ?array $offset = null, ?array $groupBy = null, ?array $selectAs = null)
    {
        foreach ($orderBy ?? [] as $field => $order) {
            $orderBy[$field] = ('DESC' == $order) ? 'ASC' : 'DESC';
        }

        return $this->__findOneBy($criteria, array_merge($orderBy ?? [], ['id' => 'DESC']), $offset, $groupBy, $selectAs) ?? null;
    }

    protected function __findLastBy(array $criteria = [], ?array $orderBy = null, ?array $offset = null, ?array $groupBy = null, ?array $selectAs = null): ?FinderQuery
    {
        foreach ($orderBy ?? [] as $field => $order) {
            $orderBy[$field] = ('DESC' == $order) ? 'ASC' : 'DESC';
        }
        $limit = array_pop_key('special:last', $criteria) ?? 1;

        return $this->__findBy($criteria, array_merge($orderBy ?? [], ['id' => 'DESC']), $limit, $offset, $groupBy, $selectAs) ?? null;
    }

    protected function __findAtMostBy(array $criteria = [], ?array $orderBy = null, ?int $offset = null, ?array $groupBy = null, ?array $selectAs = null): ?FinderQuery
    {
        $limit = array_pop_key('special:atMost', $criteria);

        return $this->__findBy($criteria, $orderBy, $limit, $offset, $groupBy, $selectAs);
    }

    protected function __findPreviousBy(array $criteria = [], ?array $orderBy = null, ?array $offset = null, ?array $groupBy = null, ?array $selectAs = null): ?FinderQuery
    {
        foreach ($orderBy ?? [] as $field => $order) {
            $orderBy[$field] = ('DESC' == $order) ? 'ASC' : 'DESC';
        }
        $limit = array_pop_key('special:prev', $criteria) ?? 1;
        foreach (array_pop_key('special:prevKeys', $criteria) ?? [] as $field) {
            $orderBy[$field] = 'DESC';
        }

        return $this->__findBy($criteria, array_merge($orderBy ?? [], ['id' => 'DESC']), $limit, $offset, $groupBy, $selectAs);
    }

    protected function __findPreviousOneBy(array $criteria = [], ?array $orderBy = null, ?array $offset = null, ?array $groupBy = null, ?array $selectAs = null)
    {
        return $this->__findPreviousBy($criteria, $orderBy, $offset, $groupBy, $selectAs)->getOneOrNullResult();
    }

    protected function __findNextBy(array $criteria = [], ?array $orderBy = null, ?array $offset = null, ?array $groupBy = null, ?array $selectAs = null): ?FinderQuery
    {
        $limit = array_pop_key('special:next', $criteria) ?? 1;
        foreach (array_pop_key('special:nextKeys', $criteria) ?? [] as $field) {
            $orderBy[$field] = 'ASC';
        }

        return $this->__findBy($criteria, array_merge($orderBy ?? [], ['id' => 'ASC']), $limit, $offset, $groupBy, $selectAs);
    }

    protected function __findNextOneBy(array $criteria = [], ?array $orderBy = null, ?array $offset = null, ?array $groupBy = null, ?array $selectAs = null)
    {
        return $this->__findNextBy($criteria, $orderBy, $offset, $groupBy, $selectAs)->getOneOrNullResult();
    }

    protected function __lengthOfBy(array $criteria = [], ?array $orderBy = null, ?array $groupBy = null, $limit = null, $offset = null, ?array $selectAs = null)
    {
        return $this->getQueryWithLength($criteria, $orderBy, $limit, $offset, $groupBy, $selectAs)->getResult();
    }

    /** @return int|array an integer; rows of counts when grouped */
    protected function __distinctCountBy(array $criteria = [], ?array $groupBy = null, ?array $selectAs = null): int|array
    {
        return $this->__countBy($criteria, self::COUNT_DISTINCT, null, $groupBy, $selectAs);
    }

    /** The rows matching the criteria counted: an integer; grouped (a groupBy or a selectAs), the rows with their "count". */
    protected function __countBy(array $criteria = [], ?string $mode = null, ?array $orderBy = null, ?array $groupBy = null, ?array $selectAs = null)
    {
        $mode = $mode ?: self::COUNT_ALL;
        $query = $this->getQueryWithCount($criteria, $mode, $orderBy, $groupBy, $selectAs);
        if ($query instanceof FinderQuery) {
            return $query->getResult();
        }

        return (int) $query;
    }

    /** SUM(), MIN() or MAX() of the column over the rows the criteria select: the database's value, null without a row. */
    protected function __sumOfBy(array $criteria = []): mixed
    {
        return $this->aggregate('SUM', $criteria);
    }

    protected function __minOfBy(array $criteria = []): mixed
    {
        return $this->aggregate('MIN', $criteria);
    }

    protected function __maxOfBy(array $criteria = []): mixed
    {
        return $this->aggregate('MAX', $criteria);
    }

    private function aggregate(string $function, array $criteria): mixed
    {
        $column = $this->subject->alias($this->column);
        if (!$this->subject->isField((string) $column)) {
            throw new \Exception('"'.$function.'" needs a field of "'.$this->map->className().'", not "'.$column.'"');
        }
        $compilation = $this->compile($criteria);
        $compilation->resetOrders();
        $compilation->finish();

        return $this->scalar($compilation->query, $function.'('.$this->wrap($this->map->column((string) $column)).')');
    }

    // The queries.

    private function getQuery(array $criteria = [], ?array $orderBy = null, $limit = null, $offset = null, ?array $groupBy = null, ?array $selectAs = null): FinderQuery
    {
        return $this->query($this->compile($criteria, $orderBy ?? [], $limit, $offset, $groupBy ?? [], $selectAs ?? []));
    }

    /** ServiceEntityParser::getQueryWithCount(): the count alone (a number), or the grouped rows with theirs. */
    private function getQueryWithCount(array $criteria = [], ?string $mode = self::COUNT_ALL, ?array $orderBy = null, ?array $groupBy = null, ?array $selectAs = null): FinderQuery|int|string|null
    {
        if (self::COUNT_ALL == $mode) {
            $mode = '';
        }
        if ($mode && self::COUNT_DISTINCT != $mode) {
            throw new \Exception('Unexpected "mode" provided: "'.$mode.'"');
        }

        $column = $this->subject->alias($this->column);
        if (!$column) {
            $column = 'id';
        }
        $grouped = !empty($groupBy) || !empty($selectAs);
        $compilation = $this->compile($criteria, $grouped ? ($orderBy ?? []) : [], null, null, $groupBy ?? $selectAs ?? [], $selectAs ?? []);

        if ($this->subject->isAssociation($column)) {
            $counted = $this->joinedIdentifier($compilation, $column);
        } elseif ($this->subject->isField($column)) {
            $counted = $this->wrap($this->map->column($column));
        } else {
            $counted = $this->expressions($compilation)->sql($column);
        }
        $count = 'COUNT('.trim($mode.' '.$counted).')';

        if (!$grouped) {
            // One number: COUNT() alone, neither the entity nor a GROUP BY.
            $compilation->resetOrders();
            $compilation->finish();

            return $this->scalar($compilation->query, $count);
        }

        $compilation->addScalar('count', $count);
        $compilation->addGroup($counted);

        return $this->query($compilation);
    }

    /** ServiceEntityParser::getQueryWithLength(): each row with the length of a field, or a collection's size. */
    private function getQueryWithLength(array $criteria = [], ?array $orderBy = null, $limit = null, $offset = null, ?array $groupBy = null, ?array $selectAs = null): FinderQuery
    {
        $column = (string) $this->subject->alias($this->column);
        $compilation = $this->compile($criteria, $orderBy ?? [], $limit, $offset, $groupBy ?? $selectAs ?? [], $selectAs ?? []);

        $relation = $this->subject->relation($column);
        if (null !== $relation && !$relation->isToOne()) {
            $compilation->addScalar('length', $this->size($relation));
        } else {
            $measured = $this->subject->isField($column) ? $this->wrap($this->map->column($column)) : $this->expressions($compilation)->sql($column);
            $compilation->addScalar('length', 'LENGTH('.$measured.')');
        }

        return $this->query($compilation);
    }

    /**
     * ServiceEntityParser::getQueryBuilder(): the class's query (the store's: its tables, its columns, its conditions),
     * the criteria, the selected values, the order, the grouping, the limit and the offset.
     */
    private function compile(array $criteria = [], array $orderBy = [], $limit = null, $offset = null, array $groupBy = [], array $selectAs = []): Compilation
    {
        $compilation = new Compilation($this->store->query($this->map));
        $this->limit($compilation->query, $limit, $offset);
        $this->joined = 0;

        // The selected values first: a criterion may name one (ClosestTo on ImageCrops' "ratio0"), as DQL reads a
        // result variable wherever it is; they come first in a mixed row, as Doctrine selects them first.
        if ($groupBy) {
            $compilation->entityKey = 'entity';
        }
        $this->selectAs($compilation, $selectAs);

        $orders = [];
        foreach ($orderBy as $name => $value) {
            $orders[] = [$name, $value];
        }

        foreach ($criteria as $field => $fieldValue) {
            // A group (Group::by(), step E2): its members in parentheses, joined by its own separator.
            if ($fieldValue instanceof Group) {
                $this->where($compilation, $this->groupExpression($compilation, $fieldValue));
                continue;
            }

            $field = explode(Parser::SEPARATOR, (string) $field);
            $fieldName = $field[0];
            if (\is_object($fieldValue) && (is_a($fieldValue, 'Doctrine\ORM\PersistentCollection') || $fieldValue instanceof \Omnibridge\Eloquent\LazyCollection)) {
                throw new \Exception('You passed a PersistentCollection for field "'.$fieldName.'"');
            }

            if (lcfirst(Parser::OPTION_MODEL) == $fieldName) {
                // A Query-by-Example: the select's set fields AND-combined, an association's directly ANDed.
                $parts = [];
                foreach ($fieldValue ?? [] as $entryID => $entryValue) {
                    $newField = $field;
                    array_unshift($newField, (string) $entryID);
                    $part = $this->criterion($compilation, $newField, $entryValue);
                    if (null === $part || '' === $part) {
                        continue;
                    }
                    if ($part instanceof Order) {
                        $orders[] = [$part, $part->direction];
                        continue;
                    }
                    if ($this->subject->isAssociation((string) $entryID)) {
                        $compilation->andWhere($part);
                    } else {
                        $parts[] = $part;
                    }
                }
                $this->where($compilation, [] === $parts ? null : Conditions::all($parts));
                continue;
            }

            $expression = $this->criterion($compilation, $field, $fieldValue);
            if ($expression instanceof Order) {
                // ClosestTo, FarestTo: an order, not a condition.
                $orders[] = [$expression, $expression->direction];
                continue;
            }
            $this->where($compilation, $expression);
        }

        $this->orderBy($compilation, $orders);
        $this->groupBy($compilation, $groupBy);

        return $compilation;
    }

    /** The query run: its result's shapes (FinderQuery). */
    private function query(Compilation $compilation): FinderQuery
    {
        $columns = $compilation->finish();
        $entityKey = $compilation->entityKey ?? ([] === $columns ? null : 0);

        return new FinderQuery($this->store, $this->map, $compilation->query, $entityKey, $columns);
    }

    /** One value: the query's columns replaced by it, its order dropped (Doctrine's resetDQLPart('orderBy')). */
    private function scalar(Builder $query, string $sql): mixed
    {
        $query->select($query->raw($sql.' as '.$query->getGrammar()->wrap('aggregate')));
        $record = $query->first();

        return null === $record ? null : ((array) $record)['aggregate'] ?? null;
    }

    private function limit(Builder $query, ?int $limit, ?int $offset): void
    {
        if (null !== $limit) {
            $query->limit($limit);
        }
        if ($offset) {
            // MySQL reads no OFFSET without a LIMIT: Doctrine's platform writes the largest one there.
            if (null === $limit) {
                $query->limit(\PHP_INT_MAX);
            }
            $query->offset($offset);
        }
    }

    /** ServiceEntityParser::where(): a criterion's condition added with the call's separator (none adds none). */
    private function where(Compilation $compilation, mixed $condition): void
    {
        if (null === $condition || '' === $condition) {
            return;
        }

        switch ($this->separator) {
            case Parser::SEPARATOR_OR:
                $compilation->orWhere($condition);
                break;

            case Parser::SEPARATOR_AND:
                $compilation->andWhere($condition);
                break;

            default:
                throw new \Exception('Unknown separator "'.$this->separator.'" provided');
        }
    }

    /**
     * A group's members, each compiled as a criterion of the call is, with the group's own words; joined by the
     * group's separator. Null when no member adds a condition.
     */
    private function groupExpression(Compilation $compilation, Group $value): ?\Closure
    {
        $query = $value->query ?? throw new \Exception('The finder group "'.$value->name.'" was not read by the parser');

        $members = [];
        foreach ($query->arguments[0] ?? [] as $key => $memberValue) {
            if ($memberValue instanceof Group) {
                $members[] = $this->groupExpression($compilation, $memberValue);
                continue;
            }
            [$name, $index] = explode(Parser::SEPARATOR, (string) $key, 2) + [1 => '0'];
            $members[] = $this->criterion($compilation, [$name, 'group', $index], $memberValue, $query->options[$key] ?? []);
        }
        $members = array_values(array_filter($members, static fn ($member) => $member instanceof \Closure));
        if (!$members) {
            return null;
        }

        return Parser::SEPARATOR_OR === $value->separator() ? Conditions::any($members) : Conditions::all($members);
    }

    /**
     * ServiceEntityParser::buildQueryExpr(): one criterion's condition, in the same order of decisions - the words,
     * the dates computed, a JSON field, the strings' patterns, the discriminator, an association (joined when its
     * column is compared, as Doctrine joins it), the comparison.
     *
     * @param list<string>      $field   [the name, (its index | the group, its index)...]
     * @param list<string>|null $options the words of a group's member (the call's otherwise, by the field's key)
     *
     * @return \Closure|Order|string|null a condition; an order for ClosestTo, FarestTo; "" or null for none
     */
    private function criterion(Compilation $compilation, array $field, mixed $fieldValue, ?array $options = null): \Closure|Order|string|null
    {
        $fieldName = (string) $this->subject->alias($field[0]);
        $fieldRoot = implode(Parser::SEPARATOR, \array_slice($field, \count($field) - 2, 2));
        $fieldHead = explode('.', $fieldName)[0];
        $options ??= $this->options[$fieldRoot] ?? [];
        $has = static fn (string $option): bool => \in_array($option, $options, true);

        // A to-one association compared by its own key (Finder\Key, step E2b): no join.
        if ($fieldValue instanceof Key) {
            return $this->keyExpression($fieldName, $fieldValue, $has(Parser::OPTION_NOT_EQUAL));
        }

        $isNull = $has(Parser::OPTION_NULL);
        $isNotNull = $has(Parser::OPTION_NOT_NULL);
        $isEmpty = $has(Parser::OPTION_EMPTY);
        $isNotEmpty = $has(Parser::OPTION_NOT_EMPTY);
        $isBool = $has(Parser::OPTION_TRUE) || $has(Parser::OPTION_NOT_TRUE) || $has(Parser::OPTION_FALSE) || $has(Parser::OPTION_NOT_FALSE);
        $isPartial = $has(Parser::OPTION_PARTIAL);
        $isInsensitive = $has(Parser::OPTION_INSENSITIVE);
        $closestTo = $has(Parser::OPTION_CLOSESTTO);
        $farestTo = $has(Parser::OPTION_FARESTTO);
        $isMemberOf = $has(Parser::OPTION_MEMBEROF);
        $isNotMemberOf = $has(Parser::OPTION_NOT_MEMBEROF);
        $isInstanceOf = $has(Parser::OPTION_INSTANCEOF);
        $isNotInstanceOf = $has(Parser::OPTION_NOT_INSTANCEOF);
        $isClassOf = $has(Parser::OPTION_CLASSOF);
        $isNotClassOf = $has(Parser::OPTION_NOT_CLASSOF);

        $tableOperator = Parser::OPTION_EQUAL;
        foreach ([
            Parser::OPTION_INSTANCEOF, Parser::OPTION_NOT_INSTANCEOF, Parser::OPTION_CLASSOF, Parser::OPTION_NOT_CLASSOF,
            Parser::OPTION_MEMBEROF, Parser::OPTION_NOT_MEMBEROF, Parser::OPTION_OVER, Parser::OPTION_NOT_OVER,
            Parser::OPTION_OLDER, Parser::OPTION_OLDER_EQUAL, Parser::OPTION_WITHIN, Parser::OPTION_YOUNGER,
            Parser::OPTION_YOUNGER_EQUAL, Parser::OPTION_STARTING_WITH, Parser::OPTION_ENDING_WITH,
            Parser::OPTION_NOT_STARTING_WITH, Parser::OPTION_NOT_ENDING_WITH, Parser::OPTION_GREATER,
            Parser::OPTION_GREATER_EQUAL, Parser::OPTION_LOWER, Parser::OPTION_LOWER_EQUAL, Parser::OPTION_NULL,
            Parser::OPTION_NOT_NULL, Parser::OPTION_EMPTY, Parser::OPTION_NOT_EMPTY, Parser::OPTION_TRUE,
            Parser::OPTION_NOT_TRUE, Parser::OPTION_FALSE, Parser::OPTION_NOT_FALSE, Parser::OPTION_NOT_EQUAL,
        ] as $option) {
            if ($has($option)) {
                $tableOperator = $option;
                break;
            }
        }

        $isAssociation = $this->subject->isAssociation($fieldHead);
        // The column compared, resolved when it is used: an association's is joined then, as Doctrine joins it.
        $column = function () use ($compilation, $fieldName, $fieldHead, $isAssociation): string {
            if ($isAssociation) {
                return $this->joinedColumn($compilation, $fieldName);
            }
            if ($this->subject->isField($fieldHead)) {
                if ($fieldHead !== $fieldName) {
                    throw new UnsupportedQueryException(sprintf('"%s" is a path through %s::$%s, which is no association.', $fieldName, $this->map->className(), $fieldHead));
                }

                return $this->wrap($this->map->column($fieldName));
            }
            if (isset($compilation->named[$fieldName])) {
                return '('.$compilation->named[$fieldName].')';
            }

            throw new UnsupportedQueryException(sprintf('%s has no field or association "%s" a finder could compare.', $this->map->className(), $fieldName));
        };

        $datetimeRequested = \in_array($tableOperator, [Parser::OPTION_OVER, Parser::OPTION_NOT_OVER, Parser::OPTION_OLDER, Parser::OPTION_WITHIN, Parser::OPTION_OLDER_EQUAL, Parser::OPTION_YOUNGER, Parser::OPTION_YOUNGER_EQUAL], true);
        if ($datetimeRequested) {
            $fieldValue = $this->dateBound($tableOperator, $fieldValue);
        }

        $regexRequested = \in_array($tableOperator, [Parser::OPTION_STARTING_WITH, Parser::OPTION_ENDING_WITH, Parser::OPTION_NOT_STARTING_WITH, Parser::OPTION_NOT_ENDING_WITH], true);

        if ('json' === $this->subject->typeOf($fieldName, $this->store->mapping()->of(...))) {
            return $this->jsonExpression($fieldName, $fieldValue, $tableOperator, $isInsensitive, $isEmpty || $isNotEmpty);
        } elseif ($regexRequested) {
            $fieldValue = str_replace(['_', '\%'], ['\_', '\%'], $fieldValue);
            $fieldValue = match ($tableOperator) {
                Parser::OPTION_STARTING_WITH, Parser::OPTION_NOT_STARTING_WITH => $fieldValue.'%',
                default => '%'.$fieldValue,
            };
            $fieldValue = ($isInsensitive ? mb_strtolower($fieldValue) : $fieldValue);
        }

        if ($isInstanceOf || $isNotInstanceOf) {
            return $this->instanceOfExpression($fieldValue, $isInstanceOf);
        } elseif ($isClassOf || $isNotClassOf) {
            return $this->classOfExpression($fieldValue, $isClassOf);
        } elseif ($isMemberOf || $isNotMemberOf) {
            return $this->memberOfExpression($fieldValue);
        }

        $bound = null;
        $noParameter = $isEmpty || $isNotEmpty || $isBool || $isNull || $isNotNull;
        if ($isAssociation) {
            if ($isPartial) {
                throw new UnsupportedQueryException(sprintf('A Partial criterion on the association %s::$%s is not compiled: omnibridge/doctrine binds it under a name its expression does not use, and Doctrine refuses the query too.', $this->map->className(), $fieldHead));
            }
            if (!\is_array($fieldValue)) {
                $columnSql = $column();
                if (!$noParameter) {
                    $bound = $this->bound($fieldName, $fieldHead, $fieldValue, null);
                }
            } else {
                $fieldValue = array_filter($fieldValue);
                if ($fieldValue) {
                    $columnSql = $column();
                    $bound = $this->parameter(array_values($fieldValue));
                }
            }
        } elseif (\is_array($fieldValue)) {
            if (!empty($fieldValue)) {
                $bound = $this->parameter(array_values($fieldValue));
            }
        } elseif (!$noParameter) {
            $type = null;
            if (!$isPartial && !$isInsensitive && \in_array($tableOperator, [Parser::OPTION_EQUAL, Parser::OPTION_NOT_EQUAL], true)) {
                $type = $this->convertedTypeOf($fieldHead, $fieldValue);
            }
            $bound = $this->bound($fieldName, $fieldHead, $fieldValue, $type);
        }

        $sql = static function () use (&$columnSql, $column, $isInsensitive): string {
            $columnSql ??= $column();

            return $isInsensitive ? 'LOWER('.$columnSql.')' : $columnSql;
        };

        if ($isPartial) {
            if (Parser::OPTION_EQUAL != $tableOperator && Parser::OPTION_NOT_EQUAL != $tableOperator) {
                throw new \Exception('Invalid operator for association field "'.$fieldName.'": '.$tableOperator);
            }
            if (\is_array($fieldValue)) {
                throw new UnsupportedQueryException(sprintf('A Partial criterion compares one value, not a list ("%s"): omnibridge/doctrine compiles a list to an expression of its parameters\' names, which Doctrine refuses too.', $fieldName));
            }

            return Conditions::raw($sql().(Parser::OPTION_EQUAL == $tableOperator ? ' LIKE ?' : ' NOT LIKE ?'), [$bound]);
        } elseif (\is_array($fieldValue)) {
            if (Parser::OPTION_EQUAL == $tableOperator) {
                $not = false;
            } elseif (Parser::OPTION_NOT_EQUAL == $tableOperator) {
                $not = true;
            } else {
                throw new \Exception('Invalid operator for field "'.$fieldName.'": '.$tableOperator);
            }
            if (empty($fieldValue)) {
                return Conditions::raw('1 = 1');
            }

            return Conditions::raw($sql().($not ? ' NOT IN (' : ' IN (').implode(', ', array_fill(0, \count($bound), '?')).')', $bound);
        } elseif ($regexRequested) {
            $like = \in_array($tableOperator, [Parser::OPTION_STARTING_WITH, Parser::OPTION_ENDING_WITH], true) ? ' LIKE ?' : ' NOT LIKE ?';

            return Conditions::raw($sql().$like, [$bound]);
        } elseif ($closestTo || $farestTo) {
            return new Order('ABS('.$sql().' - ?)', [$bound], $closestTo ? 'ASC' : 'DESC');
        } elseif ($isNull || $isNotNull) {
            return Conditions::raw($sql().(Parser::OPTION_NULL == $tableOperator ? ' IS NULL' : ' IS NOT NULL'));
        } elseif ($isEmpty || $isNotEmpty) {
            return Parser::OPTION_EMPTY == $tableOperator
                ? Conditions::raw('('.$sql().' IS NULL OR '.$sql()." = '')")
                : Conditions::raw('('.$sql().' IS NOT NULL AND '.$sql()." <> '')");
        } elseif ($isBool) {
            $operator = match ($tableOperator) {
                Parser::OPTION_TRUE, Parser::OPTION_NOT_FALSE => ' = ',
                Parser::OPTION_FALSE, Parser::OPTION_NOT_TRUE => ' <> ',
                default => throw new \Exception('Invalid operator for field "'.$fieldName.'": '.$tableOperator),
            };

            return Conditions::raw($sql().$operator.'1');
        }

        $operator = match ($tableOperator) {
            Parser::OPTION_EQUAL => '=',
            Parser::OPTION_NOT_EQUAL => '<>',
            Parser::OPTION_GREATER, Parser::OPTION_YOUNGER, Parser::OPTION_NOT_OVER => '>',
            Parser::OPTION_GREATER_EQUAL, Parser::OPTION_YOUNGER_EQUAL => '>=',
            Parser::OPTION_LOWER, Parser::OPTION_OVER, Parser::OPTION_OLDER, Parser::OPTION_WITHIN => '<',
            Parser::OPTION_LOWER_EQUAL, Parser::OPTION_OLDER_EQUAL => '<=',
            default => throw new \Exception('Invalid operator for field "'.$fieldName.'": '.$tableOperator),
        };

        return Conditions::raw($sql().' '.$operator.' ?', [$bound]);
    }

    /**
     * The bound of a date criterion, as ServiceEntityParser computes it: now for IsOver, IsNotOver; a date given as
     * text read as one; an age (YoungerThan, OlderThan: "+2 days", 172800 seconds) counted back from now, Within's
     * offset forward, to the minute.
     */
    private function dateBound(string $operator, mixed $fieldValue): mixed
    {
        if (is_numeric($fieldValue)) {
            $fieldValue = ($fieldValue > 0 ? '+' : '-').$fieldValue.' second'.($fieldValue > 1 ? 's' : '');
        }
        if ($fieldValue instanceof \DateTime && \in_array($operator, [Parser::OPTION_YOUNGER, Parser::OPTION_YOUNGER_EQUAL, Parser::OPTION_OLDER, Parser::OPTION_OLDER_EQUAL], true)) {
            throw new \Exception('Please use string or int with Older/Younger operands');
        }

        if (\in_array($operator, [Parser::OPTION_OVER, Parser::OPTION_NOT_OVER], true)) {
            return new \DateTime('now');
        }
        if (!$fieldValue instanceof \DateTime && self::validateDate($fieldValue)) {
            return new \DateTime($fieldValue);
        }
        if (!$fieldValue instanceof \DateTime) {
            $offset = $fieldValue;
            if (\in_array($operator, [Parser::OPTION_YOUNGER, Parser::OPTION_YOUNGER_EQUAL, Parser::OPTION_OLDER, Parser::OPTION_OLDER_EQUAL], true)) {
                $offset = strtr($fieldValue, ['+' => '-', '-' => '+']);
            }

            return round_datetime((new \DateTime('now'))->modify($offset), '+1 minute');
        }

        return $fieldValue;
    }

    private static function validateDate(mixed $date, string $format = 'Y-m-d H:i:s'): bool
    {
        $d = \DateTime::createFromFormat($format, $date);

        return $d && IntlDateTime::createFromDateTime($d)->format($format) == $date;
    }

    /**
     * A JSON field, as ServiceEntityParser compares one: a list, each value JSON_CONTAINS()ed (AND; NOT for
     * NotEqualTo; LOWER() for Insensitive), an empty list no condition; one value JSON_CONTAINS()ed as it is given.
     * On SQLite (no JSON_CONTAINS) a value of the array: json_each(). Empty, NotEmpty: refused as Doctrine's
     * compiler refuses them.
     */
    private function jsonExpression(string $fieldName, mixed $fieldValue, string $tableOperator, bool $isInsensitive, bool $emptiness): \Closure
    {
        if (str_contains($fieldName, '.')) {
            throw new UnsupportedQueryException(sprintf('The JSON field "%s" is compared through a path: not compiled.', $fieldName));
        }
        $column = $this->wrap($this->map->column($fieldName));
        $contains = function (string $value, bool $lower) use ($column): \Closure {
            if (\in_array($this->driver(), ['mysql', 'mariadb'], true)) {
                return $lower
                    ? Conditions::raw("JSON_CONTAINS(LOWER(JSON_EXTRACT({$column}, '\$')), LOWER(?), '\$') = 1", [$value])
                    : Conditions::raw("JSON_CONTAINS({$column}, ?, '\$') = 1", [$value]);
            }
            if ('sqlite' !== $this->driver()) {
                throw new UnsupportedQueryException(sprintf('A JSON field is compared on MySQL and SQLite only, not on %s.', $this->driver()));
            }
            $member = json_decode($value, true);

            return Conditions::raw('EXISTS (SELECT 1 FROM json_each('.$column.') AS je WHERE '.($lower ? 'LOWER(je.value) = LOWER(?)' : 'je.value = ?').')', [\is_scalar($member) ? $member : $value]);
        };

        if (\is_array($fieldValue)) {
            if (empty($fieldValue)) {
                return Conditions::raw('1 = 1');
            }
            $parts = [];
            foreach ($fieldValue as $subFieldValue) {
                $parts[] = $contains('"'.$subFieldValue.'"', $isInsensitive);
            }
            if (Parser::OPTION_EQUAL == $tableOperator) {
                return Conditions::all($parts);
            } elseif (Parser::OPTION_NOT_EQUAL == $tableOperator) {
                return Conditions::not(Conditions::all($parts));
            }

            throw new \Exception('Invalid operator for field "'.$fieldName.'": '.$tableOperator);
        } elseif ($emptiness) {
            throw new \LogicException('Operation not supported for json-like field "'.$fieldName.'" in "'.$this->map->className().'"');
        }
        if ($isInsensitive) {
            throw new UnsupportedQueryException(sprintf('An Insensitive criterion on one value of the JSON field "%s" is not compiled: omnibridge/doctrine reads it at the path $.NODE of the lowered text.', $fieldName));
        }

        return $contains((string) $this->parameter($fieldValue), false);
    }

    /** InstanceOf, NotInstanceOf: the discriminator among the values of the classes given and their subclasses (^ reverses one). */
    private function instanceOfExpression(mixed $fieldValue, bool $isInstanceOf): string|\Closure
    {
        if (!\is_array($fieldValue)) {
            $fieldValue = null !== $fieldValue ? [$fieldValue] : [];
        }
        $instanceOf = [];
        $notInstanceOf = [];
        if (null !== $this->map->inheritance) {
            foreach ($fieldValue as $value) {
                [$class, $reverse] = $this->classOfValue($value);
                $values = $this->instanceValues($class);
                if ($isInstanceOf xor $reverse) {
                    $instanceOf[] = $this->discriminatorIn($values, false);
                } else {
                    $notInstanceOf[] = $this->discriminatorIn($values, true);
                }
            }
        }

        $parts = $instanceOf ? [Conditions::any($instanceOf)] : [];
        if ($notInstanceOf) {
            $parts[] = Conditions::all($notInstanceOf);
        }

        return $parts ? Conditions::all($parts) : '';
    }

    /** ClassOf, NotClassOf: the discriminator of the class itself, none of its subclasses' (^ reverses one). */
    private function classOfExpression(mixed $fieldValue, bool $isClassOf): string|\Closure
    {
        if (!\is_array($fieldValue)) {
            $fieldValue = null !== $fieldValue ? [$fieldValue] : [];
        }
        $classOf = [];
        $notClassOf = [];
        if (null !== $this->map->inheritance) {
            foreach ($fieldValue as $value) {
                [$class, $reverse] = $this->classOfValue($value);
                // The class's own rows: its instances but its children's, as ServiceEntityParser writes it (INSTANCE OF
                // the class AND NOT INSTANCE OF each class of the map below it).
                $this->store->mapping()->of($class);
                $values = $this->instanceValues($class);
                foreach ($this->map->inheritance->map as $child) {
                    if ($child != $class && is_a($child, $class, true)) {
                        $values = array_values(array_diff($values, $this->instanceValues($child)));
                    }
                }
                if ($isClassOf && !$reverse) {
                    $classOf[] = $this->discriminatorIn($values, false);
                } elseif ($isClassOf) {
                    $classOf[] = $this->discriminatorIn($values, true);
                } elseif (!$reverse) {
                    $notClassOf[] = $this->discriminatorIn($values, true);
                } else {
                    $classOf[] = $this->discriminatorIn($values, false);
                }
            }
        }

        $parts = $classOf ? [Conditions::any($classOf)] : [];
        if ($notClassOf) {
            $parts[] = Conditions::all($notClassOf);
        }

        return $parts ? Conditions::all($parts) : '';
    }

    /** MemberOf, NotMemberOf: no condition without a discriminator (as Doctrine's compiler), refused with one. */
    private function memberOfExpression(mixed $fieldValue): string
    {
        if (!\is_array($fieldValue)) {
            $fieldValue = null !== $fieldValue ? [$fieldValue] : [];
        }
        if (null === $this->map->inheritance || [] === $fieldValue) {
            return '';
        }
        foreach ($fieldValue as $value) {
            $this->classOfValue($value);
        }

        throw new UnsupportedQueryException(sprintf('MemberOf is not compiled for %s: omnibridge/doctrine writes it as "e MEMBER OF <class>", which DQL refuses as well (MEMBER OF compares an entity with a collection).', $this->map->className()));
    }

    /** @return array{0: string, 1: bool} the class a value names (^ reversing it), refused when there is none */
    private function classOfValue(mixed $value): array
    {
        $reverse = \is_string($value) && str_starts_with($value, '^');
        if ($reverse) {
            $value = ltrim($value, '^');
        }
        if (!\is_string($value) || !class_exists($value)) {
            throw new \InvalidArgumentException('Entity "'.(\is_string($value) ? $value : get_debug_type($value)).'" doesn\'t exists');
        }

        return [$value, $reverse];
    }

    /** @return list<string> the discriminator values of a class and its subclasses in the hierarchy's map */
    private function instanceValues(string $class): array
    {
        $values = [];
        foreach ($this->map->inheritance?->map ?? [] as $value => $mapped) {
            if (is_a($mapped, $class, true)) {
                $values[] = (string) $value;
            }
        }

        return $values;
    }

    /** @param list<string> $values */
    private function discriminatorIn(array $values, bool $not): \Closure
    {
        $column = $this->wrap('t0.'.$this->map->discriminator());
        if ([] === $values) {
            return Conditions::raw($not ? '1 = 1' : '1 = 0');
        }

        return Conditions::raw($column.($not ? ' NOT IN (' : ' IN (').implode(', ', array_fill(0, \count($values), '?')).')', $values);
    }

    /**
     * A key criterion (Finder\Key, step E2b): the association's own foreign key, `= ?`, `IN`, `IS NULL` (NotEqualTo:
     * `<>`, `NOT IN`, `IS NOT NULL`), no join - no scope of the associated entity decides the row. An empty list adds
     * no condition.
     */
    private function keyExpression(string $fieldName, Key $key, bool $not): ?\Closure
    {
        $relation = $this->subject->relation($fieldName);
        if (null === $relation || !$relation->isToOne()) {
            throw new \Exception('A key criterion compares a to-one association of "'.$this->map->className().'", which "'.$fieldName.'" is not');
        }
        if (!$this->map->hasColumn($fieldName)) {
            throw new UnsupportedQueryException(sprintf('%s::$%s is the inverse side of a one-to-one: it has no key of its own to compare (DQL refuses it too).', $this->map->className(), $fieldName));
        }
        $column = $this->wrap($this->map->column($fieldName));
        if ($key->isNull()) {
            return Conditions::raw($column.($not ? ' IS NOT NULL' : ' IS NULL'));
        }
        if ($key->isList() && !$key->value) {
            return null;
        }
        $target = $this->store->mapping()->of($relation->target);
        $type = $target->identifier[0]->type;
        $ids = array_map(fn (mixed $id): mixed => $this->store->casts()->toDatabase($type, $id), (array) $this->parameter($key->isList() ? $key->value : [$key->value]));
        if ($key->isList()) {
            return Conditions::raw($column.($not ? ' NOT IN (' : ' IN (').implode(', ', array_fill(0, \count($ids), '?')).')', $ids);
        }

        return Conditions::raw($column.($not ? ' <> ?' : ' = ?'), $ids);
    }

    /**
     * A value bound as Doctrine binds it: a date with the field's type (DateTime and DateTimeImmutable inferred
     * through a path), a converting type's value converted (ConvertedInCriteria: the passkeys' base64 and AAGUID),
     * the rest as parameter() makes it.
     */
    private function bound(string $fieldName, string $fieldHead, mixed $fieldValue, ?string $type): mixed
    {
        if ($fieldValue instanceof \DateTimeInterface) {
            $type = $this->subject->isField($fieldHead) ? $this->map->entity->fields[$fieldHead]->type : ($fieldValue instanceof \DateTimeImmutable ? 'datetime_immutable' : 'datetime');

            return $this->store->casts()->toDatabase($type, $fieldValue);
        }
        if (null !== $type) {
            return $this->store->casts()->toDatabase($type, $fieldValue);
        }

        return $this->parameter($fieldValue);
    }

    /** The type an equality on one value is bound with when the field's type converts it (omnibase's passkeys), null otherwise. */
    private function convertedTypeOf(string $fieldHead, mixed $fieldValue): ?string
    {
        if (!\is_scalar($fieldValue) || !$this->subject->isField($fieldHead)) {
            return null;
        }
        $type = $this->map->entity->fields[$fieldHead]->type;

        return \in_array($type, ['passkey_base64', 'passkey_aaguid'], true) ? $type : null;
    }

    /**
     * A parameter as Doctrine's Query::processParameterValue() makes it: a PHP enumeration its value, an entity (of the
     * store, Doctrine's or any) its identifier, a list each member so.
     */
    private function parameter(mixed $value): mixed
    {
        if (\is_array($value)) {
            return array_map($this->parameter(...), array_values($value));
        }
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if (\is_object($value) && null !== ($map = $this->mapOfObject($value))) {
            if (1 !== \count($map->identifier)) {
                throw new UnsupportedQueryException(sprintf('A %s compared by its composite identifier: not compiled.', $map->className()));
            }
            $field = $map->identifier[0];
            $property = new \ReflectionProperty($field->declaredIn ?? $map->className(), $field->property);
            $id = $property->isInitialized($value) || (new \ReflectionClass($value))->isUninitializedLazyObject($value) ? $property->getRawValue($value) : null;
            if (null === $id) {
                throw new \InvalidArgumentException('Binding entities to query parameters only allowed for entities that have an identifier.');
            }

            return $id;
        }

        return $value;
    }

    /** The map of an entity's class, through its parents (a Doctrine proxy's); null for an object that is no entity. */
    private function mapOfObject(object $value): ?EntityMap
    {
        for ($class = $value::class; false !== $class; $class = get_parent_class($class)) {
            if ($this->store->mapping()->has($class)) {
                return $this->store->mapping()->of($class);
            }
        }

        return null;
    }

    /**
     * The column of an association's criterion: the joined target's identifier for the association itself
     * ("e_thread = :thread"), its field (or owning to-one key) for a path ("translatable.state").
     */
    private function joinedColumn(Compilation $compilation, string $fieldName): string
    {
        $path = explode('.', $fieldName);
        if (\count($path) > 2) {
            throw new UnsupportedQueryException(sprintf('"%s" goes through more than one association: the finder joins one.', $fieldName));
        }
        [$prefix, $target] = $this->join($compilation, $path[0]);
        if (1 === \count($path)) {
            return $this->wrap($target->idColumns($prefix)[0]);
        }
        if (!$target->hasColumn($path[1])) {
            throw new UnsupportedQueryException(sprintf('%s has no field or owning to-one association "%s" (in "%s").', $target->className(), $path[1], $fieldName));
        }

        return $this->wrap($target->column($path[1], $prefix));
    }

    private function joinedIdentifier(Compilation $compilation, string $association): string
    {
        [$prefix, $target] = $this->join($compilation, $association);

        return $this->wrap($target->idColumns($prefix)[0]);
    }

    /**
     * An association LEFT JOINed once (j1_0, j1_1... its tables): on the owner's foreign key, the target's
     * (mappedBy: a to-many or an inverse to-one association, a translation's own key), or a join table's; the store's
     * conditions and the target's discriminator values (a subclass of a hierarchy) in the ON.
     *
     * @return array{0: string, 1: EntityMap}
     */
    private function join(Compilation $compilation, string $association): array
    {
        if (isset($compilation->joins[$association])) {
            return $compilation->joins[$association];
        }
        $relation = $this->subject->relation($association) ?? throw new \LogicException(sprintf('%s::$%s is no association.', $this->map->className(), $association));
        $target = $this->store->mapping()->of($relation->target);
        $prefix = 'j'.(++$this->joined).'_';
        $query = $compilation->query;
        $root = $target->tables[0].' as '.$prefix.'0';
        $targetId = $target->identifier[0]->column;
        $ownerId = $this->map->idColumns()[0];

        $constrain = function (JoinClause $join) use ($target, $prefix): void {
            if ($target->className() !== $target->root && null !== $target->inheritance) {
                $join->whereIn($prefix.'0.'.$target->discriminator(), $this->targetValues($target));
            }
            $this->store->constrain($target, $join, $prefix);
        };

        if ($relation->isToOne() && $relation->owning) {
            $referenced = $relation->joinColumns[0]->referencedColumn ?? $targetId;
            $query->leftJoin($root, function (JoinClause $join) use ($prefix, $referenced, $association, $constrain): void {
                $join->on($prefix.'0.'.$referenced, '=', $this->map->column($association));
                $constrain($join);
            });
        } elseif (Relation::MANY_TO_MANY === $relation->kind) {
            if ($relation->owning) {
                $joinTable = $relation->joinTable ?? throw new \LogicException(sprintf('%s::$%s has no join table.', $this->map->className(), $association));
                [$ownerColumn, $targetColumn] = [$joinTable->joinColumns[0]->name, $joinTable->inverseJoinColumns[0]->name];
            } else {
                $owning = $target->entity->relations[(string) $relation->mappedBy] ?? throw new \LogicException(sprintf('%s::$%s is mapped by no association of %s.', $this->map->className(), $association, $target->className()));
                $joinTable = $owning->joinTable ?? throw new \LogicException(sprintf('%s::$%s has no join table.', $target->className(), $owning->property));
                [$ownerColumn, $targetColumn] = [$joinTable->inverseJoinColumns[0]->name, $joinTable->joinColumns[0]->name];
            }
            $query->leftJoin($joinTable->name.' as '.$prefix.'jt', $prefix.'jt.'.$ownerColumn, '=', $ownerId);
            $query->leftJoin($root, function (JoinClause $join) use ($prefix, $targetId, $targetColumn, $constrain): void {
                $join->on($prefix.'0.'.$targetId, '=', $prefix.'jt.'.$targetColumn);
                $constrain($join);
            });
        } else {
            // The target holds the key (mappedBy): a one-to-many, an inverse one-to-one - a Translatable's translations,
            // on the translation's translatable_id in the root table of its hierarchy (TranslatableWalker's rewrite).
            $mappedBy = (string) $relation->mappedBy;
            if (!$target->hasColumn($mappedBy) || $target->tableOf($mappedBy) !== $target->tables[0]) {
                throw new UnsupportedQueryException(sprintf('%s::$%s is mapped by %s::$%s, whose key is not in the root table of its hierarchy: not joined.', $this->map->className(), $association, $target->className(), $mappedBy));
            }
            $query->leftJoin($root, function (JoinClause $join) use ($target, $prefix, $mappedBy, $ownerId, $constrain): void {
                $join->on($target->column($mappedBy, $prefix), '=', $ownerId);
                $constrain($join);
            });
        }
        foreach (\array_slice($target->tables, 1, null, true) as $index => $table) {
            $query->leftJoin($table.' as '.$prefix.$index, $prefix.$index.'.'.$targetId, '=', $prefix.'0.'.$targetId);
        }

        return $compilation->joins[$association] = [$prefix, $target];
    }

    /** @return list<string> the discriminator values a joined target's class and its subclasses have */
    private function targetValues(EntityMap $target): array
    {
        $values = [];
        foreach ($target->inheritance?->map ?? [] as $value => $class) {
            if (is_a($class, $target->className(), true)) {
                $values[] = (string) $value;
            }
        }

        return $values;
    }

    /** SIZE(): a collection's members counted for each row, in its join table or its target's table. */
    private function size(Relation $relation): string
    {
        $ownerId = $this->wrap($this->map->idColumns()[0]);
        $target = $this->store->mapping()->of($relation->target);
        if (Relation::MANY_TO_MANY === $relation->kind) {
            if ($relation->owning) {
                $joinTable = $relation->joinTable ?? throw new \LogicException(sprintf('%s::$%s has no join table.', $this->map->className(), $relation->property));
                $ownerColumn = $joinTable->joinColumns[0]->name;
            } else {
                $joinTable = $target->entity->relations[(string) $relation->mappedBy]->joinTable ?? throw new \LogicException(sprintf('%s::$%s has no join table.', $target->className(), (string) $relation->mappedBy));
                $ownerColumn = $joinTable->inverseJoinColumns[0]->name;
            }

            return '(SELECT COUNT(*) FROM '.$this->wrapTable($joinTable->name).' AS '.$this->wrap('size_jt').' WHERE '.$this->wrap('size_jt.'.$ownerColumn).' = '.$ownerId.')';
        }
        $mappedBy = (string) $relation->mappedBy;
        $table = $target->tableOf($mappedBy);
        $column = $target->entity->relations[$mappedBy] ?? null;
        $keyColumn = null === $column ? $mappedBy : $target->joinColumn($column);

        return '(SELECT COUNT(*) FROM '.$this->wrapTable($table).' AS '.$this->wrap('size_t').' WHERE '.$this->wrap('size_t.'.$keyColumn).' = '.$ownerId.')';
    }

    /** ServiceEntityParser::selectAs(): each value selected under its name (an expression of Expressions). */
    private function selectAs(Compilation $compilation, array $selectAs): void
    {
        foreach ($selectAs as $select => $as) {
            if (!\is_string($select)) {
                if (!\is_string($as) || !preg_match('~^e\.(\w+)$~', $as, $match)) {
                    throw new UnsupportedQueryException(sprintf('A selected value without a name ("%s") is selected by a field of the entity only (e.<field>).', \is_string($as) ? $as : get_debug_type($as)));
                }
                $select = $match[1];
            }
            $sql = $this->expressions($compilation)->sql((string) $as);
            $compilation->named[$select] = $sql;
            if (null === $compilation->entityKey) {
                $compilation->entityKey = 0;
            }
            $compilation->addScalar($select, $sql);
        }
    }

    /**
     * ServiceEntityParser::orderBy(): a field (its alias read), an association's field (joined), a ClosestTo's distance,
     * a list of values (FIELD()), id => "rand" (RAND(), the terms before it dropped), a selected value's name, an
     * expression.
     *
     * @param list<array{0: string|int|Order, 1: mixed}> $orders
     */
    private function orderBy(Compilation $compilation, array $orders): void
    {
        foreach ($orders as [$name, $value]) {
            if ($name instanceof Order) {
                $compilation->addOrder($name->sql.' '.$name->direction, $name->bindings);
                continue;
            }
            $path = array_map(fn ($segment) => $this->subject->alias($segment), explode('.', (string) $name));
            $name = implode('.', $path);
            $entity = $path[0];

            $isRandom = ('id' == $name && 'rand' == strtolower($value));
            if ($isRandom) {
                $compilation->resetOrders();
                $compilation->addOrder($this->expressions($compilation)->random());
                continue;
            }

            if ($this->subject->isField($entity)) {
                if (\count($path) > 1) {
                    throw new UnsupportedQueryException(sprintf('"%s" is a path through %s::$%s, which is no association.', $name, $this->map->className(), $entity));
                }
                $sql = $this->wrap($this->map->column($entity));
            } elseif ($this->subject->isAssociation($entity)) {
                if (1 === \count($path)) {
                    throw new UnsupportedQueryException(sprintf('An order on the association %s::$%s itself: order on one of its fields ("%s.<field>").', $this->map->className(), $entity, $entity));
                }
                $sql = $this->joinedColumn($compilation, $name);
            } elseif (isset($compilation->named[$name])) {
                $sql = $compilation->named[$name];
            } else {
                $sql = $this->expressions($compilation)->sql($name);
            }

            if (\is_array($value)) {
                $compilation->addOrder($this->expressions($compilation)->field($sql, array_values($value)), array_values($value));
                continue;
            }
            $direction = strtoupper((string) $value);
            if (!\in_array($direction, ['ASC', 'DESC'], true)) {
                throw new UnsupportedQueryException(sprintf('"%s" orders by "%s": ASC or DESC.', $name, (string) $value));
            }
            $compilation->addOrder($sql.' '.$direction);
        }
    }

    /**
     * ServiceEntityParser::groupBy(): each field (its alias read), an association by its target's identifier (joined,
     * and selected under its name), a path's field, an expression.
     */
    private function groupBy(Compilation $compilation, array $groupBy): void
    {
        if (!$groupBy) {
            return;
        }
        foreach ($groupBy as $column) {
            $aliasIdentifier = str_replace('.', '_', (string) $column);
            $column = implode('.', array_map(fn ($segment) => $this->subject->alias($segment), explode('.', (string) $column)));
            $columnHead = explode('.', $column)[0];

            if ($this->subject->isAssociation($column)) {
                $sql = $this->joinedIdentifier($compilation, $column);
                $compilation->addScalar($aliasIdentifier, $sql);
            } elseif ($this->subject->isAssociation($columnHead)) {
                $sql = $this->joinedColumn($compilation, $column);
            } elseif ($this->subject->isField($columnHead) && $columnHead === $column) {
                $sql = $this->wrap($this->map->column($column));
            } else {
                $sql = $this->expressions($compilation)->sql($column);
            }
            $compilation->addGroup($sql);
        }
    }

    private function expressions(Compilation $compilation): Expressions
    {
        return new Expressions(function (string $alias, string $field) use ($compilation): string {
            $field = (string) $this->subject->alias($field);
            if ('e' === $alias) {
                if (!$this->map->hasColumn($field)) {
                    throw new UnsupportedQueryException(sprintf('%s has no column "%s" (e.%s).', $this->map->className(), $field, $field));
                }

                return $this->wrap($this->map->column($field));
            }

            return $this->joinedColumn($compilation, substr($alias, 2).'.'.$field);
        }, $compilation->named, $this->driver());
    }

    private function driver(): string
    {
        return $this->store->connection()->getDriverName();
    }

    private function wrap(string $column): string
    {
        return $this->store->connection()->getQueryGrammar()->wrap($column);
    }

    private function wrapTable(string $table): string
    {
        return $this->store->connection()->getQueryGrammar()->wrapTable($table);
    }
}
