<?php

/**
 * This file is part of PhpOffice\PhpDatabase - A pure PHP library for reading and writing
 * database documents (ACCDB, ODB, SQLite).
 *
 * PhpOffice\PhpDatabase is free software distributed under the terms of the GNU Lesser
 * General Public License version 3 as published by the Free Software Foundation.
 *
 * @license http://www.gnu.org/licenses/lgpl.txt LGPL version 3
 */

declare(strict_types=1);

namespace PhpOffice\PhpDatabase\Writer\Sqlite;

use Closure;
use InvalidArgumentException;
use PhpOffice\PhpDatabase\Shared\Sql\Dialect;
use PhpOffice\PhpDatabase\Shared\Sqlite\Record;

/**
 * Evaluation of "standard" SQL expressions with the semantics of SQLite, used to build the entries of
 * the indexes on expressions and of the partial indexes.
 *
 * Supported: literals, columns, arithmetic, ||, comparisons, IS [NOT] NULL, [NOT] IN (list),
 * [NOT] BETWEEN, [NOT] LIKE, AND, OR, NOT, CASE, CAST and the common scalar functions
 * (lower, upper, length, substr, trim, replace, abs, round, coalesce, ifnull, nullif, iif, instr, min, max).
 * Anything else throws an InvalidArgumentException.
 */
final class Evaluator
{
    /**
     * @var array<int, array{0: string, 1: string, 2: string}>
     */
    private $tokens = [];

    /**
     * @var int
     */
    private $position = 0;

    /**
     * Compile an expression: the closure takes a row (column name in lower case => SQLite value).
     *
     * @return Closure(array<string, mixed>): mixed
     */
    public static function compile(string $expression): Closure
    {
        $evaluator = new self();
        $evaluator->tokens = Dialect::tokenize($expression, Dialect::STANDARD);
        $evaluator->position = 0;
        if (empty($evaluator->tokens)) {
            throw new InvalidArgumentException('Empty expression');
        }

        return $evaluator->parseAll();
    }

    /**
     * Called on the instance built by compile(), which PHPMD does not see.
     *
     * @SuppressWarnings("PHPMD.UnusedPrivateMethod")
     */
    private function parseAll(): Closure
    {
        $closure = $this->parseExpression(0);
        if ($this->position < count($this->tokens)) {
            throw new InvalidArgumentException(sprintf('Unexpected "%s" in the expression', $this->tokens[$this->position][1]));
        }

        return $closure;
    }

    /**
     * SQLite truth value: null (unknown), true or false.
     */
    public static function isTrue(mixed $value): ?bool
    {
        if (null === $value) {
            return null;
        }

        return 0.0 !== self::toNumber($value, true);
    }

    /**
     * Binary operators: precedence (higher binds tighter).
     */
    private const PRECEDENCE = [
        'OR' => 1, 'AND' => 2,
        '=' => 4, '==' => 4, '!=' => 4, '<>' => 4, 'IS' => 4, 'IN' => 4, 'LIKE' => 4, 'BETWEEN' => 4, 'NOT' => 4, 'ISNULL' => 4, 'NOTNULL' => 4,
        '<' => 5, '<=' => 5, '>' => 5, '>=' => 5,
        '+' => 7, '-' => 7,
        '*' => 8, '/' => 8, '%' => 8,
        '||' => 9,
    ];

    /**
     * @return Closure(array<string, mixed>): mixed
     */
    private function parseExpression(int $minimum): Closure
    {
        $left = $this->parseUnary();
        while (true) {
            $token = $this->tokens[$this->position] ?? null;
            if (null === $token || (Dialect::T_WORD !== $token[0] && Dialect::T_SYMBOL !== $token[0])) {
                break;
            }
            $operator = strtoupper($token[1]);
            $precedence = self::PRECEDENCE[$operator] ?? null;
            if (null === $precedence || $precedence < $minimum) {
                break;
            }
            ++$this->position;
            $left = $this->parseBinary($operator, $precedence, $left);
        }

        return $left;
    }

