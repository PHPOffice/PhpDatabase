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

namespace PhpOffice\PhpDatabase\Shared\Sql;

/**
 * Conversion of SQL statements and expressions (views, check constraints, defaults...) between the
 * dialects of the formats and the "standard" SQL of the object model:
 *
 * - standard: "identifier", 'string', a || b, DATE '2024-01-31', CURRENT_DATE, LIMIT n;
 * - SQLite: also `identifier` and [identifier];
 * - HSQLDB 1.8: unquoted identifiers are upper case: the writer quotes them all;
 * - Access: [identifier], "string", a & b, #2024-01-31#, Date(), Now(), SELECT TOP n, Like "a*".
 *
 * The conversion works on tokens and keeps the layout (spaces, comments) of the statement.
 * Functions specific to an engine (IIf, Nz, strftime...) are kept as is.
 */
final class Dialect
{
    public const STANDARD = 'standard';
    public const SQLITE = 'sqlite';
    public const HSQLDB = 'hsqldb';
    public const ACCESS = 'access';

    public const T_WORD = 'word';
    public const T_IDENTIFIER = 'identifier';
    public const T_STRING = 'string';
    public const T_NUMBER = 'number';
    public const T_SYMBOL = 'symbol';
    /** Access date literal (#...#) */
    public const T_DATE = 'date';

    /**
     * Words which are not identifiers (keywords, constants).
     */
    private const KEYWORDS = [
        'ALL', 'AND', 'ANY', 'AS', 'ASC', 'BETWEEN', 'BY', 'CASE', 'CAST', 'COLLATE', 'CROSS', 'CURRENT_DATE',
        'CURRENT_TIME', 'CURRENT_TIMESTAMP', 'DATE', 'DEFAULT', 'DESC', 'DISTINCT', 'DISTINCTROW', 'ELSE', 'END',
        'ESCAPE', 'EXCEPT', 'EXISTS', 'FALSE', 'FETCH', 'FIRST', 'FROM', 'FULL', 'GLOB', 'GROUP', 'HAVING', 'IN',
        'INNER', 'INTERSECT', 'INTERVAL', 'IS', 'ISNULL', 'JOIN', 'LAST', 'LEFT', 'LIKE', 'LIMIT', 'MOD', 'NATURAL',
        'NOT', 'NOTNULL', 'NULL', 'NULLS', 'OFFSET', 'ON', 'OR', 'ORDER', 'OUTER', 'PERCENT', 'RIGHT', 'ROWS',
        'SELECT', 'SOME', 'THEN', 'TIME', 'TIMESTAMP', 'TOP', 'TRUE', 'UNION', 'UNKNOWN', 'USING', 'VALUES', 'WHEN',
        'WHERE', 'WITH', 'XOR',
    ];

