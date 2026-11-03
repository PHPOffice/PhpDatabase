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

use DateTimeImmutable;

/**
 * Properties of the database document.
 */
class DocumentProperties
{
    /**
     * @var string
     */
    protected $title = '';

    /**
     * @var string
     */
    protected $description = '';

    /**
     * @var string
     */
    protected $creator = 'PhpOffice\PhpDatabase';

    /**
     * @var DateTimeImmutable
     */
    protected $created;

    /**
     * @var DateTimeImmutable
     */
    protected $modified;

    public function __construct()
    {
        $this->created = new DateTimeImmutable();
        $this->modified = $this->created;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;

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

    public function getCreator(): string
    {
        return $this->creator;
    }

    public function setCreator(string $creator): self
    {
        $this->creator = $creator;

        return $this;
    }

    public function getCreated(): DateTimeImmutable
    {
        return $this->created;
    }

    public function setCreated(DateTimeImmutable $created): self
    {
        $this->created = $created;

        return $this;
    }

    public function getModified(): DateTimeImmutable
    {
        return $this->modified;
    }

    public function setModified(DateTimeImmutable $modified): self
    {
        $this->modified = $modified;

        return $this;
    }
}
