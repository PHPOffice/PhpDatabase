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

namespace PhpOffice\PhpDatabase\Shared\Sqlite;

/**
 * BLOB value of a SQLite record (to distinguish it from text).
 */
final class Blob
{
    /**
     * @var string
     */
    public $data;

    public function __construct(string $data)
    {
        $this->data = $data;
    }
}
