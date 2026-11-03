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

use PhpOffice\PhpDatabase\Database;
use PhpOffice\PhpDatabase\Exception\WriterException;

abstract class AbstractWriter implements WriterInterface
{
    /**
     * @var null|Database
     */
    protected $database;

    /**
     * Objects of the database that the format can't hold, skipped by the last save().
     *
     * @var array<int, string>
     */
    protected $warnings = [];

    public function __construct(?Database $database = null)
    {
        $this->database = $database;
    }

    public function getDatabase(): ?Database
    {
        return $this->database;
    }

    public function setDatabase(?Database $database = null): self
    {
        $this->database = $database;

        return $this;
    }

    /**
     * Messages about the objects skipped by the last save() (views, constraints... not supported by the format).
     *
     * @return array<int, string>
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    protected function addWarning(string $message): void
    {
        $this->warnings[] = $message;
    }

    protected function getDatabaseOrFail(): Database
    {
        if (null === $this->database) {
            throw new WriterException('No Database assigned to the writer');
        }

        return $this->database;
    }

    protected function writeFile(string $filename, string $content): void
    {
        if (false === @file_put_contents($filename, $content)) {
            throw new WriterException(sprintf('Unable to write the file "%s"', $filename));
        }
    }
}
