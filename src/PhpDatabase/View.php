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
 * View (Access: select query, LibreOffice Base: view or query): a named SELECT statement.
 *
 * The statement is written in "standard" SQL (see Expression): the readers convert it from the dialect
 * of their format, the writers to the dialect of theirs.
 */
class View
{
    /**
     * @var string
     */
    protected $name;

    /**
     * @var string
     */
    protected $sql;

    /**
     * @var string
     */
    protected $description = '';

    public function __construct(string $name, string $sql)
    {
        if ('' === trim($name)) {
            throw new InvalidArgumentException('The name of a view can\'t be empty');
        }
        $this->name = $name;
        $this->setSql($sql);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    /**
     * SELECT statement (without CREATE VIEW ... AS).
     */
    public function getSql(): string
    {
        return $this->sql;
    }

    public function setSql(string $sql): self
    {
        $this->sql = rtrim(trim($sql), ';');

        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): self
    {
        $this->description = $description;

        return $this;
    }
}