    /**
     * @return array<int, array{0: string, 1: string, 2: string}> tokens: type, value (unquoted), spaces before
     */
    public static function tokenize(string $sql, string $dialect): array
    {
        $tokens = [];
        $length = strlen($sql);
        $position = 0;
        $space = '';
        while ($position < $length) {
            $char = $sql[$position];
            $next = $sql[$position + 1] ?? '';
            if (ctype_space($char)) {
                $space .= $char;
                ++$position;

                continue;
            }
            if ('-' === $char && '-' === $next) {
                $end = strpos($sql, "\n", $position);
                $end = false === $end ? $length : $end;
                $space .= substr($sql, $position, $end - $position);
                $position = $end;

                continue;
            }
            if ('/' === $char && '*' === $next) {
                $end = strpos($sql, '*/', $position + 2);
                $end = false === $end ? $length : $end + 2;
                $space .= substr($sql, $position, $end - $position);
                $position = $end;

                continue;
            }
            if ("'" === $char || ('"' === $char && self::ACCESS === $dialect)) {
                [$value, $position] = self::readQuoted($sql, $position, $char);
                $tokens[] = [self::T_STRING, $value, $space];
            } elseif ('"' === $char || ('`' === $char && self::ACCESS !== $dialect)) {
                [$value, $position] = self::readQuoted($sql, $position, $char);
                $tokens[] = [self::T_IDENTIFIER, $value, $space];
            } elseif ('[' === $char && self::HSQLDB !== $dialect) {
                $end = strpos($sql, ']', $position);
                $end = false === $end ? $length : $end;
                $tokens[] = [self::T_IDENTIFIER, substr($sql, $position + 1, $end - $position - 1), $space];
                $position = $end + 1;
            } elseif ('#' === $char && self::ACCESS === $dialect && 1 === preg_match('/\G#([0-9\/\-:. apmAPM]+)#/A', $sql, $match, 0, $position)) {
                $tokens[] = [self::T_DATE, trim($match[1]), $space];
                $position += strlen($match[0]);
            } elseif ((ctype_digit($char) || ('.' === $char && ctype_digit($next))) && 1 === preg_match('/\G(?:0x[0-9a-f]+|(?:\d+\.?\d*|\.\d+)(?:e[+-]?\d+)?)/Ai', $sql, $match, 0, $position)) {
                $tokens[] = [self::T_NUMBER, $match[0], $space];
                $position += strlen($match[0]);
            } elseif (1 === preg_match('/\G[A-Za-z_\x80-\xff][A-Za-z0-9_$\x80-\xff]*/A', $sql, $match, 0, $position)) {
                $tokens[] = [self::T_WORD, $match[0], $space];
                $position += strlen($match[0]);
            } elseif (1 === preg_match('/\G(?:<>|<=|>=|!=|==|\|\|)/A', $sql, $match, 0, $position)) {
                $tokens[] = [self::T_SYMBOL, $match[0], $space];
                $position += 2;
            } else {
                $tokens[] = [self::T_SYMBOL, $char, $space];
                ++$position;
            }
            $space = '';
        }
        if ('' !== $space && !empty($tokens)) {
            // Trailing spaces/comments are dropped
            $space = '';
        }

        return $tokens;
    }

    /**
     * Convert a statement or an expression from a dialect to another.
     */
    public static function convert(string $sql, string $from, string $to): string
    {
        $sql = trim($sql);
        if ($from === $to || '' === $sql) {
            return rtrim($sql, '; ');
        }
        $tokens = self::tokenize($sql, $from);
        while (!empty($tokens) && self::is($tokens[count($tokens) - 1], ';')) {
            array_pop($tokens);
        }

        return trim(self::render(self::transform($tokens, $from, $to), $to));
    }

    /**
     * Convert tokens from a dialect to another.
     *
     * @param array<int, array{0: string, 1: string, 2: string}> $tokens
     * @param bool $statement convert TOP/LIMIT (only for complete SELECT statements)
     *
     * @return array<int, array{0: string, 1: string, 2: string}>
     */
    public static function transform(array $tokens, string $from, string $to, bool $statement = true): array
    {
        if ($from === $to) {
            return $tokens;
        }
        if (self::ACCESS === $from) {
            $tokens = self::fromAccess($tokens, $statement);
        } elseif (self::HSQLDB === $from && $statement && self::isSingleSelect($tokens)) {
            $tokens = self::fromHsqldb($tokens);
        } elseif (self::SQLITE === $from) {
            foreach ($tokens as $index => $token) {
                if (self::is($token, '==')) {
                    $tokens[$index][1] = '=';
                }
            }
        }
        switch ($to) {
            case self::ACCESS:
                return self::toAccess($tokens, $statement);
            case self::HSQLDB:
                return self::toHsqldb($tokens);
            case self::SQLITE:
                $result = [];
                foreach ($tokens as $index => $token) {
                    // No date literals in SQLite: dates are ISO 8601 strings
                    if (self::T_WORD === $token[0] && self::is($token, 'DATE', 'TIME', 'TIMESTAMP') && self::T_STRING === ($tokens[$index + 1][0] ?? '')) {
                        $tokens[$index + 1][2] = $token[2];

                        continue;
                    }
                    $result[] = $token;
                }

                return $result;
            default:
                return $tokens;
        }
    }

