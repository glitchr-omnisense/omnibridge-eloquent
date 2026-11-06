<?php

namespace Omnibridge\Eloquent\Omnibase\Finder;

use Base\Database\Repository\UnsupportedQueryException;

/**
 * The few DQL expressions omnibase's finder calls hand over as text - a selected value (ImageCrops' ratio,
 * `['ratio0' => 'e.width0/e.height0']`), an order or a grouping that names no field - written in SQL for the
 * connection's database: `e.<field>` the entity's column, `e_<association>.<field>` a joined association's (joined
 * then), a selected value's name its expression, numbers, quoted texts, + - * / %, parentheses, and the functions
 * omnibridge/doctrine registers or DQL has that the finder's callers use:
 *
 * - ABS, LOWER, UPPER, LENGTH, COALESCE, MOD, ROUND;
 * - RAND(): RAND() on MySQL, RANDOM() on SQLite and PostgreSQL;
 * - FIELD(x, a, b...): FIELD() on MySQL, the same CASE elsewhere (0 when x is none of them);
 * - JSON_ARRAY_POSITION(json, value): the zero-based position of value in a JSON array, as omnibridge/doctrine's
 *   function writes it on MySQL (JSON_TABLE) and PostgreSQL, json_each() on SQLite.
 *
 * Anything else - a parameter, another function, a subquery, a name that is none of those - is refused aloud
 * (UnsupportedQueryException), never passed through as SQL.
 *
 * @internal
 */
final class Expressions
{
    private const FUNCTIONS = ['ABS', 'LOWER', 'UPPER', 'LENGTH', 'COALESCE', 'MOD', 'ROUND'];

    /** @var list<string> */
    private array $tokens = [];

    private int $position = 0;

    private string $source = '';

    /**
     * @param \Closure(string, string): string $column  "e" or "e_<association>", a field => its SQL (wrapped)
     * @param array<string, string>            $named   a selected value's name => its SQL
     * @param string                           $driver  the connection's driver name (mysql, mariadb, sqlite, pgsql)
     */
    public function __construct(
        private readonly \Closure $column,
        private readonly array $named,
        private readonly string $driver,
    ) {
    }

    public function sql(string $dql): string
    {
        $this->source = $dql;
        $this->position = 0;
        if (!preg_match_all("~\\s*('(?:[^']|'')*'|\\d+(?:\\.\\d+)?|[A-Za-z_][A-Za-z0-9_]*(?:\\.[A-Za-z_][A-Za-z0-9_]*)?|<>|!=|<=|>=|[-+*/%(),<>=]|:\\w+|\\S)~", $dql, $matches)) {
            throw $this->refuse('nothing to read');
        }
        $this->tokens = $matches[1];
        $sql = $this->expression();
        if ($this->position < \count($this->tokens)) {
            throw $this->refuse('"'.$this->tokens[$this->position].'" unexpected');
        }

        return $sql;
    }

    /** The SQL of RAND() on this database. */
    public function random(): string
    {
        return \in_array($this->driver, ['mysql', 'mariadb'], true) ? 'RAND()' : 'RANDOM()';
    }

    /**
     * The SQL of FIELD(x, values...) on this database, the values bound.
     *
     * @param list<mixed> $values
     */
    public function field(string $sql, array $values): string
    {
        if (\in_array($this->driver, ['mysql', 'mariadb'], true)) {
            return 'FIELD('.$sql.', '.implode(', ', array_fill(0, \count($values), '?')).')';
        }
        $case = 'CASE '.$sql;
        foreach (array_keys(array_values($values)) as $position) {
            $case .= ' WHEN ? THEN '.($position + 1);
        }

        return $case.' ELSE 0 END';
    }

    private function expression(): string
    {
        $sql = [];
        while (null !== ($token = $this->peek()) && ')' !== $token && ',' !== $token) {
            $sql[] = $this->term();
        }
        if ([] === $sql) {
            throw $this->refuse('an empty expression');
        }

        return implode(' ', $sql);
    }

