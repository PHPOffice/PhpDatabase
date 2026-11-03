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

namespace PhpOffice\PhpDatabase\Shared\Access;

/**
 * Column of an Access table definition.
 */
final class ColumnDefinition
{
    /**
     * @var string
     */
    public $name = '';

    /**
     * One of the JetFormat::TYPE_* constants.
     *
     * @var int
     */
    public $type = 0;

    /**
     * Number of the column in the null mask.
     *
     * @var int
     */
    public $number = 0;

    /**
     * @var int
     */
    public $variableIndex = 0;

    /**
     * @var int
     */
    public $fixedOffset = 0;

    /**
     * @var int
     */
    public $length = 0;

    /**
     * @var int
     */
    public $flags = 0;

    /**
     * @var int
     */
    public $precision = 0;

    /**
     * @var int
     */
    public $scale = 0;

    /**
     * Complex columns (attachments, multi-valued): ComplexID of MSysComplexColumns.
     *
     * @var int
     */
    public $complexId = 0;

    /**
     * Text columns: version of the sort order (TextIndexCodes::GENERAL_LEGACY or TextIndexCodes::GENERAL).
     *
     * @var int
     */
    public $sortOrder = 0;

    /**
     * Calculated column (Access 2010+): values wrapped in a header of 20 bytes.
     *
     * @var bool
     */
    public $calculated = false;

    public function isFixed(): bool
    {
        return ($this->flags & JetFormat::COLUMN_FLAG_FIXED) === JetFormat::COLUMN_FLAG_FIXED;
    }

    public function isAutoNumber(): bool
    {
        return ($this->flags & (JetFormat::COLUMN_FLAG_AUTO_NUMBER | JetFormat::COLUMN_FLAG_AUTO_NUMBER_GUID)) !== 0;
    }
}
