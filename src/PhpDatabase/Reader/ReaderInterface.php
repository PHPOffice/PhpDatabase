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

namespace PhpOffice\PhpDatabase\Reader;

use PhpOffice\PhpDatabase\Database;

/**
 * Reader interface.
 */
interface ReaderInterface
{
    /**
     * Load only the structure of the tables, not their rows.
     */
    public const SKIP_ROWS = 1;

    /**
     * Can the current ReaderInterface read the file?
     */
    public function canRead(string $filename): bool;

    /**
     * Loads Database from file.
     */
    public function load(string $filename, int $flags = 0): Database;
}