    /**
     * @param Closure(array<string, mixed>): mixed $left
     *
     * @return Closure(array<string, mixed>): mixed
     */
    private function parseBinary(string $operator, int $precedence, Closure $left): Closure
    {
        switch ($operator) {
            case 'AND':
                $right = $this->parseExpression($precedence + 1);

                return static function (array $row) use ($left, $right) {
                    $a = self::isTrue($left($row));
                    if (false === $a) {
                        return 0;
                    }
                    $b = self::isTrue($right($row));
                    if (false === $b) {
                        return 0;
                    }

                    return null === $a || null === $b ? null : 1;
                };
            case 'OR':
                $right = $this->parseExpression($precedence + 1);

                return static function (array $row) use ($left, $right) {
                    $a = self::isTrue($left($row));
                    if (true === $a) {
                        return 1;
                    }
                    $b = self::isTrue($right($row));
                    if (true === $b) {
                        return 1;
                    }

                    return null === $a || null === $b ? null : 0;
                };
            case 'ISNULL':
                return static fn (array $row) => null === $left($row) ? 1 : 0;
            case 'NOTNULL':
                return static fn (array $row) => null === $left($row) ? 0 : 1;
            case 'IS':
                $not = $this->accept('NOT');
                if ($this->accept('NULL')) {
                    return static fn (array $row) => (null === $left($row)) !== $not ? 1 : 0;
                }
                $right = $this->parseExpression($precedence + 1);

                return static function (array $row) use ($left, $right, $not) {
                    $a = $left($row);
                    $b = $right($row);
                    $same = null === $a || null === $b ? $a === $b : 0 === self::compare($a, $b);

                    return $same !== $not ? 1 : 0;
                };
            case 'NOT':
                if ($this->accept('NULL')) {
                    return static fn (array $row) => null === $left($row) ? 0 : 1;
                }
                $next = strtoupper($this->tokens[$this->position][1] ?? '');
                if (!in_array($next, ['IN', 'LIKE', 'BETWEEN'], true)) {
                    throw new InvalidArgumentException('Unsupported NOT operator');
                }
                ++$this->position;
                $inner = $this->parseBinary($next, $precedence, $left);

                return static function (array $row) use ($inner) {
                    $value = self::isTrue($inner($row));

                    return null === $value ? null : ($value ? 0 : 1);
                };
            case 'IN':
                $this->expect('(');
                $items = [];
                if (!$this->accept(')')) {
                    do {
                        $items[] = $this->parseExpression(0);
                    } while ($this->accept(','));
                    $this->expect(')');
                }

                return static function (array $row) use ($left, $items) {
                    $value = $left($row);
                    if (null === $value) {
                        return null;
                    }
                    $unknown = false;
                    foreach ($items as $item) {
                        $candidate = $item($row);
                        if (null === $candidate) {
                            $unknown = true;
                        } elseif (0 === self::compare($value, $candidate)) {
                            return 1;
                        }
                    }

                    return $unknown ? null : 0;
                };
            case 'BETWEEN':
                $low = $this->parseExpression(5);
                $this->expect('AND');
                $high = $this->parseExpression(5);

                return static function (array $row) use ($left, $low, $high) {
                    $value = $left($row);
                    $a = $low($row);
                    $b = $high($row);
                    if (null === $value || null === $a || null === $b) {
                        return null;
                    }

                    return self::compare($value, $a) >= 0 && self::compare($value, $b) <= 0 ? 1 : 0;
                };
            case 'LIKE':
                $pattern = $this->parseExpression($precedence + 1);
                $escape = null;
                if ($this->accept('ESCAPE')) {
                    $escape = $this->parseExpression($precedence + 1);
                }

                return static function (array $row) use ($left, $pattern, $escape) {
                    $value = $left($row);
                    $like = $pattern($row);
                    if (null === $value || null === $like) {
                        return null;
                    }
                    $escapeChar = null === $escape ? null : (string) $escape($row);

                    return self::like(self::toText($like), self::toText($value), $escapeChar) ? 1 : 0;
                };
        }
        $right = $this->parseExpression($precedence + 1);
        if (in_array($operator, ['=', '==', '!=', '<>', '<', '<=', '>', '>='], true)) {
            return static function (array $row) use ($left, $right, $operator) {
                $a = $left($row);
                $b = $right($row);
                if (null === $a || null === $b) {
                    return null;
                }
                $result = self::compare($a, $b);
                switch ($operator) {
                    case '=':
                    case '==':
                        return 0 === $result ? 1 : 0;
                    case '!=':
                    case '<>':
                        return 0 !== $result ? 1 : 0;
                    case '<':
                        return $result < 0 ? 1 : 0;
                    case '<=':
                        return $result <= 0 ? 1 : 0;
                    case '>':
                        return $result > 0 ? 1 : 0;
                    default:
                        return $result >= 0 ? 1 : 0;
                }
            };
        }
        if ('||' === $operator) {
            return static function (array $row) use ($left, $right) {
                $a = $left($row);
                $b = $right($row);

                return null === $a || null === $b ? null : self::toText($a) . self::toText($b);
            };
        }

        return static function (array $row) use ($left, $right, $operator) {
            $a = $left($row);
            $b = $right($row);
            if (null === $a || null === $b) {
                return null;
            }

            return self::arithmetic($operator, self::toNumber($a), self::toNumber($b));
        };
    }

