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

use DateTimeImmutable;

/**
 * File of an attachment column (ColumnType::ATTACHMENT, Access 2007+).
 */
class Attachment
{
    /**
     * @var string
     */
    protected $name;

    /**
     * @var string
     */
    protected $data;

    /**
     * File type (extension, without the dot).
     *
     * @var string
     */
    protected $type;

    /**
     * @var null|DateTimeImmutable
     */
    protected $modified;

    public function __construct(string $name, string $data, ?string $type = null, ?DateTimeImmutable $modified = null)
    {
        $this->name = $name;
        $this->data = $data;
        $this->type = $type ?? strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $this->modified = $modified;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getData(): string
    {
        return $this->data;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getModified(): ?DateTimeImmutable
    {
        return $this->modified;
    }
}
