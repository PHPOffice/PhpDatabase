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
 * Access table definition (TDEF pages).
 */
final class TableDefinition
{
    /**
     * @var string
     */
    public $name = '';

    /**
     * @var int
     */
    public $page = 0;

    /**
     * @var int
     */
    public $rowCount = 0;

    /**
     * @var int
     */
    public $nextAutoNumber = 0;

    /**
     * Columns in the order of their number.
     *
     * @var array<int, ColumnDefinition>
     */
    public $columns = [];

    /**
     * Column numbers of the primary key.
     *
     * @var array<int, int>
     */
    public $primaryKey = [];

    /**
     * Row pointer of the usage map of the pages owned by the table.
     *
     * @var int
     */
    public $ownedPages = 0;

    /**
     * Row pointer of the usage map of the pages with free space.
     *
     * @var int
     */
    public $freeSpacePages = 0;

    /**
     * Real indexes (index data): columns (number => ascending), flags, root page, usage map pointer and
     * offset of the definition in the TDEF data.
     *
     * @var array<int, array{columns: array<int, bool>, flags: int, root: int, usageMap: int, offset: int}>
     */
    public $realIndexes = [];

    /**
     * Logical indexes: name, number, real index, type (1: primary key, 2: foreign key), foreign key reference.
     *
     * @var array<int, array{name: string, number: int, realIndex: int, type: int, relatedTableType: int, relatedIndex: int, relatedTable: int, cascadeUpdates: int, cascadeDeletes: int}>
     */
    public $logicalIndexes = [];

    /**
     * Usage maps of the long value pages of the memo/OLE columns: column number => [owned, free] row pointers,
     * with the offset of the entry in the TDEF data.
     *
     * @var array<int, array{owned: int, free: int, offset: int}>
     */
    public $columnUsageMaps = [];

    /**
     * Content of the definition (all the TDEF pages, the continuation pages without their header).
     *
     * @var string
     */
    public $data = '';
}
