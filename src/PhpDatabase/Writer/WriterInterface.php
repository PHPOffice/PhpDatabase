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
