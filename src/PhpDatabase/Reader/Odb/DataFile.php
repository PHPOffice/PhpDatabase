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

namespace PhpOffice\PhpDatabase\Reader\Odb;

use DateTimeImmutable;
use DateTimeZone;
use Generator;
use PhpOffice\PhpDatabase\Exception\ReaderException;

/**
 * Reader of the rows of the CACHED tables of HSQLDB 1.8 (database/data file of the ODB files).
 *
 * A row is: int size, one AVL node per index (int balance, int left, int right, int parent),
 * then for each column a "not null" byte followed by the value.
 */
class DataFile
{
    /**
     * @var string
     */
    private $data;

    /**
     * hsqldb.cache_file_scale: positions are multiplied by this value.
     *
     * @var int
     */
    private $scale;

    /**
     * @var DateTimeZone
     */
    private $timezone;

    /**
     * @var int
     */
    private $offset = 0;

    public function __construct(string $data, int $scale = 1, ?DateTimeZone $timezone = null)
    {
        $this->data = $data;
        $this->scale = max(1, $scale);
        $this->timezone = $timezone ?? new DateTimeZone(date_default_timezone_get());
    }

    /**
     * Rows of a table, in the order of its primary index.
     *
     * @param array<int, string> $types HSQLDB type names of the columns (INTEGER, VARCHAR...)
     *
     * @return Generator<int, array<int, mixed>>
     */
    public function readRows(int $root, int $indexCount, array $types): Generator
    {
        // In-order traversal of the AVL tree of the primary index
        $stack = [];
        $position = $root;
        $guard = 0;
        $limit = intdiv(strlen($this->data), 8) + 1;
        while (true) {
            while (0 !== $position) {
                if (++$guard > $limit) {
                    throw new ReaderException('HSQLDB: loop in the index of a table');
                }
                $stack[] = $position;
                $position = $this->int32($position * $this->scale + 8);
            }
            $position = array_pop($stack);
            if (null === $position) {
                return;
            }
            yield $this->readRow($position * $this->scale, $indexCount, $types);
            $position = $this->int32($position * $this->scale + 12);
        }
    }

    /**
     * @param array<int, string> $types
     *
     * @return array<int, mixed>
     */
    private function readRow(int $offset, int $indexCount, array $types): array
    {
        if ($offset + 4 > strlen($this->data)) {
            throw new ReaderException('HSQLDB: row outside of the data file');
        }
        $this->offset = $offset + 4 + 16 * $indexCount;
        $values = [];
        foreach ($types as $type) {
            if ("\0" === $this->data[$this->offset++]) {
                $values[] = null;

                continue;
            }
            $values[] = $this->readValue($type);
        }

        return $values;
    }

    private function readValue(string $type): mixed
    {
        switch ($type) {
            case 'INTEGER':
            case 'INT':
                return $this->readInt();
            case 'SMALLINT':
            case 'TINYINT':
                $value = (ord($this->data[$this->offset]) << 8) | ord($this->data[$this->offset + 1]);
                $this->offset += 2;

                return $value >= 0x8000 ? $value - 0x10000 : $value;
            case 'BIGINT':
                return $this->readLong();
            case 'BOOLEAN':
            case 'BIT':
                return "\0" !== $this->data[$this->offset++];
            case 'REAL':
            case 'FLOAT':
            case 'DOUBLE':
                $unpacked = unpack('E', $this->data, $this->offset);
                $this->offset += 8;

                return false === $unpacked ? null : (float) $unpacked[1];
            case 'DECIMAL':
            case 'NUMERIC':
                $unscaled = $this->readBytes();
                $scale = $this->readInt();

                return self::bigDecimal($unscaled, $scale);
            case 'DATE':
                return $this->toDate($this->readLong(), 0)->setTime(0, 0);
            case 'TIME':
                return $this->toDate($this->readLong(), 0);
            case 'TIMESTAMP':
            case 'DATETIME':
                $millis = $this->readLong();

                return $this->toDate($millis, $this->readInt());
            case 'BINARY':
            case 'VARBINARY':
            case 'LONGVARBINARY':
            case 'OTHER':
            case 'OBJECT':
                return $this->readBytes();
            default:
                // CHAR, VARCHAR, VARCHAR_IGNORECASE, LONGVARCHAR
                return self::decodeModifiedUtf8($this->readBytes());
        }
    }