    /**
     * @param array<int, array{0: string, 1: string, 2: string}> $tokens
     */
    public static function render(array $tokens, string $dialect): string
    {
        $sql = '';
        foreach ($tokens as $token) {
            $sql .= $token[2];
            switch ($token[0]) {
                case self::T_IDENTIFIER:
                    $sql .= self::ACCESS === $dialect ? '[' . $token[1] . ']' : self::quoteIdentifier($token[1]);

                    break;
                case self::T_STRING:
                    $sql .= self::ACCESS === $dialect ? '"' . str_replace('"', '""', $token[1]) . '"' : "'" . str_replace("'", "''", $token[1]) . "'";

                    break;
                case self::T_DATE:
                    $sql .= '#' . $token[1] . '#';

                    break;
                default:
                    $sql .= $token[1];
            }
        }

        return $sql;
    }

    /**
     * Can the statement be written in a schema without breaking it? A SELECT (or WITH) statement with
     * balanced parentheses and without syntax specific to Access (parameters, Forms!Control...).
     */
    public static function isPortableSelect(string $sql): bool
    {
        $tokens = self::tokenize($sql, self::STANDARD);
        if (empty($tokens) || !self::is($tokens[0], 'SELECT', 'WITH', 'VALUES')) {
            return false;
        }
        $depth = 0;
        foreach ($tokens as $token) {
            if (self::is($token, '(')) {
                ++$depth;
            } elseif (self::is($token, ')')) {
                if (--$depth < 0) {
                    return false;
                }
            } elseif (self::is($token, '!', '?', '#', '&', '[', ']', ';', 'TRANSFORM', 'PIVOT', 'PARAMETERS', 'DISTINCTROW')) {
                return false;
            }
        }

        return 0 === $depth;
    }

    public static function quoteIdentifier(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }

    /**
     * Is the token the word or symbol given (case insensitive)?
     *
     * @param array{0: string, 1: string, 2: string} $token
     */
    public static function is(array $token, string ...$values): bool
    {
        if (self::T_WORD !== $token[0] && self::T_SYMBOL !== $token[0]) {
            return false;
        }
        foreach ($values as $value) {
            if (0 === strcasecmp($token[1], $value)) {
                return true;
            }
        }

        return false;
    }

    public static function isKeyword(string $word): bool
    {
        return in_array(strtoupper($word), self::KEYWORDS, true);
    }

    /**
     * @param array<int, array{0: string, 1: string, 2: string}> $tokens
     *
     * @return array<int, array{0: string, 1: string, 2: string}>
     */
    private static function fromAccess(array $tokens, bool $statement): array
    {
        $functions = ['DATE' => 'CURRENT_DATE', 'NOW' => 'CURRENT_TIMESTAMP', 'TIME' => 'CURRENT_TIME'];
        $result = [];
        $count = count($tokens);
        for ($index = 0; $index < $count; ++$index) {
            $token = $tokens[$index];
            if (self::T_DATE === $token[0]) {
                [$type, $value] = self::parseAccessDate($token[1]);
                $result[] = [self::T_WORD, $type, $token[2]];
                $result[] = [self::T_STRING, $value, ' '];

                continue;
            }
            if (self::is($token, '&')) {
                $token[1] = '||';
            } elseif (self::T_WORD === $token[0] && self::is($token, 'DISTINCTROW')) {
                $token[1] = 'DISTINCT';
            } elseif (self::T_WORD === $token[0] && self::is($token, 'MOD') && !self::is($tokens[$index + 1] ?? ['', '', ''], '(')) {
                $token = [self::T_SYMBOL, '%', $token[2]];
            } elseif (self::T_WORD === $token[0] && isset($functions[strtoupper($token[1])]) && self::is($tokens[$index + 1] ?? ['', '', ''], '(') && self::is($tokens[$index + 2] ?? ['', '', ''], ')')) {
                $token[1] = $functions[strtoupper($token[1])];
                $index += 2;
            } elseif (self::T_STRING === $token[0] && !empty($result) && self::is($result[count($result) - 1], 'LIKE') && !str_contains($token[1], '%')) {
                $token[1] = strtr($token[1], ['*' => '%', '?' => '_']);
            }
            $result[] = $token;
        }
        if ($statement) {
            $result = self::moveTop($result);
        }

        return $result;
    }

