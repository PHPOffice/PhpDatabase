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

namespace PhpOffice\PhpDatabase;

/**
 * Logical column types, independent of the file format.
 *
 * PHP values held in the rows: BOOLEAN => bool, TINYINT/SMALLINT/INTEGER/BIGINT => int,
 * FLOAT/DOUBLE => float, DECIMAL => numeric string, TEXT/LONGTEXT => string (UTF-8),
 * DATE/TIME/DATETIME => DateTimeImmutable, BLOB => binary string, GUID => "{XXXXXXXX-XXXX-XXXX-XXXX-XXXXXXXXXXXX}",
 * HYPERLINK => string ("text#address#subaddress#" like Access), ATTACHMENT => array of Attachment.
 * null is always allowed. A multi-valued column (Column::setMultiValued()) holds an array of values.
 */
final class ColumnType
{
    public const BOOLEAN = 'boolean';
    public const TINYINT = 'tinyint';
    public const SMALLINT = 'smallint';
    public const INTEGER = 'integer';
    public const BIGINT = 'bigint';
    public const FLOAT = 'float';
    public const DOUBLE = 'double';
    public const DECIMAL = 'decimal';
    /** Text with an optional maximum length. */
    public const TEXT = 'text';
    /** Long text (Access memo, HSQLDB LONGVARCHAR). */
    public const LONGTEXT = 'longtext';
    public const DATE = 'date';
    public const TIME = 'time';
    public const DATETIME = 'datetime';
    public const BLOB = 'blob';
    /** Globally unique identifier (Access: Replication ID). */
    public const GUID = 'guid';
    /** Hyperlink (Access): text holding "text#address#subaddress#screen tip". */
    public const HYPERLINK = 'hyperlink';
    /** Files (Access 2007+ attachments). */
    public const ATTACHMENT = 'attachment';

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::BOOLEAN, self::TINYINT, self::SMALLINT, self::INTEGER, self::BIGINT,
            self::FLOAT, self::DOUBLE, self::DECIMAL, self::TEXT, self::LONGTEXT,
            self::DATE, self::TIME, self::DATETIME, self::BLOB, self::GUID, self::HYPERLINK, self::ATTACHMENT,
        ];
    }

    public static function isValid(string $type): bool
    {
        return in_array($type, self::all(), true);
    }

    public static function isInteger(string $type): bool
    {
        return in_array($type, [self::TINYINT, self::SMALLINT, self::INTEGER, self::BIGINT], true);
    }

    public static function isTemporal(string $type): bool
    {
        return in_array($type, [self::DATE, self::TIME, self::DATETIME], true);
    }

    public static function isText(string $type): bool
    {
        return self::TEXT === $type || self::LONGTEXT === $type;
    }
}