    private function toDate(int $millis, int $nanos): DateTimeImmutable
    {
        $milliseconds = (($millis % 1000) + 1000) % 1000;
        $seconds = intdiv($millis - $milliseconds, 1000);
        $date = (new DateTimeImmutable('@' . $seconds))->setTimezone($this->timezone);
        $micro = $nanos > 0 ? intdiv($nanos, 1000) : $milliseconds * 1000;

        return $micro > 0 ? $date->setTime((int) $date->format('G'), (int) $date->format('i'), (int) $date->format('s'), $micro) : $date;
    }

    private function readInt(): int
    {
        $unpacked = unpack('N', $this->data, $this->offset);
        $this->offset += 4;
        $value = false === $unpacked ? 0 : (int) $unpacked[1];

        return $value >= 0x80000000 ? $value - 0x100000000 : $value;
    }

    private function readLong(): int
    {
        $unpacked = unpack('J', $this->data, $this->offset);
        $this->offset += 8;

        return false === $unpacked ? 0 : (int) $unpacked[1];
    }

    private function readBytes(): string
    {
        $length = $this->readInt();
        if ($length < 0 || $this->offset + $length > strlen($this->data)) {
            throw new ReaderException('HSQLDB: invalid value length');
        }
        $bytes = substr($this->data, $this->offset, $length);
        $this->offset += $length;

        return $bytes;
    }

    private function int32(int $offset): int
    {
        if ($offset + 4 > strlen($this->data)) {
            throw new ReaderException('HSQLDB: index node outside of the data file');
        }
        $unpacked = unpack('N', $this->data, $offset);
        $value = false === $unpacked ? 0 : (int) $unpacked[1];

        return $value >= 0x80000000 ? $value - 0x100000000 : $value;
    }

    /**
     * Java BigDecimal (two's complement big-endian unscaled value + scale) to a decimal string.
     */
    public static function bigDecimal(string $unscaled, int $scale): string
    {
        $negative = '' !== $unscaled && (ord($unscaled[0]) & 0x80);
        if ($negative) {
            // Two's complement: invert and add one
            $unscaled = ~$unscaled;
            for ($i = strlen($unscaled) - 1; $i >= 0; --$i) {
                $byte = ord($unscaled[$i]) + 1;
                $unscaled[$i] = chr($byte & 0xff);
                if ($byte < 0x100) {
                    break;
                }
            }
        }
        // Base 256 to base 10 without extension
        $digits = '0';
        for ($i = 0, $length = strlen($unscaled); $i < $length; ++$i) {
            $carry = ord($unscaled[$i]);
            $result = '';
            for ($j = strlen($digits) - 1; $j >= 0; --$j) {
                $value = ((int) $digits[$j]) * 256 + $carry;
                $result = ($value % 10) . $result;
                $carry = intdiv($value, 10);
            }
            while ($carry > 0) {
                $result = ($carry % 10) . $result;
                $carry = intdiv($carry, 10);
            }
            $digits = ltrim($result, '0');
            if ('' === $digits) {
                $digits = '0';
            }
        }
        if ($scale > 0) {
            $digits = str_pad($digits, $scale + 1, '0', STR_PAD_LEFT);
            $digits = substr($digits, 0, -$scale) . '.' . substr($digits, -$scale);
        } elseif ($scale < 0) {
            $digits .= str_repeat('0', -$scale);
        }

        return ($negative ? '-' : '') . $digits;
    }

    /**
     * Java "modified UTF-8" (null as C0 80, supplementary characters as surrogate pairs) to UTF-8.
     */
    public static function decodeModifiedUtf8(string $bytes): string
    {
        if (1 !== preg_match('/\xC0\x80|\xED[\xA0-\xBF]/', $bytes)) {
            return $bytes;
        }
        $bytes = str_replace("\xC0\x80", "\0", $bytes);

        return (string) preg_replace_callback('/\xED([\xA0-\xAF])([\x80-\xBF])\xED([\xB0-\xBF])([\x80-\xBF])/', static function (array $match): string {
            $high = 0xD000 | ((ord($match[1]) & 0x3F) << 6) | (ord($match[2]) & 0x3F);
            $low = 0xD000 | ((ord($match[3]) & 0x3F) << 6) | (ord($match[4]) & 0x3F);

            return (string) mb_convert_encoding(pack('nn', $high, $low), 'UTF-8', 'UTF-16BE');
        }, $bytes);
    }
}
