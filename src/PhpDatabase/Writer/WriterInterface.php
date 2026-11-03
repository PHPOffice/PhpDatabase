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

namespace PhpOffice\PhpDatabase\Writer;

/**
 * Writer interface.
 */
interface WriterInterface
{
    /**
     * Save Database to file.
     */
    public function save(string $filename): void;

    /**
     * Messages about the objects skipped by the last save() (views, constraints... not supported by the format).
     *
     * @return array<int, string>
     */
    public function getWarnings(): array;
}
