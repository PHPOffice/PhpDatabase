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

namespace PhpOffice\PhpDatabase\Exception;

class FileNotFoundException extends DatabaseException
{
    public function __construct(string $path)
    {
        parent::__construct(sprintf('The file "%s" doesn\'t exist', $path));
    }
}
