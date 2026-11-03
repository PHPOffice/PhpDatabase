<?php

/**
 * This file is part of PhpOffice\PhpDatabase - A pure PHP library for reading and writing
 * database documents (ACCDB, ODB, SQLite).
 *
 * PhpOffice\PhpDatabase is free software distributed under the terms of the MIT License.
 *
 * @license https://opensource.org/licenses/MIT MIT
 */

declare(strict_types=1);

namespace PhpOffice\PhpDatabase\Shared\Access;

use PhpOffice\PhpDatabase\Shared\Sql\Dialect;

/**
 * Select and union queries of Access: rows of MSysQueries (one row per element of the statement:
 * column, table, join, where, group by, having, order by) to SQL and back (rules of Jackcess, QueryImpl).
 *
 * @phpstan-type QueryRow array{Attribute: int, Expression: null|string, Flag: null|int, LvExtra: null|int, Name1: null|string, Name2: null|string, Order: string}
 */
final class QueryFormat
{
    public const ATTRIBUTE_START = 0;
    public const ATTRIBUTE_TYPE = 1;
    public const ATTRIBUTE_PARAMETER = 2;
    public const ATTRIBUTE_FLAG = 3;
    public const ATTRIBUTE_REMOTE_DATABASE = 4;
    public const ATTRIBUTE_TABLE = 5;
    public const ATTRIBUTE_COLUMN = 6;
    public const ATTRIBUTE_JOIN = 7;
    public const ATTRIBUTE_WHERE = 8;
    public const ATTRIBUTE_GROUP_BY = 9;
    public const ATTRIBUTE_HAVING = 10;
    public const ATTRIBUTE_ORDER_BY = 11;
    public const ATTRIBUTE_END = 255;

    /** Object flags (MSysObjects.Flags) and type flags (type row) of the queries */
    public const OBJECT_FLAG_SELECT = 0;
    public const OBJECT_FLAG_UNION = 128;
    public const TYPE_SELECT = 1;
    public const TYPE_UNION = 9;

    private const SELECT_STAR = 0x01;
    private const DISTINCT = 0x02;
    private const DISTINCT_ROW = 0x08;
    private const TOP = 0x10;
    private const PERCENT = 0x20;
    private const UNION_DISTINCT = 0x02;
    private const UNION_PART1 = 'X7YZ_____1';
    private const UNION_PART2 = 'X7YZ_____2';
    private const JOIN_TYPES = [1 => 'INNER JOIN', 2 => 'LEFT JOIN', 3 => 'RIGHT JOIN'];

