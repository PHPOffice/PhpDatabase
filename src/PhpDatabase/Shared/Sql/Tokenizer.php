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

namespace PhpOffice\PhpDatabase\Shared\Sql;

use PhpOffice\PhpDatabase\Exception\ReaderException;

/**
 * Tokenizer for the SQL dialects stored in the files (SQLite schema, HSQLDB scripts).
 */
class Tokenizer
{
    /**
     * Decode the \uXXXX escapes of HSQLDB scripts in strings and quoted identifiers.
     *
     * @var bool
     */
    private $unicodeEscapes;

    public function __construct(bool $unicodeEscapes = false)
    {
        $this->unicodeEscapes = $unicodeEscapes;
    }

    /**
     * @return array<int, Token>
     */
    public function tokenize(string $sql): array
    {
        $tokens = [];
        $length = strlen($sql);
        $position = 0;
        $begin = 0;
        $count = 0;
        while ($position < $length) {
            if (count($tokens) > $count) {
                $count = count($tokens);
                $tokens[$count - 1]->start = $begin;
                $tokens[$count - 1]->end = $position;
            }
            $begin = $position;
            $char = $sql[$position];
            if (ctype_space($char)) {
                ++$position;

                continue;
            }
            if ('-' === $char && '-' === ($sql[$position + 1] ?? '')) {
                $end = strpos($sql, "\n", $position);
                $position = false === $end ? $length : $end + 1;

                continue;
            }
            if ('/' === $char && '*' === ($sql[$position + 1] ?? '')) {
                $end = strpos($sql, '*/', $position + 2);
                $position = false === $end ? $length : $end + 2;

                continue;
            }
            if (('x' === $char || 'X' === $char) && "'" === ($sql[$position + 1] ?? '')) {
                [$value, $position] = $this->readQuoted($sql, $position + 1, "'");
                $tokens[] = new Token(Token::BLOB, $value);

                continue;
            }
            if ("'" === $char) {
                [$value, $position] = $this->readQuoted($sql, $position, "'");
                $tokens[] = new Token(Token::STRING, $this->decode($value));

                continue;
            }
            if ('"' === $char || '`' === $char) {
                [$value, $position] = $this->readQuoted($sql, $position, $char);
                $tokens[] = new Token(Token::IDENTIFIER, $this->decode($value));

                continue;
            }
            if ('[' === $char) {
                $end = strpos($sql, ']', $position);
                if (false === $end) {
                    throw new ReaderException('Unterminated identifier in SQL');
                }
                $tokens[] = new Token(Token::IDENTIFIER, substr($sql, $position + 1, $end - $position - 1));
                $position = $end + 1;

                continue;
            }
            if (ctype_digit($char) || ('.' === $char && ctype_digit($sql[$position + 1] ?? ''))) {
                if (1 === preg_match('/\G(?:0x[0-9a-f]+|(?:\d+\.?\d*|\.\d+)(?:e[+-]?\d+)?)/Ai', $sql, $match, 0, $position)) {
                    $tokens[] = new Token(Token::NUMBER, $match[0]);
                    $position += strlen($match[0]);

                    continue;
                }
            }
            if (1 === preg_match('/\G[A-Za-z_\x80-\xff][A-Za-z0-9_$\x80-\xff]*/A', $sql, $match, 0, $position)) {
                $tokens[] = new Token(Token::WORD, $match[0]);
                $position += strlen($match[0]);

                continue;
            }
            if (1 === preg_match('/\G(?:<>|<=|>=|!=|==|\|\||<<|>>)/A', $sql, $match, 0, $position)) {
                $tokens[] = new Token(Token::SYMBOL, $match[0]);
                $position += 2;

                continue;
            }
            $tokens[] = new Token(Token::SYMBOL, $char);
            ++$position;
        }
        if (count($tokens) > $count) {
            $tokens[count($tokens) - 1]->start = $begin;
            $tokens[count($tokens) - 1]->end = $position;
        }

        return $tokens;
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function readQuoted(string $sql, int $position, string $quote): array
    {
        $value = '';
        $length = strlen($sql);
        ++$position;
        while (true) {
            $end = strpos($sql, $quote, $position);
            if (false === $end) {
                throw new ReaderException('Unterminated quoted value in SQL');
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

    private function decode(string $value): string
    {
        if (!$this->unicodeEscapes || !str_contains($value, '\\u')) {
            return $value;
        }

        return self::decodeUnicodeEscapes($value);
    }

    /**
     * Decode \uXXXX escapes (UTF-16 code units, surrogate pairs included) to UTF-8.
     */
    public static function decodeUnicodeEscapes(string $value): string
    {
        return (string) preg_replace_callback('/(?:\\\\u[0-9a-fA-F]{4})+/', static function (array $match): string {
            $utf16 = '';
            foreach (str_split(str_replace('\\u', '', $match[0]), 4) as $unit) {
                $utf16 .= pack('n', hexdec($unit));
            }

            return (string) mb_convert_encoding($utf16, 'UTF-8', 'UTF-16BE');
        }, $value);
    }
}