    private function term(): string
    {
        $token = $this->next();
        if (\in_array($token, ['+', '-', '*', '/', '%', '<', '>', '=', '<>', '!=', '<=', '>='], true)) {
            return '!=' === $token ? '<>' : $token;
        }
        if ('(' === $token) {
            $inner = $this->expression();
            $this->expect(')');

            return '('.$inner.')';
        }
        if ("'" === $token[0] || ctype_digit($token[0])) {
            return $token;
        }
        if (':' === $token[0]) {
            throw $this->refuse('a parameter ('.$token.') has no value here');
        }
        if (!preg_match('~^[A-Za-z_]~', $token)) {
            throw $this->refuse('"'.$token.'" unexpected');
        }
        if ('(' === $this->peek()) {
            return $this->call(strtoupper($token));
        }
        if (str_contains($token, '.')) {
            [$alias, $field] = explode('.', $token, 2);
            if ('e' !== $alias && !str_starts_with($alias, 'e_')) {
                throw $this->refuse('"'.$alias.'" is no alias of the finder (e, e_<association>)');
            }

            return ($this->column)($alias, $field);
        }
        if (isset($this->named[$token])) {
            return '('.$this->named[$token].')';
        }

        throw $this->refuse('"'.$token.'" names no field, association or selected value');
    }

    private function call(string $function): string
    {
        $this->expect('(');
        $arguments = [];
        if (')' !== $this->peek()) {
            do {
                $arguments[] = $this->expression();
            } while (',' === $this->peek() && null !== $this->next());
        }
        $this->expect(')');

        return match (true) {
            'RAND' === $function && [] === $arguments => $this->random(),
            'FIELD' === $function && \count($arguments) > 1 => $this->fieldOf($arguments),
            'JSON_ARRAY_POSITION' === $function && 2 === \count($arguments) => $this->jsonArrayPosition($arguments[0], $arguments[1]),
            \in_array($function, self::FUNCTIONS, true) => $function.'('.implode(', ', $arguments).')',
            default => throw $this->refuse('the function '.$function.'() with '.\count($arguments).' argument(s) is not translated for Eloquent'),
        };
    }

    /** @param list<string> $arguments */
    private function fieldOf(array $arguments): string
    {
        $sql = array_shift($arguments);
        if (\in_array($this->driver, ['mysql', 'mariadb'], true)) {
            return 'FIELD('.$sql.', '.implode(', ', $arguments).')';
        }
        $case = 'CASE '.$sql;
        foreach ($arguments as $position => $argument) {
            $case .= ' WHEN '.$argument.' THEN '.($position + 1);
        }

        return $case.' ELSE 0 END';
    }

    private function jsonArrayPosition(string $json, string $value): string
    {
        return match ($this->driver) {
            'mysql', 'mariadb' => "(SELECT jt.idx - 1 FROM JSON_TABLE({$json}, '\$[*]' COLUMNS (idx FOR ORDINALITY, val JSON PATH '\$')) AS jt WHERE jt.val = {$value})",
            'pgsql' => "(SELECT elem_index - 1 FROM jsonb_array_elements({$json}) WITH ORDINALITY AS elems(elem_value, elem_index) WHERE elems.elem_value = to_jsonb({$value}))",
            'sqlite' => "(SELECT CAST(je.key AS INTEGER) FROM json_each({$json}) AS je WHERE je.value = {$value})",
            default => throw $this->refuse('JSON_ARRAY_POSITION() has no translation for the '.$this->driver.' driver'),
        };
    }

    private function peek(): ?string
    {
        return $this->tokens[$this->position] ?? null;
    }

    private function next(): ?string
    {
        return $this->tokens[$this->position++] ?? null;
    }

    private function expect(string $token): void
    {
        if ($this->next() !== $token) {
            throw $this->refuse('"'.$token.'" expected');
        }
    }

    private function refuse(string $why): UnsupportedQueryException
    {
        return new UnsupportedQueryException(sprintf('The finder expression "%s" is not compiled for Eloquent: %s.', $this->source, $why));
    }
}