    /**
     * HSQLDB: SELECT LIMIT offset limit ... / SELECT TOP n ... => LIMIT n [OFFSET m] at the end.
     *
     * @param array<int, array{0: string, 1: string, 2: string}> $tokens
     *
     * @return array<int, array{0: string, 1: string, 2: string}>
     */
    private static function fromHsqldb(array $tokens): array
    {
        if (self::is($tokens[1] ?? ['', '', ''], 'LIMIT') && self::T_NUMBER === ($tokens[2][0] ?? '') && self::T_NUMBER === ($tokens[3][0] ?? '')) {
            [$offset, $limit] = [$tokens[2][1], $tokens[3][1]];
            array_splice($tokens, 1, 3);
            $tokens[] = [self::T_WORD, 'LIMIT', ' '];
            $tokens[] = [self::T_NUMBER, $limit, ' '];
            if ('0' !== $offset) {
                $tokens[] = [self::T_WORD, 'OFFSET', ' '];
                $tokens[] = [self::T_NUMBER, $offset, ' '];
            }
        } elseif (self::is($tokens[1] ?? ['', '', ''], 'TOP') && self::T_NUMBER === ($tokens[2][0] ?? '')) {
            $limit = $tokens[2][1];
            array_splice($tokens, 1, 2);
            $tokens[] = [self::T_WORD, 'LIMIT', ' '];
            $tokens[] = [self::T_NUMBER, $limit, ' '];
        }

        return $tokens;
    }

    /**
     * Access dates: #m/d/yyyy#, #yyyy-mm-dd#, with an optional time.
     *
     * @return array{0: string, 1: string}
     */
    private static function parseAccessDate(string $value): array
    {
        $value = trim($value);
        $date = null;
        $time = null;
        if (1 === preg_match('#^(\d{1,2})/(\d{1,2})/(\d{2,4})#', $value, $match)) {
            $year = (int) $match[3];
            $year += $year < 100 ? ($year < 30 ? 2000 : 1900) : 0;
            $date = sprintf('%04d-%02d-%02d', $year, (int) $match[1], (int) $match[2]);
        } elseif (1 === preg_match('#^(\d{4})-(\d{1,2})-(\d{1,2})#', $value, $match)) {
            $date = sprintf('%04d-%02d-%02d', (int) $match[1], (int) $match[2], (int) $match[3]);
        }
        if (1 === preg_match('#(\d{1,2}):(\d{2})(?::(\d{2}))?\s*([ap]m)?\s*$#i', $value, $match)) {
            $hour = (int) $match[1];
            if (!empty($match[4])) {
                $hour = $hour % 12 + (0 === strcasecmp($match[4], 'pm') ? 12 : 0);
            }
            $time = sprintf('%02d:%02d:%02d', $hour, (int) $match[2], (int) ($match[3] ?? 0));
        }
        if (null !== $date && null !== $time) {
            return ['TIMESTAMP', $date . ' ' . $time];
        }
        if (null !== $time) {
            return ['TIME', $time];
        }

        return ['DATE', $date ?? $value];
    }

