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

namespace PhpOffice\PhpDatabase;

use DateTimeImmutable;

/**
 * SQL expression (default value of a column, column of an index), written in "standard" SQL:
 * identifiers between double quotes, strings between single quotes, || for concatenations,
 * CURRENT_DATE, CURRENT_TIME and CURRENT_TIMESTAMP for the current date/time.
 * The readers and writers convert it from/to the dialect of each format (see Shared\Sql\Dialect).
 */
final class Expression
{
    public const CURRENT_DATE = 'CURRENT_DATE';
    public const CURRENT_TIME = 'CURRENT_TIME';
    public const CURRENT_TIMESTAMP = 'CURRENT_TIMESTAMP';

    /**
     * @var string
     */
    private $sql;

    public function __construct(string $sql)
    {
        $sql = trim($sql);
        foreach ([self::CURRENT_DATE, self::CURRENT_TIME, self::CURRENT_TIMESTAMP] as $constant) {
            if (0 === strcasecmp($sql, $constant)) {
                $sql = $constant;
            }
        }
        $this->sql = $sql;
    }

    public static function currentDate(): self
    {
        return new self(self::CURRENT_DATE);
    }

    public static function currentTime(): self
    {
        return new self(self::CURRENT_TIME);
    }

    public static function currentTimestamp(): self
    {
        return new self(self::CURRENT_TIMESTAMP);
    }

    public function getSql(): string
    {
        return $this->sql;
    }

    /**
     * CURRENT_DATE, CURRENT_TIME or CURRENT_TIMESTAMP.
     */
    public function isCurrentDateTime(): bool
    {
        return in_array($this->sql, [self::CURRENT_DATE, self::CURRENT_TIME, self::CURRENT_TIMESTAMP], true);
    }

    /**
     * Value of the expression when a row is added without a value for the column:
     * the current date/time, null for the other expressions (only evaluated by the database engines).
     */
    public function evaluate(): ?DateTimeImmutable
    {
        switch ($this->sql) {
            case self::CURRENT_DATE:
                return new DateTimeImmutable('today');
            case self::CURRENT_TIME:
            case self::CURRENT_TIMESTAMP:
                return new DateTimeImmutable('now');
            default:
                return null;
        }
    }

    public function __toString(): string
    {
        return $this->sql;
    }
}