    /**
     * SQL (Access dialect) of a select or union query, null for the other queries.
     *
     * @param array<int, array<string, mixed>> $rows rows of MSysQueries, sorted by attribute and order
     */
    public static function toSql(int $objectFlags, array $rows): ?string
    {
        $type = (int) (self::first($rows, self::ATTRIBUTE_TYPE)['Flag'] ?? 0);
        $objectType = $objectFlags & 0xF0;
        if (self::OBJECT_FLAG_UNION === $objectType || self::TYPE_UNION === $type) {
            return self::unionSql($rows);
        }
        if (self::OBJECT_FLAG_SELECT !== $objectType || self::TYPE_SELECT !== $type) {
            return null;
        }
        $flags = (int) (self::first($rows, self::ATTRIBUTE_FLAG)['Flag'] ?? 0);
        $sql = '';
        $parameters = [];
        foreach (self::all($rows, self::ATTRIBUTE_PARAMETER) as $row) {
            $parameters[] = $row['Name1'];
        }
        if (!empty($parameters)) {
            // Parameterized query: kept, but not portable
            $sql .= 'PARAMETERS ' . implode(', ', $parameters) . ";\n";
        }
        $sql .= 'SELECT ';
        if (0 !== ($flags & self::DISTINCT)) {
            $sql .= 'DISTINCT ';
        } elseif (0 !== ($flags & self::DISTINCT_ROW)) {
            $sql .= 'DISTINCTROW ';
        }
        if (0 !== ($flags & self::TOP)) {
            $sql .= 'TOP ' . self::first($rows, self::ATTRIBUTE_FLAG)['Name1'] . (0 !== ($flags & self::PERCENT) ? ' PERCENT ' : ' ');
        }
        $columns = [];
        foreach (self::all($rows, self::ATTRIBUTE_COLUMN) as $row) {
            $columns[] = $row['Expression'] . (null !== $row['Name1'] && '' !== $row['Name1'] ? ' AS ' . self::quoteOptional((string) $row['Name1'], false) : '');
        }
        if (0 !== ($flags & self::SELECT_STAR)) {
            $columns[] = '*';
        }
        $sql .= implode(', ', $columns);
        $from = self::fromTables($rows);
        if (!empty($from)) {
            $sql .= "\nFROM " . implode(', ', $from);
        }
        $where = self::first($rows, self::ATTRIBUTE_WHERE)['Expression'] ?? null;
        if (null !== $where) {
            $sql .= "\nWHERE " . $where;
        }
        $groups = array_map(static fn (array $row): string => (string) $row['Expression'], self::all($rows, self::ATTRIBUTE_GROUP_BY));
        if (!empty($groups)) {
            $sql .= "\nGROUP BY " . implode(', ', $groups);
        }
        $having = self::first($rows, self::ATTRIBUTE_HAVING)['Expression'] ?? null;
        if (null !== $having) {
            $sql .= "\nHAVING " . $having;
        }

        return $sql . self::orderBy($rows);
    }

