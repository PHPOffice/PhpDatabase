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
 * SQL token.
 */
final class Token
{
    public const WORD = 'word';
    public const IDENTIFIER = 'identifier';
    public const STRING = 'string';
    public const NUMBER = 'number';
    public const BLOB = 'blob';
    public const SYMBOL = 'symbol';

    /**
     * @var string
     */
    public $type;

    /**
     * Unquoted value (identifier/string), raw text otherwise.
     *
     * @var string
     */
    public $value;

    /**
     * Offsets of the token in the SQL text (end excluded).
     *
     * @var int
     */
    public $start = 0;

    /**
     * @var int
     */
    public $end = 0;

    public function __construct(string $type, string $value)
    {
        $this->type = $type;
        $this->value = $value;
    }

    /**
     * Is this token the keyword or symbol given (case insensitive)?
     */
    public function is(string ...$values): bool
    {
        if (self::WORD !== $this->type && self::SYMBOL !== $this->type) {
            return false;
        }
        foreach ($values as $value) {
            if (0 === strcasecmp($this->value, $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Is this token a name (bare word or quoted identifier)?
     */
    public function isName(): bool
    {
        return self::WORD === $this->type || self::IDENTIFIER === $this->type;
    }
}
