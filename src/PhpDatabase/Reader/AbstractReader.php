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

namespace PhpOffice\PhpDatabase\Reader;

use PhpOffice\PhpDatabase\Exception\FileNotFoundException;
use PhpOffice\PhpDatabase\Exception\InvalidFileFormatException;

abstract class AbstractReader implements ReaderInterface
{
    /**
     * @var int
     */
    protected $flags = 0;

    protected function assertReadable(string $filename): void
    {
        if (!is_file($filename) || !is_readable($filename)) {
            throw new FileNotFoundException($filename);
        }
        if (!$this->canRead($filename)) {
            throw new InvalidFileFormatException($filename, static::class);
        }
    }

    protected function readSignature(string $filename, int $length, int $offset = 0): string
    {
        if (!is_file($filename) || !is_readable($filename)) {
            return '';
        }
        $handle = fopen($filename, 'rb');
        if (false === $handle) {
            return '';
        }
        if ($offset > 0) {
            fseek($handle, $offset);
        }
        $data = (string) fread($handle, max(1, $length));
        fclose($handle);

        return $data;
    }

    protected function skipRows(): bool
    {
        return ($this->flags & self::SKIP_ROWS) === self::SKIP_ROWS;
    }
}