    /**
     * Rows of MSysQueries for a SELECT statement (standard SQL), with the object flags of the query;
     * null when the statement can't be stored by Access (subqueries in FROM, NATURAL/FULL joins...).
     *
     * @return null|array{flags: int, rows: array<int, array{0: int, 1: null|string, 2: null|int, 3: null|string, 4: null|string}>}
     */
    public static function fromSql(string $sql): ?array
    {
        $tokens = Dialect::tokenize($sql, Dialect::STANDARD);
        while (!empty($tokens) && Dialect::is($tokens[count($tokens) - 1], ';')) {
            array_pop($tokens);
        }
        $tokens = Dialect::transform($tokens, Dialect::STANDARD, Dialect::ACCESS, false);
        if (empty($tokens) || !Dialect::is($tokens[0], 'SELECT')) {
            return null;
        }
        // UNION: the last UNION separates the two parts, a final ORDER BY applies to the union
        $unions = self::topLevel($tokens, ['UNION']);
        if (!empty($unions)) {
            $last = end($unions);
            $all = Dialect::is($tokens[$last + 1] ?? ['', '', ''], 'ALL');
            $part1 = array_slice($tokens, 0, $last);
            $part2 = array_slice($tokens, $last + ($all ? 2 : 1));
            $orderBy = [];
            $orderPositions = self::topLevel($part2, ['ORDER']);
            if (!empty($orderPositions)) {
                $orderBy = array_slice($part2, end($orderPositions));
                $part2 = array_slice($part2, 0, end($orderPositions));
            }
            $rows = [
                [self::ATTRIBUTE_TYPE, null, self::TYPE_UNION, null, null],
                [self::ATTRIBUTE_FLAG, null, $all ? 0 : self::UNION_DISTINCT, null, null],
                [self::ATTRIBUTE_TABLE, self::render($part1), null, null, self::UNION_PART1],
                [self::ATTRIBUTE_TABLE, self::render($part2), null, null, self::UNION_PART2],
            ];
            if (!empty($orderBy)) {
                $items = self::orderItems(array_slice($orderBy, 2));
                if (null === $items) {
                    return null;
                }
                array_push($rows, ...$items);
            }

            return ['flags' => self::OBJECT_FLAG_UNION, 'rows' => $rows];
        }

        $flags = 0;
        $top = null;
        $position = 1;
        if (Dialect::is($tokens[1] ?? ['', '', ''], 'DISTINCT')) {
            $flags |= self::DISTINCT;
            ++$position;
        } elseif (Dialect::is($tokens[1] ?? ['', '', ''], 'ALL')) {
            ++$position;
        }
        if (Dialect::is($tokens[$position] ?? ['', '', ''], 'TOP') && Dialect::T_NUMBER === ($tokens[$position + 1][0] ?? '')) {
            $top = $tokens[$position + 1][1];
            $position += 2;
            if (Dialect::is($tokens[$position] ?? ['', '', ''], 'PERCENT')) {
                $flags |= self::PERCENT;
                ++$position;
            }
        }
        $tokens = array_slice($tokens, $position);
        // LIMIT n at the end => TOP n
        $count = count($tokens);
        if ($count >= 2 && Dialect::is($tokens[$count - 2], 'LIMIT') && Dialect::T_NUMBER === $tokens[$count - 1][0]) {
            $top = $tokens[$count - 1][1];
            $tokens = array_slice($tokens, 0, $count - 2);
        }
        if (!empty(self::topLevel($tokens, ['LIMIT', 'OFFSET', 'FETCH', 'INTO']))) {
            return null;
        }
        if (null !== $top) {
            $flags |= self::TOP;
        }
        $clauses = self::clauses($tokens);
        if (null === $clauses) {
            return null;
        }
        $rows = [[self::ATTRIBUTE_TYPE, null, self::TYPE_SELECT, null, null]];
        $columnRows = [];
        foreach (self::split($clauses['SELECT'], ',') as $item) {
            if (1 === count($item) && Dialect::is($item[0], '*')) {
                $flags |= self::SELECT_STAR;

                continue;
            }
            [$expression, $alias] = self::alias($item);
            if (empty($expression)) {
                return null;
            }
            $columnRows[] = [self::ATTRIBUTE_COLUMN, self::render($expression), 0, $alias, null];
        }
        $rows[] = [self::ATTRIBUTE_FLAG, null, $flags, $top, null];
        if (isset($clauses['FROM'])) {
            $from = self::fromRows($clauses['FROM']);
            if (null === $from) {
                return null;
            }
            array_push($rows, ...$from['tables']);
            array_push($rows, ...$columnRows);
            array_push($rows, ...$from['joins']);
        } else {
            array_push($rows, ...$columnRows);
        }
        if (isset($clauses['WHERE'])) {
            $rows[] = [self::ATTRIBUTE_WHERE, self::render($clauses['WHERE']), null, null, null];
        }
        foreach (isset($clauses['GROUP']) ? self::split(array_slice($clauses['GROUP'], 1), ',') : [] as $item) {
            $rows[] = [self::ATTRIBUTE_GROUP_BY, self::render($item), 0, null, null];
        }
        if (isset($clauses['HAVING'])) {
            $rows[] = [self::ATTRIBUTE_HAVING, self::render($clauses['HAVING']), null, null, null];
        }
        if (isset($clauses['ORDER'])) {
            $items = self::orderItems(array_slice($clauses['ORDER'], 1));
            if (null === $items) {
                return null;
            }
            array_push($rows, ...$items);
        }

        return ['flags' => self::OBJECT_FLAG_SELECT, 'rows' => $rows];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private static function unionSql(array $rows): ?string
    {
        $parts = [];
        foreach (self::all($rows, self::ATTRIBUTE_TABLE) as $row) {
            $parts[(string) $row['Name2']] = trim((string) preg_replace('/[\r\n]+/', "\n", (string) $row['Expression']));
        }
        if (!isset($parts[self::UNION_PART1], $parts[self::UNION_PART2])) {
            return null;
        }
        $flags = (int) (self::first($rows, self::ATTRIBUTE_FLAG)['Flag'] ?? 0);

        return $parts[self::UNION_PART1] . "\nUNION " . (0 !== ($flags & self::UNION_DISTINCT) ? '' : 'ALL ') . $parts[self::UNION_PART2] . self::orderBy($rows);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private static function orderBy(array $rows): string
    {
        $orders = [];
        foreach (self::all($rows, self::ATTRIBUTE_ORDER_BY) as $row) {
            $orders[] = $row['Expression'] . (0 === strcasecmp((string) $row['Name1'], 'D') ? ' DESC' : '');
        }

        return empty($orders) ? '' : "\nORDER BY " . implode(', ', $orders);
    }

    /**
     * FROM clause: tables, then the joins nested like Access does (Jackcess, QueryImpl.getFromTables()).
     *
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<int, string>
     */
    private static function fromTables(array $rows): array
    {
        // Sources: [table keys (lower case), SQL, join type, on conditions]
        $sources = [];
        foreach (self::all($rows, self::ATTRIBUTE_TABLE) as $row) {
            $sql = '';
            if (null !== $row['Expression']) {
                $sql .= self::quote((string) $row['Expression']) . '.';
            }
            if (null !== $row['Name1']) {
                $sql .= self::quoteOptional((string) $row['Name1'], true);
            }
            if (null !== $row['Name2']) {
                $sql .= ' AS ' . self::quoteOptional((string) $row['Name2'], false);
            }
            $sources[] = ['keys' => [strtolower((string) ($row['Name2'] ?? $row['Name1']))], 'sql' => $sql, 'type' => 0, 'on' => [], 'from' => null, 'to' => null];
        }
        foreach (self::all($rows, self::ATTRIBUTE_JOIN) as $row) {
            $fromName = strtolower((string) $row['Name1']);
            $toName = strtolower((string) $row['Name2']);
            $from = null;
            $to = null;
            foreach ($sources as $index => $source) {
                if (null === $from && in_array($fromName, $source['keys'], true)) {
                    $from = $index;
                    if (null === $to && in_array($toName, $source['keys'], true)) {
                        $to = $index;

                        break;
                    }
                } elseif (null === $to && in_array($toName, $source['keys'], true)) {
                    $to = $index;
                }
            }
            $fromSource = null === $from ? ['keys' => [$fromName], 'sql' => self::quoteOptional((string) $row['Name1'], true), 'type' => 0, 'on' => [], 'from' => null, 'to' => null] : $sources[$from];
            $toSource = null === $to ? ['keys' => [$toName], 'sql' => self::quoteOptional((string) $row['Name2'], true), 'type' => 0, 'on' => [], 'from' => null, 'to' => null] : $sources[$to];
            if (null !== $from && $from === $to) {
                // Same join: one more condition
                if ($sources[$from]['type'] === (int) $row['Flag']) {
                    array_unshift($sources[$from]['on'], (string) $row['Expression']);
                }

                continue;
            }
            foreach ([$from, $to] as $index) {
                if (null !== $index) {
                    unset($sources[$index]);
                }
            }
            $sources = array_values($sources);
            $sources[] = ['keys' => array_merge($fromSource['keys'], $toSource['keys']), 'sql' => '', 'type' => (int) $row['Flag'], 'on' => [(string) $row['Expression']], 'from' => $fromSource, 'to' => $toSource];
        }

        return array_map(static fn (array $source): string => self::sourceSql($source, true), $sources);
    }

    /**
     * @param array<string, mixed> $source
     */
    private static function sourceSql(array $source, bool $topLevel): string
    {
        if (0 === $source['type']) {
            return $source['sql'];
        }
        $on = count($source['on']) > 1 ? '((' . implode(') AND (', $source['on']) . '))' : $source['on'][0];
        $sql = self::sourceSql($source['from'], false) . ' ' . (self::JOIN_TYPES[$source['type']] ?? 'INNER JOIN') . ' ' . self::sourceSql($source['to'], false) . ' ON ' . $on;

        return $topLevel ? $sql : '(' . $sql . ')';
    }

    /**
     * @param array<int, array{0: string, 1: string, 2: string}> $tokens FROM clause (without FROM)
     *
     * @return null|array{tables: array<int, array{0: int, 1: null|string, 2: null|int, 3: null|string, 4: null|string}>, joins: array<int, array{0: int, 1: null|string, 2: null|int, 3: null|string, 4: null|string}>}
     */
    private static function fromRows(array $tokens): ?array
    {
        $tables = [];
        $joins = [];
        $position = 0;
        $count = count($tokens);
        while ($position < $count) {
            $item = self::parseSource($tokens, $position, $tables, $joins);
            if (null === $item) {
                return null;
            }
            if ($position < $count) {
                if (!Dialect::is($tokens[$position], ',') && !(Dialect::is($tokens[$position], 'CROSS') && Dialect::is($tokens[$position + 1] ?? ['', '', ''], 'JOIN'))) {
                    return null;
                }
                $position += Dialect::is($tokens[$position], ',') ? 1 : 2;
            }
        }

        return ['tables' => $tables, 'joins' => $joins];
    }

    /**
     * Table or join tree; returns the keys (alias or name) of its tables.
     *
     * @param array<int, array{0: string, 1: string, 2: string}> $tokens
     * @param array<int, array{0: int, 1: null|string, 2: null|int, 3: null|string, 4: null|string}> $tables
     * @param array<int, array{0: int, 1: null|string, 2: null|int, 3: null|string, 4: null|string}> $joins
     *
     * @return null|array<int, string>
     */
    private static function parseSource(array $tokens, int &$position, array &$tables, array &$joins): ?array
    {
        $keys = self::parsePrimarySource($tokens, $position, $tables, $joins);
        if (null === $keys) {
            return null;
        }
        while (isset($tokens[$position])) {
            $token = $tokens[$position];
            $type = null;
            if (Dialect::is($token, 'JOIN', 'INNER')) {
                $type = 1;
            } elseif (Dialect::is($token, 'LEFT')) {
                $type = 2;
            } elseif (Dialect::is($token, 'RIGHT')) {
                $type = 3;
            } else {
                break;
            }
            while (isset($tokens[$position]) && !Dialect::is($tokens[$position], 'JOIN')) {
                if (!Dialect::is($tokens[$position], 'INNER', 'LEFT', 'RIGHT', 'OUTER')) {
                    return null;
                }
                ++$position;
            }
            ++$position;
            $right = self::parsePrimarySource($tokens, $position, $tables, $joins);
            if (null === $right || !Dialect::is($tokens[$position] ?? ['', '', ''], 'ON')) {
                return null;
            }
            ++$position;
            $start = $position;
            $depth = 0;
            while (isset($tokens[$position])) {
                $current = $tokens[$position];
                if (Dialect::is($current, '(')) {
                    ++$depth;
                } elseif (Dialect::is($current, ')')) {
                    if (0 === $depth) {
                        break;
                    }
                    --$depth;
                } elseif (0 === $depth && Dialect::is($current, ',', 'JOIN', 'INNER', 'LEFT', 'RIGHT', 'CROSS')) {
                    break;
                }
                ++$position;
            }
            $condition = array_slice($tokens, $start, $position - $start);
            if (empty($condition)) {
                return null;
            }
            $joins[] = [self::ATTRIBUTE_JOIN, self::render($condition), $type, self::joinKey($keys, $condition), self::joinKey($right, $condition)];
            $keys = array_merge($keys, $right);
        }

        return $keys;
    }

    /**
     * @param array<int, array{0: string, 1: string, 2: string}> $tokens
     * @param array<int, array{0: int, 1: null|string, 2: null|int, 3: null|string, 4: null|string}> $tables
     * @param array<int, array{0: int, 1: null|string, 2: null|int, 3: null|string, 4: null|string}> $joins
     *
     * @return null|array<int, string>
     */
    private static function parsePrimarySource(array $tokens, int &$position, array &$tables, array &$joins): ?array
    {
        $token = $tokens[$position] ?? null;
        if (null === $token) {
            return null;
        }
        if (Dialect::is($token, '(')) {
            if (Dialect::is($tokens[$position + 1] ?? ['', '', ''], 'SELECT')) {
                // Subquery
                return null;
            }
            ++$position;
            $keys = self::parseSource($tokens, $position, $tables, $joins);
            if (null === $keys || !Dialect::is($tokens[$position] ?? ['', '', ''], ')')) {
                return null;
            }
            ++$position;

            return $keys;
        }
        if (Dialect::T_IDENTIFIER !== $token[0] && (Dialect::T_WORD !== $token[0] || Dialect::isKeyword($token[1]))) {
            return null;
        }
        $name = $token[1];
        ++$position;
        while (Dialect::is($tokens[$position] ?? ['', '', ''], '.') && isset($tokens[$position + 1])) {
            $name = $tokens[$position + 1][1];
            $position += 2;
        }
        $alias = null;
        if (Dialect::is($tokens[$position] ?? ['', '', ''], 'AS')) {
            ++$position;
        }
        $next = $tokens[$position] ?? null;
        if (null !== $next && (Dialect::T_IDENTIFIER === $next[0] || (Dialect::T_WORD === $next[0] && !Dialect::isKeyword($next[1])))) {
            $alias = $next[1];
            ++$position;
        }
        $tables[] = [self::ATTRIBUTE_TABLE, null, null, $name, $alias];

        return [$alias ?? $name];
    }

    /**
     * Table of a join tree named in the condition (else the last one).
     *
     * @param array<int, string> $keys
     * @param array<int, array{0: string, 1: string, 2: string}> $condition
     */
    private static function joinKey(array $keys, array $condition): string
    {
        foreach ($condition as $index => $token) {
            if ((Dialect::T_IDENTIFIER === $token[0] || Dialect::T_WORD === $token[0]) && Dialect::is($condition[$index + 1] ?? ['', '', ''], '.')) {
                foreach ($keys as $key) {
                    if (0 === strcasecmp($key, $token[1])) {
                        return $key;
                    }
                }
            }
        }

        return (string) end($keys);
    }

    /**
     * @param array<int, array{0: string, 1: string, 2: string}> $tokens
     *
     * @return null|array<int, array{0: int, 1: null|string, 2: null|int, 3: null|string, 4: null|string}>
     */
    private static function orderItems(array $tokens): ?array
    {
        $rows = [];
        foreach (self::split($tokens, ',') as $item) {
            $descending = false;
            $last = end($item);
            if (false !== $last && Dialect::is($last, 'ASC', 'DESC')) {
                $descending = Dialect::is($last, 'DESC');
                array_pop($item);
            }
            if (empty($item)) {
                return null;
            }
            $rows[] = [self::ATTRIBUTE_ORDER_BY, self::render($item), null, $descending ? 'D' : null, null];
        }

        return $rows;
    }

    /**
     * Clauses of a SELECT (tokens after SELECT [DISTINCT] [TOP n]): SELECT (columns), FROM, WHERE,
     * GROUP (GROUP BY ...), HAVING, ORDER (ORDER BY ...).
     *
     * @param array<int, array{0: string, 1: string, 2: string}> $tokens
     *
     * @return null|array<string, array<int, array{0: string, 1: string, 2: string}>>
     */
    private static function clauses(array $tokens): ?array
    {
        $clauses = [];
        $current = 'SELECT';
        $start = 0;
        foreach (self::topLevel($tokens, ['FROM', 'WHERE', 'GROUP', 'HAVING', 'ORDER']) as $position) {
            $clauses[$current] = array_slice($tokens, $start, $position - $start);
            $current = strtoupper($tokens[$position][1]);
            if (isset($clauses[$current])) {
                return null;
            }
            $start = $position + 1;
        }
        $clauses[$current] = array_slice($tokens, $start);

        return $clauses;
    }

    /**
     * Positions of the keywords outside of parentheses.
     *
     * @param array<int, array{0: string, 1: string, 2: string}> $tokens
     * @param array<int, string> $keywords
     *
     * @return array<int, int>
     */
    private static function topLevel(array $tokens, array $keywords): array
    {
        $positions = [];
        $depth = 0;
        foreach ($tokens as $index => $token) {
            if (Dialect::is($token, '(')) {
                ++$depth;
            } elseif (Dialect::is($token, ')')) {
                --$depth;
            } elseif (0 === $depth && Dialect::T_WORD === $token[0] && Dialect::is($token, ...$keywords)) {
                $positions[] = $index;
            }
        }

        return $positions;
    }

    /**
     * @param array<int, array{0: string, 1: string, 2: string}> $tokens
     *
     * @return array<int, array<int, array{0: string, 1: string, 2: string}>>
     */
    private static function split(array $tokens, string $separator): array
    {
        $items = [];
        $current = [];
        $depth = 0;
        foreach ($tokens as $token) {
            if (Dialect::is($token, '(')) {
                ++$depth;
            } elseif (Dialect::is($token, ')')) {
                --$depth;
            } elseif (0 === $depth && Dialect::is($token, $separator)) {
                $items[] = $current;
                $current = [];

                continue;
            }
            $current[] = $token;
        }
        if (!empty($current)) {
            $items[] = $current;
        }

        return $items;
    }

    /**
     * Expression and alias of a column of the select list.
     *
     * @param array<int, array{0: string, 1: string, 2: string}> $item
     *
     * @return array{0: array<int, array{0: string, 1: string, 2: string}>, 1: null|string}
     */
    private static function alias(array $item): array
    {
        $count = count($item);
        if ($count >= 3 && Dialect::is($item[$count - 2], 'AS')) {
            return [array_slice($item, 0, $count - 2), $item[$count - 1][1]];
        }
        if ($count >= 2) {
            $last = $item[$count - 1];
            $previous = $item[$count - 2];
            $isName = Dialect::T_IDENTIFIER === $last[0] || (Dialect::T_WORD === $last[0] && !Dialect::isKeyword($last[1]));
            $isOperand = in_array($previous[0], [Dialect::T_IDENTIFIER, Dialect::T_NUMBER, Dialect::T_STRING, Dialect::T_DATE], true)
                || (Dialect::T_WORD === $previous[0] && !Dialect::isKeyword($previous[1])) || Dialect::is($previous, ')');
            if ($isName && $isOperand && !Dialect::is($item[$count - 2], '.')) {
                return [array_slice($item, 0, $count - 1), $last[1]];
            }
        }

        return [$item, null];
    }

    /**
     * @param array<int, array{0: string, 1: string, 2: string}> $tokens
     */
    private static function render(array $tokens): string
    {
        if (!empty($tokens)) {
            $tokens[0][2] = '';
        }

        return trim(Dialect::render($tokens, Dialect::ACCESS));
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<int, array<string, mixed>>
     */
    private static function all(array $rows, int $attribute): array
    {
        return array_values(array_filter($rows, static fn (array $row): bool => (((int) $row['Attribute']) & 0xFF) === $attribute));
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<string, mixed>
     */
    private static function first(array $rows, int $attribute): array
    {
        return self::all($rows, $attribute)[0] ?? [];
    }

    private static function quote(string $expression): string
    {
        return str_starts_with($expression, '[') && str_ends_with($expression, ']') ? $expression : '[' . $expression . ']';
    }

    private static function quoteOptional(string $expression, bool $identifier): string
    {
        $parts = $identifier ? explode('.', $expression) : [$expression];

        return implode('.', array_map(static fn (string $part): string => 1 === preg_match('/\W/u', $part) ? self::quote($part) : $part, $parts));
    }
}