    /**
     * @param array<int, array{0: string, 1: string, 2: string}> $tokens
     *
     * @return array<int, array{0: string, 1: string, 2: string}>
     */
    private static function toAccess(array $tokens, bool $statement): array
    {
        $functions = ['CURRENT_DATE' => 'Date', 'CURRENT_TIMESTAMP' => 'Now', 'CURRENT_TIME' => 'Time'];
        $result = [];
        $count = count($tokens);
        for ($index = 0; $index < $count; ++$index) {
            $token = $tokens[$index];
            if (self::T_WORD === $token[0] && self::is($token, 'DATE', 'TIME', 'TIMESTAMP') && self::T_STRING === ($tokens[$index + 1][0] ?? '')) {
                $result[] = [self::T_DATE, $tokens[$index + 1][1], $token[2]];
                ++$index;

                continue;
            }
            if (self::T_WORD === $token[0] && isset($functions[strtoupper($token[1])])) {
                $result[] = [self::T_WORD, $functions[strtoupper($token[1])], $token[2]];
                $result[] = [self::T_SYMBOL, '(', ''];
                $result[] = [self::T_SYMBOL, ')', ''];

                continue;
            }
            if (self::is($token, '||')) {
                $token[1] = '&';
            } elseif (self::is($token, '!=')) {
                $token[1] = '<>';
            } elseif (self::is($token, '%')) {
                $token = [self::T_WORD, 'Mod', ' ' === $token[2] || '' === $token[2] ? ' ' : $token[2]];
            } elseif (self::T_STRING === $token[0] && !empty($result) && self::is($result[count($result) - 1], 'LIKE')) {
                $token[1] = strtr($token[1], ['%' => '*', '_' => '?']);
            }
            $result[] = $token;
        }
        if ($statement) {
            $result = self::moveLimit($result);
        }

        return $result;
    }

    /**
     * HSQLDB folds unquoted identifiers to upper case: quote them to keep their case.
     *
     * @param array<int, array{0: string, 1: string, 2: string}> $tokens
     *
     * @return array<int, array{0: string, 1: string, 2: string}>
     */
    private static function toHsqldb(array $tokens): array
    {
        $castDepths = [];
        $depth = 0;
        foreach ($tokens as $index => $token) {
            if (self::is($token, '(')) {
                ++$depth;

                continue;
            }
            if (self::is($token, ')')) {
                if (end($castDepths) === $depth) {
                    array_pop($castDepths);
                }
                --$depth;

                continue;
            }
            if (self::T_WORD !== $token[0]) {
                continue;
            }
            $next = $tokens[$index + 1] ?? ['', '', ''];
            if (self::is($token, 'CAST', 'CONVERT') && self::is($next, '(')) {
                $castDepths[] = $depth + 1;

                continue;
            }
            $previous = $tokens[$index - 1] ?? ['', '', ''];
            if (!empty($castDepths) && self::is($previous, 'AS')) {
                // Types of CAST: names of HSQLDB
                $types = ['TEXT' => 'VARCHAR', 'REAL' => 'DOUBLE', 'BLOB' => 'LONGVARBINARY', 'CLOB' => 'LONGVARCHAR'];
                $tokens[$index][1] = $types[strtoupper($token[1])] ?? $token[1];

                continue;
            }
            if (self::isKeyword($token[1]) || self::is($next, '(') || (!empty($castDepths) && self::is($previous, ','))) {
                continue;
            }
            $tokens[$index][0] = self::T_IDENTIFIER;
        }

        // LIMIT n OFFSET m => SELECT LIMIT m n
        $count = count($tokens);
        if ($count > 4 && self::isSingleSelect($tokens) && self::is($tokens[$count - 4], 'LIMIT') && self::is($tokens[$count - 2], 'OFFSET')
            && self::T_NUMBER === $tokens[$count - 3][0] && self::T_NUMBER === $tokens[$count - 1][0]) {
            $limit = $tokens[$count - 3][1];
            $offset = $tokens[$count - 1][1];
            array_splice($tokens, $count - 4, 4);
            array_splice($tokens, 1, 0, [[self::T_WORD, 'LIMIT', ' '], [self::T_NUMBER, $offset, ' '], [self::T_NUMBER, $limit, ' ']]);
        }

        return $tokens;
    }

