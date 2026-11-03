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

use PhpOffice\PhpDatabase\Exception\ReaderException;

/**
 * Varints and records of the SQLite file format (https://www.sqlite.org/fileformat2.html#record_format).
 *
 * Values: null, int, float, string (text) or Blob.
 */
final class Record
{
    /**
     * Read a varint.
     *
     * @return array{0: int, 1: int} value and new offset
     */
    public static function readVarint(string $data, int $offset): array
    {
        $value = 0;
        for ($i = 0; $i < 8; ++$i) {
            if (!isset($data[$offset])) {
                throw new ReaderException('SQLite: truncated varint');
            }
            $byte = ord($data[$offset++]);
            $value = ($value << 7) | ($byte & 0x7f);
            if ($byte < 0x80) {
                return [$value, $offset];
            }
        }
        $value = ($value << 8) | ord($data[$offset++] ?? "\0");

        return [$value, $offset];
    }

    public static function writeVarint(int $value): string
    {
        if ($value >= 0 && $value <= 0x7f) {
            return chr($value);
        }
        if ($value < 0 || $value > 0x00ffffffffffffff) {
            // 9 bytes: 8 groups of 7 bits then a full byte
            $result = chr($value & 0xff);
            $value = ($value >> 8) & 0x00ffffffffffffff;
            for ($i = 0; $i < 8; ++$i) {
                $result = chr(($value & 0x7f) | 0x80) . $result;
                $value >>= 7;
            }

            return $result;
        }
        $result = chr($value & 0x7f);
        $value >>= 7;
        while ($value > 0) {
            $result = chr(($value & 0x7f) | 0x80) . $result;
            $value >>= 7;
        }

        return $result;
    }

    /**
     * Decode a record.
     *
     * @return array<int, null|Blob|float|int|string>
     */
    public static function decode(string $payload): array
    {
        [$headerSize, $offset] = self::readVarint($payload, 0);
        $types = [];
        while ($offset < $headerSize) {
            [$types[], $offset] = self::readVarint($payload, $offset);
        }
        $offset = $headerSize;
        $values = [];
        foreach ($types as $type) {
            switch (true) {
                case 0 === $type:
                    $values[] = null;

                    break;
                case $type >= 1 && $type <= 6:
                    $size = [1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 6, 6 => 8][$type];
                    $values[] = self::readInt(substr($payload, $offset, $size));
                    $offset += $size;

                    break;
                case 7 === $type:
                    $unpacked = unpack('E', substr($payload, $offset, 8));
                    $values[] = false === $unpacked ? null : (float) $unpacked[1];
                    $offset += 8;

                    break;
                case 8 === $type:
                    $values[] = 0;

                    break;
                case 9 === $type:
                    $values[] = 1;

                    break;
                case $type >= 12 && 0 === $type % 2:
                    $size = intdiv($type - 12, 2);
                    $values[] = new Blob(substr($payload, $offset, $size));
                    $offset += $size;

                    break;
                case $type >= 13:
                    $size = intdiv($type - 13, 2);
                    $values[] = substr($payload, $offset, $size);
                    $offset += $size;

                    break;
                default:
                    throw new ReaderException(sprintf('SQLite: invalid serial type %d', $type));
            }
        }

        return $values;
    }

    /**
     * Encode a record (text must already be in the encoding of the database).
     *
     * @param array<int, null|Blob|bool|float|int|string> $values
     */
    public static function encode(array $values): string
    {
        $header = '';
        $body = '';
        foreach ($values as $value) {
            if (null === $value) {
                $header .= "\x00";
            } elseif (is_bool($value) || is_int($value)) {
                $value = (int) $value;
                if (0 === $value || 1 === $value) {
                    $header .= chr(8 + $value);
                } elseif ($value >= -0x80 && $value <= 0x7f) {
                    $header .= "\x01";
                    $body .= pack('c', $value);
                } elseif ($value >= -0x8000 && $value <= 0x7fff) {
                    $header .= "\x02";
                    $body .= pack('n', $value & 0xffff);
                } elseif ($value >= -0x800000 && $value <= 0x7fffff) {
                    $header .= "\x03";
                    $body .= substr(pack('N', $value & 0xffffff), 1);
                } elseif ($value >= -0x80000000 && $value <= 0x7fffffff) {
                    $header .= "\x04";
                    $body .= pack('N', $value & 0xffffffff);
                } elseif ($value >= -0x800000000000 && $value <= 0x7fffffffffff) {
                    $header .= "\x05";
                    $body .= substr(pack('J', $value), 2);
                } else {
                    $header .= "\x06";
                    $body .= pack('J', $value);
                }
            } elseif (is_float($value)) {
                $header .= "\x07";
                $body .= pack('E', $value);
            } elseif ($value instanceof Blob) {
                $header .= self::writeVarint(strlen($value->data) * 2 + 12);
                $body .= $value->data;
            } else {
                $header .= self::writeVarint(strlen($value) * 2 + 13);
                $body .= $value;
            }
        }
        // The header size includes its own varint
        $size = strlen($header) + 1;
        if (strlen(self::writeVarint($size)) > 1) {
            ++$size;
        }

        return self::writeVarint($size) . $header . $body;
    }

    /**
     * Compare two values like SQLite with the BINARY collation:
     * NULL < numbers < text < blobs.
     */
    public static function compareValues(mixed $a, mixed $b): int
    {
        $rank = static function (mixed $value): int {
            if (null === $value) {
                return 0;
            }
            if (is_int($value) || is_float($value) || is_bool($value)) {
                return 1;
            }

            return $value instanceof Blob ? 3 : 2;
        };
        $rankA = $rank($a);
        $rankB = $rank($b);
        if ($rankA !== $rankB) {
            return $rankA <=> $rankB;
        }
        switch ($rankA) {
            case 0:
                return 0;
            case 1:
                return $a <=> $b;
            case 3:
                return strcmp($a->data, $b->data) <=> 0;
            default:
                return strcmp((string) $a, (string) $b) <=> 0;
        }
    }

    /**
     * @param array<int, mixed> $a
     * @param array<int, mixed> $b
     */
    public static function compareRecords(array $a, array $b): int
    {
        foreach ($a as $index => $value) {
            $result = self::compareValues($value, $b[$index] ?? null);
            if (0 !== $result) {
                return $result;
            }
        }

        return count($a) <=> count($b);
    }

    private static function readInt(string $bytes): int
    {
        $value = 0;
        $length = strlen($bytes);
        for ($i = 0; $i < $length; ++$i) {
            $value = ($value << 8) | ord($bytes[$i]);
        }
        if ($length < 8 && $length > 0 && (ord($bytes[0]) & 0x80)) {
            $value -= 1 << ($length * 8);
        }

        return $value;
    }
}
