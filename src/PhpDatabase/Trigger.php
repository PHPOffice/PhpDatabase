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

use InvalidArgumentException;

/**
 * Trigger of a table. The statement is specific to an engine: only SQLite triggers are read and written.
 */
class Trigger
{
    /**
     * @var string
     */
    protected $name;

    /**
     * @var string
     */
    protected $table;

    /**
     * @var string
     */
    protected $sql;

    /**
     * @param string $sql complete statement (CREATE TRIGGER ... END)
     */
    public function __construct(string $name, string $table, string $sql)
    {
        if ('' === trim($name)) {
            throw new InvalidArgumentException('The name of a trigger can\'t be empty');
        }
        $this->name = $name;
        $this->table = $table;
        $this->sql = rtrim(trim($sql), ';');
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getTable(): string
    {
        return $this->table;
    }

    public function getSql(): string
    {
        return $this->sql;
    }
}