    /**
     * @return Closure(array<string, mixed>): mixed
     */
    private function parseUnary(): Closure
    {
        $token = $this->tokens[$this->position] ?? null;
        if (null === $token) {
            throw new InvalidArgumentException('Unexpected end of the expression');
        }
        if (Dialect::is($token, 'NOT')) {
            ++$this->position;
            $operand = $this->parseExpression(3);

            return static function (array $row) use ($operand) {
                $value = self::isTrue($operand($row));

                return null === $value ? null : ($value ? 0 : 1);
            };
        }
        if (Dialect::is($token, '-', '+')) {
            ++$this->position;
            $operand = $this->parseExpression(10);
            if ('+' === $token[1]) {
                return $operand;
            }

            return static function (array $row) use ($operand) {
                $value = $operand($row);

                return null === $value ? null : self::arithmetic('-', 0, self::toNumber($value));
            };
        }

        return $this->parsePrimary();
    }

    /**
     * @return Closure(array<string, mixed>): mixed
     */
    private function parsePrimary(): Closure
    {
        $token = $this->tokens[$this->position++];
        switch ($token[0]) {
            case Dialect::T_NUMBER:
                $value = self::numberLiteral($token[1]);

                return static fn () => $value;
            case Dialect::T_STRING:
                $value = $token[1];

                return static fn () => $value;
            case Dialect::T_IDENTIFIER:
                return $this->column($token[1]);
        }
        if (Dialect::is($token, '(')) {
            $inner = $this->parseExpression(0);
            $this->expect(')');

            return $inner;
        }
        if (Dialect::T_WORD !== $token[0]) {
            throw new InvalidArgumentException(sprintf('Unexpected "%s" in the expression', $token[1]));
        }
        $word = strtoupper($token[1]);
        if ('NULL' === $word) {
            return static fn () => null;
        }
        if ('TRUE' === $word || 'FALSE' === $word) {
            $value = 'TRUE' === $word ? 1 : 0;

            return static fn () => $value;
        }
        if ('CASE' === $word) {
            return $this->parseCase();
        }
        if ('CAST' === $word) {
            $this->expect('(');
            $operand = $this->parseExpression(0);
            $this->expect('AS');
            $type = '';
            while (isset($this->tokens[$this->position]) && !Dialect::is($this->tokens[$this->position], ')')) {
                $type .= ' ' . $this->tokens[$this->position++][1];
            }
            $this->expect(')');

            return static fn (array $row) => self::cast($operand($row), strtoupper(trim($type)));
        }
        if (Dialect::is($this->tokens[$this->position] ?? ['', '', ''], '(')) {
            return $this->parseFunction(strtolower($token[1]));
        }
        if (Dialect::isKeyword($word)) {
            throw new InvalidArgumentException(sprintf('Unsupported keyword "%s" in the expression', $token[1]));
        }

        return $this->column($token[1]);
    }

    /**
     * @return Closure(array<string, mixed>): mixed
     */
    private function column(string $name): Closure
    {
        // table.column
        while (Dialect::is($this->tokens[$this->position] ?? ['', '', ''], '.')) {
            ++$this->position;
            $name = $this->tokens[$this->position++][1] ?? '';
        }
        $key = strtolower($name);

        return static function (array $row) use ($key, $name) {
            if (!array_key_exists($key, $row)) {
                throw new InvalidArgumentException(sprintf('Unknown column "%s" in the expression', $name));
            }

            return $row[$key];
        };
    }

    /**
     * @return Closure(array<string, mixed>): mixed
     */
    private function parseCase(): Closure
    {
        $base = null;
        if (!Dialect::is($this->tokens[$this->position] ?? ['', '', ''], 'WHEN')) {
            $base = $this->parseExpression(0);
        }
        $branches = [];
        while ($this->accept('WHEN')) {
            $condition = $this->parseExpression(0);
            $this->expect('THEN');
            $branches[] = [$condition, $this->parseExpression(0)];
        }
        $else = $this->accept('ELSE') ? $this->parseExpression(0) : null;
        $this->expect('END');

        return static function (array $row) use ($base, $branches, $else) {
            $value = null === $base ? null : $base($row);
            foreach ($branches as [$condition, $result]) {
                $test = $condition($row);
                $matches = null === $base ? true === self::isTrue($test) : (null !== $value && null !== $test && 0 === self::compare($value, $test));
                if ($matches) {
                    return $result($row);
                }
            }

            return null === $else ? null : $else($row);
        };
    }