    /**
     * Problem preventing an expression or a SELECT statement (already converted to HSQLDB) from being
     * loaded by HSQLDB 1.8, which refuses to open a database with an invalid script; null if none found.
     */
    /**
     * Is there a FROM in the SELECT starting at this position (at the same level of parentheses)?
     *
     * @param array<int, array{0: string, 1: string, 2: string}> $tokens
     */
    private static function hasFrom(array $tokens, int $select): bool
    {
        $depth = 0;
        $count = count($tokens);
        for ($index = $select + 1; $index < $count; ++$index) {
            if (self::is($tokens[$index], '(')) {
                ++$depth;
            } elseif (self::is($tokens[$index], ')')) {
                if (--$depth < 0) {
                    return false;
                }
            } elseif (0 === $depth && self::is($tokens[$index], 'FROM')) {
                return true;
            } elseif (0 === $depth && self::is($tokens[$index], 'UNION')) {
                return false;
            }
        }

        return false;
    }

    public static function checkHsqldb(string $sql): ?string
    {
        $tokens = self::tokenize($sql, self::HSQLDB);
        $depth = 0;
        foreach ($tokens as $index => $token) {
            if (self::is($token, '(')) {
                ++$depth;
            } elseif (self::is($token, ')')) {
                if (--$depth < 0) {
                    return 'unbalanced parentheses';
                }
            } elseif (self::is($token, '!', '?', '#', '&', '[', ']', ';', 'WITH', 'GLOB', 'TRANSFORM', 'PIVOT', 'PARAMETERS', 'DISTINCTROW', 'FULL', 'NATURAL', 'OVER', 'WINDOW', 'RECURSIVE', 'EXCEPT', 'INTERSECT')) {
                return sprintf('"%s" is not supported by HSQLDB 1.8', $token[1]);
            } elseif (self::T_WORD === $token[0] && self::is($tokens[$index + 1] ?? ['', '', ''], '(') && !in_array(strtoupper($token[1]), self::HSQLDB_FUNCTIONS, true)) {
                return sprintf('function "%s" is not supported by HSQLDB 1.8', $token[1]);
            } elseif (self::is($token, 'SELECT') && !self::hasFrom($tokens, $index)) {
                // HSQLDB 1.8 requires a FROM clause, also in the subqueries
                return 'SELECT without FROM is not supported by HSQLDB 1.8';
            }
        }

        return 0 === $depth ? null : 'unbalanced parentheses';
    }

    /**
     * Built-in functions of HSQLDB 1.8 (and SQL keywords followed by parentheses).
     */
    private const HSQLDB_FUNCTIONS = [
        'ABS', 'ACOS', 'ASIN', 'ATAN', 'ATAN2', 'BITAND', 'BITOR', 'CEILING', 'COS', 'COT', 'DEGREES', 'EXP', 'FLOOR',
        'LOG', 'LOG10', 'MOD', 'PI', 'POWER', 'RADIANS', 'RAND', 'ROUND', 'SIGN', 'SIN', 'SQRT', 'TAN', 'TRUNCATE',
        'ASCII', 'BIT_LENGTH', 'CHAR', 'CHAR_LENGTH', 'CHARACTER_LENGTH', 'CONCAT', 'DIFFERENCE', 'HEXTORAW', 'INSERT',
        'LCASE', 'LEFT', 'LENGTH', 'LOCATE', 'LTRIM', 'OCTET_LENGTH', 'RAWTOHEX', 'REPEAT', 'REPLACE', 'RIGHT', 'RTRIM',
        'SOUNDEX', 'SPACE', 'SUBSTR', 'SUBSTRING', 'UCASE', 'LOWER', 'UPPER', 'TRIM', 'POSITION', 'CURDATE', 'CURTIME',
        'DATEDIFF', 'DAYNAME', 'DAYOFMONTH', 'DAYOFWEEK', 'DAYOFYEAR', 'HOUR', 'MINUTE', 'MONTH', 'MONTHNAME', 'NOW',
        'QUARTER', 'SECOND', 'WEEK', 'YEAR', 'EXTRACT', 'DAY', 'COALESCE', 'IFNULL', 'NULLIF', 'CASEWHEN', 'CAST',
        'CONVERT', 'COUNT', 'SUM', 'AVG', 'MIN', 'MAX', 'EVERY', 'SOME', 'STDDEV_POP', 'STDDEV_SAMP', 'VAR_POP',
        'VAR_SAMP', 'TO_CHAR', 'IN', 'EXISTS', 'ANY', 'ALL', 'VALUES', 'AS', 'ON', 'AND', 'OR', 'NOT', 'FROM', 'WHERE',
        'SELECT', 'JOIN', 'BETWEEN', 'LIKE', 'IS', 'THEN', 'ELSE', 'WHEN', 'CASE', 'USING', 'BY', 'HAVING',
    ];

