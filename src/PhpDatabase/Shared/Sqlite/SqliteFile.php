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

namespace PhpOffice\PhpDatabase\Shared\Sqlite;

use Generator;
use PhpOffice\PhpDatabase\Exception\ReaderException;

/**
 * Reader of the SQLite 3 file format (https://www.sqlite.org/fileformat2.html).
 */
class SqliteFile
{
    public const SIGNATURE = "SQLite format 3\0";

    /**
     * @var string
     */
    private $data;

    /**
     * @var int
     */
    private $pageSize;

    /**
     * Usable size of a page (page size - reserved bytes).
     *
     * @var int
     */
    private $usableSize;

    /**
     * 1: UTF-8, 2: UTF-16le, 3: UTF-16be.
     *
     * @var int
     */
    private $encoding;

    public function __construct(string $data)
    {
        if (strlen($data) < 100 || !str_starts_with($data, self::SIGNATURE)) {
            throw new ReaderException('Not a SQLite 3 database');
        }
        $this->data = $data;
        $pageSize = $this->uint16(16);
        $this->pageSize = 1 === $pageSize ? 65536 : $pageSize;
        if ($this->pageSize < 512 || ($this->pageSize & ($this->pageSize - 1)) !== 0) {
            throw new ReaderException('SQLite: invalid page size');
        }
        $this->usableSize = $this->pageSize - ord($data[20]);
        $encoding = $this->uint32(56);
        $this->encoding = 0 === $encoding ? 1 : $encoding;
    }

    public function getPageSize(): int
    {
        return $this->pageSize;
    }

    public function getEncoding(): int
    {
        return $this->encoding;
    }

    public function getUserVersion(): int
    {
        return $this->uint32(60);
    }

    /**
     * Rows of sqlite_schema: type, name, tbl_name, rootpage, sql.
     *
     * @return array<int, array{type: string, name: string, tbl_name: string, rootpage: int, sql: null|string}>
     */
    public function getSchema(): array
    {
        $schema = [];
        foreach ($this->readTable(1) as $values) {
            $schema[] = [
                'type' => $this->text($values[0] ?? ''),
                'name' => $this->text($values[1] ?? ''),
                'tbl_name' => $this->text($values[2] ?? ''),
                'rootpage' => (int) ($values[3] ?? 0),
                'sql' => isset($values[4]) ? $this->text($values[4]) : null,
            ];
        }

        return $schema;
    }

    /**
     * Records of a table b-tree, in rowid order, indexed by rowid.
     *
     * @return Generator<int, array<int, null|Blob|float|int|string>>
     */
    public function readTable(int $rootPage): Generator
    {
        yield from $this->walk($rootPage, 0);
    }

    /**
     * Records of an index b-tree (WITHOUT ROWID tables), in key order.
     *
     * @return Generator<int, array<int, null|Blob|float|int|string>>
     */
    public function readIndex(int $rootPage): Generator
    {
        foreach ($this->walk($rootPage, 0) as $record) {
            yield $record;
        }
    }

    /**
     * Convert a text value of a record to UTF-8.
     */
    public function text(mixed $value): string
    {
        if ($value instanceof Blob) {
            return $value->data;
        }
        if (!is_string($value)) {
            return (string) $value;
        }
        if (2 === $this->encoding) {
            return (string) mb_convert_encoding($value, 'UTF-8', 'UTF-16LE');
        }
        if (3 === $this->encoding) {
            return (string) mb_convert_encoding($value, 'UTF-8', 'UTF-16BE');
        }

        return $value;
    }

    /**
     * @return Generator<int, array<int, null|Blob|float|int|string>>
     */
    private function walk(int $pageNumber, int $depth): Generator
    {
        if ($depth > 64) {
            throw new ReaderException('SQLite: b-tree too deep (corrupted file?)');
        }
        $pageOffset = ($pageNumber - 1) * $this->pageSize;
        if ($pageOffset < 0 || $pageOffset + $this->pageSize > strlen($this->data)) {
            throw new ReaderException(sprintf('SQLite: invalid page %d', $pageNumber));
        }
        $headerOffset = $pageOffset + (1 === $pageNumber ? 100 : 0);
        $type = ord($this->data[$headerOffset]);
        $cellCount = $this->uint16($headerOffset + 3);
        $interior = 0x02 === $type || 0x05 === $type;
        $pointerOffset = $headerOffset + ($interior ? 12 : 8);
        for ($i = 0; $i < $cellCount; ++$i) {
            $cellOffset = $pageOffset + $this->uint16($pointerOffset + $i * 2);
            switch ($type) {
                case 0x0d:
                    [$payloadSize, $offset] = Record::readVarint($this->data, $cellOffset);
                    [$rowid, $offset] = Record::readVarint($this->data, $offset);
                    yield $rowid => Record::decode($this->readPayload($offset, $payloadSize, false));

                    break;
                case 0x05:
                    yield from $this->walk($this->uint32($cellOffset), $depth + 1);

                    break;
                case 0x0a:
                    [$payloadSize, $offset] = Record::readVarint($this->data, $cellOffset);
                    yield Record::decode($this->readPayload($offset, $payloadSize, true));

                    break;
                case 0x02:
                    yield from $this->walk($this->uint32($cellOffset), $depth + 1);
                    [$payloadSize, $offset] = Record::readVarint($this->data, $cellOffset + 4);
                    yield Record::decode($this->readPayload($offset, $payloadSize, true));

                    break;
                default:
                    throw new ReaderException(sprintf('SQLite: invalid b-tree page type %d on page %d', $type, $pageNumber));
            }
        }
        if ($interior) {
            yield from $this->walk($this->uint32($headerOffset + 8), $depth + 1);
        }
    }

    private function readPayload(int $offset, int $size, bool $index): string
    {
        $local = PayloadSize::local($size, $this->usableSize, $index);
        $payload = substr($this->data, $offset, $local);
        if ($local < $size) {
            $next = $this->uint32($offset + $local);
            $remaining = $size - $local;
            $guard = 0;
            while ($remaining > 0 && $next > 0) {
                if (++$guard > 1000000) {
                    throw new ReaderException('SQLite: overflow chain loop');
                }
                $pageOffset = ($next - 1) * $this->pageSize;
                $chunk = min($remaining, $this->usableSize - 4);
                $payload .= substr($this->data, $pageOffset + 4, $chunk);
                $remaining -= $chunk;
                $next = $this->uint32($pageOffset);
            }
        }

        return $payload;
    }

    private function uint16(int $offset): int
    {
        return (ord($this->data[$offset]) << 8) | ord($this->data[$offset + 1]);
    }

    private function uint32(int $offset): int
    {
        $unpacked = unpack('N', $this->data, $offset);

        return false === $unpacked ? 0 : (int) $unpacked[1];
    }
}