    /**
     * @return Closure(array<string, mixed>): mixed
     */
    private function parseFunction(string $name): Closure
    {
        $this->expect('(');
        $arguments = [];
        if (!$this->accept(')')) {
            do {
                $arguments[] = $this->parseExpression(0);
            } while ($this->accept(','));
            $this->expect(')');
        }
        $count = count($arguments);
        $arity = ['lower' => [1, 1], 'upper' => [1, 1], 'length' => [1, 1], 'abs' => [1, 1], 'typeof' => [1, 1],
            'coalesce' => [2, 99], 'ifnull' => [2, 2], 'nullif' => [2, 2], 'iif' => [3, 3], 'substr' => [2, 3], 'substring' => [2, 3],
            'trim' => [1, 2], 'ltrim' => [1, 2], 'rtrim' => [1, 2], 'replace' => [3, 3], 'round' => [1, 2], 'instr' => [2, 2],
            'min' => [2, 99], 'max' => [2, 99]];
        if (!isset($arity[$name]) || $count < $arity[$name][0] || $count > $arity[$name][1]) {
            throw new InvalidArgumentException(sprintf('Unsupported function "%s" in the expression', $name));
        }

        return static function (array $row) use ($name, $arguments) {
            if (in_array($name, ['coalesce', 'ifnull'], true)) {
                foreach ($arguments as $argument) {
                    $value = $argument($row);
                    if (null !== $value) {
                        return $value;
                    }
                }

                return null;
            }
            if ('iif' === $name) {
                return true === self::isTrue($arguments[0]($row)) ? $arguments[1]($row) : $arguments[2]($row);
            }
            $values = array_map(static fn (Closure $argument) => $argument($row), $arguments);
            if ('nullif' === $name) {
                return null !== $values[0] && null !== $values[1] && 0 === self::compare($values[0], $values[1]) ? null : $values[0];
            }
            if ('typeof' === $name) {
                return null === $values[0] ? 'null' : (is_int($values[0]) ? 'integer' : (is_float($values[0]) ? 'real' : 'text'));
            }
            if (in_array(null, $values, true)) {
                return null;
            }
            switch ($name) {
                case 'lower':
                    return strtolower(self::toText($values[0]));
                case 'upper':
                    return strtoupper(self::toText($values[0]));
                case 'length':
                    return mb_strlen(self::toText($values[0]), 'UTF-8');
                case 'abs':
                    $number = self::toNumber($values[0]);

                    return is_int($number) ? abs($number) : abs($number);
                case 'substr':
                case 'substring':
                    return self::substr(self::toText($values[0]), (int) self::toNumber($values[1]), isset($values[2]) ? (int) self::toNumber($values[2]) : null);
                case 'trim':
                case 'ltrim':
                case 'rtrim':
                    $characters = isset($values[1]) ? self::toText($values[1]) : ' ';
                    $function = ['trim' => 'trim', 'ltrim' => 'ltrim', 'rtrim' => 'rtrim'][$name];

                    return $function(self::toText($values[0]), $characters);
                case 'replace':
                    $search = self::toText($values[1]);

                    return '' === $search ? self::toText($values[0]) : str_replace($search, self::toText($values[2]), self::toText($values[0]));
                case 'round':
                    return round((float) self::toNumber($values[0]), isset($values[1]) ? (int) self::toNumber($values[1]) : 0);
                case 'instr':
                    $position = mb_strpos(self::toText($values[0]), self::toText($values[1]), 0, 'UTF-8');

                    return false === $position ? 0 : $position + 1;
                default:
                    // min(), max()
                    $result = $values[0];
                    foreach ($values as $value) {
                        $comparison = self::compare($value, $result);
                        if (('min' === $name && $comparison < 0) || ('max' === $name && $comparison > 0)) {
                            $result = $value;
                        }
                    }

                    return $result;
            }
        };
    }

    private function accept(string $value): bool
    {
        if (isset($this->tokens[$this->position]) && Dialect::is($this->tokens[$this->position], $value)) {
            ++$this->position;

            return true;
        }

        return false;
    }

    private function expect(string $value): void
    {
        if (!$this->accept($value)) {
            throw new InvalidArgumentException(sprintf('"%s" expected in the expression', $value));
        }
    }

    /**
     * Comparison of two non null values (numbers before texts, numeric texts compared as numbers).
     */
    private static function compare(mixed $a, mixed $b): int
    {
        if (is_string($a) && (is_int($b) || is_float($b)) && is_numeric($a)) {
            $a = self::numberLiteral(trim($a));
        } elseif (is_string($b) && (is_int($a) || is_float($a)) && is_numeric($b)) {
            $b = self::numberLiteral(trim($b));
        }

        return Record::compareValues($a, $b);
    }