    /**
     * Access SELECT TOP n (without PERCENT) => LIMIT n at the end of the statement.
     *
     * @param array<int, array{0: string, 1: string, 2: string}> $tokens
     *
     * @return array<int, array{0: string, 1: string, 2: string}>
     */
    private static function moveTop(array $tokens): array
    {
        if (!self::isSingleSelect($tokens)) {
            return $tokens;
        }
        $index = 1;
        if (self::is($tokens[$index] ?? ['', '', ''], 'DISTINCT', 'ALL')) {
            ++$index;
        }
        if (!self::is($tokens[$index] ?? ['', '', ''], 'TOP') || self::T_NUMBER !== ($tokens[$index + 1][0] ?? '') || self::is($tokens[$index + 2] ?? ['', '', ''], 'PERCENT')) {
            return $tokens;
        }
        $limit = $tokens[$index + 1][1];
        array_splice($tokens, $index, 2);
        $tokens[] = [self::T_WORD, 'LIMIT', ' '];
        $tokens[] = [self::T_NUMBER, $limit, ' '];

        return $tokens;
    }

    /**
     * LIMIT n at the end of a SELECT => SELECT TOP n (Access).
     *
     * @param array<int, array{0: string, 1: string, 2: string}> $tokens
     *
     * @return array<int, array{0: string, 1: string, 2: string}>
     */
    private static function moveLimit(array $tokens): array
    {
        $count = count($tokens);
        if ($count < 3 || !self::isSingleSelect($tokens) || !self::is($tokens[$count - 2], 'LIMIT') || self::T_NUMBER !== $tokens[$count - 1][0]) {
            return $tokens;
        }
        $limit = $tokens[$count - 1][1];
        array_splice($tokens, $count - 2, 2);
        $index = self::is($tokens[1] ?? ['', '', ''], 'DISTINCT', 'ALL') ? 2 : 1;
        array_splice($tokens, $index, 0, [[self::T_WORD, 'TOP', ' '], [self::T_NUMBER, $limit, ' ']]);

        return $tokens;
    }

    /**
     * Statement made of one SELECT (no UNION at the top level).
     *
     * @param array<int, array{0: string, 1: string, 2: string}> $tokens
     */
    private static function isSingleSelect(array $tokens): bool
    {
        if (empty($tokens) || !self::is($tokens[0], 'SELECT')) {
            return false;
        }
        $depth = 0;
        foreach ($tokens as $token) {
            if (self::is($token, '(')) {
                ++$depth;
            } elseif (self::is($token, ')')) {
                --$depth;
            } elseif (0 === $depth && self::is($token, 'UNION', 'EXCEPT', 'INTERSECT')) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{0: string, 1: int}
     */
    private static function readQuoted(string $sql, int $position, string $quote): array
    {
        $value = '';
        $length = strlen($sql);
        ++$position;
        while (true) {
            $end = strpos($sql, $quote, $position);
            if (false === $end) {
                return [$value . substr($sql, $position), $length];
            }
            $value .= substr($sql, $position, $end - $position);
            if ($end + 1 < $length && $sql[$end + 1] === $quote) {
                $value .= $quote;
                $position = $end + 2;

                continue;
            }

            return [$value, $end + 1];
        }
    }
}