    private static function arithmetic(string $operator, int|float $a, int|float $b): int|float|null
    {
        switch ($operator) {
            case '+':
                $result = $a + $b;

                break;
            case '-':
                $result = $a - $b;

                break;
            case '*':
                $result = $a * $b;

                break;
            case '/':
                if (0 == $b) {
                    return null;
                }
                if (is_int($a) && is_int($b)) {
                    return intdiv($a, $b);
                }

                return $a / $b;
            default:
                $b = (int) $b;
                if (0 === $b) {
                    return null;
                }

                return (int) $a % $b;
        }

        return is_int($a) && is_int($b) && is_float($result) ? (float) $result : $result;
    }

    /**
     * Numeric value of a value (texts: their numeric prefix, like SQLite).
     */
    private static function toNumber(mixed $value, bool $float = false): int|float
    {
        if (is_bool($value)) {
            $value = (int) $value;
        }
        if (is_int($value) || is_float($value)) {
            return $float ? (float) $value : $value;
        }
        if (1 === preg_match('/^\s*[+-]?(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?/', (string) $value, $match)) {
            $number = self::numberLiteral(trim($match[0]));

            return $float ? (float) $number : $number;
        }

        return $float ? 0.0 : 0;
    }

    private static function numberLiteral(string $text): int|float
    {
        $text = ltrim($text, '+');
        if (1 === preg_match('/^-?\d+$/', $text) && abs((float) $text) < 9.2e18) {
            return (int) $text;
        }
        if (1 === preg_match('/^0x[0-9a-f]+$/i', $text)) {
            return (int) hexdec(substr($text, 2));
        }

        return (float) $text;
    }

    private static function toText(mixed $value): string
    {
        if (is_float($value)) {
            if (floor($value) === $value && abs($value) < 1e15) {
                return number_format($value, 1, '.', '');
            }

            return rtrim(rtrim(sprintf('%.15g', $value), '0'), '.');
        }
        if (is_object($value) && property_exists($value, 'data')) {
            return (string) $value->data;
        }

        return (string) $value;
    }

    private static function cast(mixed $value, string $type): mixed
    {
        if (null === $value) {
            return null;
        }
        if (str_contains($type, 'INT')) {
            return (int) self::toNumber($value);
        }
        if (str_contains($type, 'CHAR') || str_contains($type, 'TEXT') || str_contains($type, 'CLOB')) {
            return self::toText($value);
        }
        if (str_contains($type, 'REAL') || str_contains($type, 'FLOA') || str_contains($type, 'DOUB')) {
            return (float) self::toNumber($value);
        }
        if (str_contains($type, 'BLOB')) {
            return $value;
        }
        $number = self::toNumber($value);

        return is_float($number) && floor($number) === $number && abs($number) < 9.2e18 ? (int) $number : $number;
    }

    private static function substr(string $text, int $start, ?int $length): string
    {
        $size = mb_strlen($text, 'UTF-8');
        if ($start < 0) {
            $start = $size + $start + 1;
            if ($start < 1) {
                if (null !== $length) {
                    $length += $start - 1;
                }
                $start = 1;
            }
        } elseif (0 === $start) {
            if (null !== $length) {
                --$length;
            }
            $start = 1;
        }
        if (null !== $length && $length < 0) {
            $end = $start;
            $start = max(1, $start + $length);
            $length = $end - $start;
        }
        if (null !== $length && $length <= 0) {
            return '';
        }

        return mb_substr($text, $start - 1, $length, 'UTF-8');
    }

    /**
     * LIKE: % and _ wildcards, case insensitive for ASCII letters.
     */
    private static function like(string $pattern, string $value, ?string $escape): bool
    {
        // Case insensitive for ASCII letters only, like SQLite
        $pattern = strtolower($pattern);
        $escape = null === $escape ? null : strtolower($escape);
        $regex = '';
        $length = mb_strlen($pattern, 'UTF-8');
        for ($i = 0; $i < $length; ++$i) {
            $char = mb_substr($pattern, $i, 1, 'UTF-8');
            if (null !== $escape && $char === $escape && $i + 1 < $length) {
                $regex .= preg_quote(mb_substr($pattern, ++$i, 1, 'UTF-8'), '/');
            } elseif ('%' === $char) {
                $regex .= '.*';
            } elseif ('_' === $char) {
                $regex .= '.';
            } else {
                $regex .= preg_quote($char, '/');
            }
        }

        return 1 === preg_match('/^' . $regex . '$/su', strtolower($value));
    }
}
