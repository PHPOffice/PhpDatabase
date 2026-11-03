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

namespace PhpOffice\PhpDatabase\Writer\Accdb;

use DateTimeImmutable;
use PhpOffice\PhpDatabase\Exception\WriterException;
use PhpOffice\PhpDatabase\Shared\Access\ColumnDefinition;
use PhpOffice\PhpDatabase\Shared\Access\JetFormat;
use PhpOffice\PhpDatabase\Shared\Access\ValueCodec;

/**
 * Keys of the index entries: for each column, a flag (start of a value or null) then the value encoded
 * so that the entries are sorted by comparing their bytes (rules of Jackcess, IndexData).
 */
final class IndexKey
{
    private const ASC_START = "\x7F";
    private const ASC_NULL = "\x00";
    private const DESC_START = "\x80";
    private const DESC_NULL = "\xFF";

    /**
     * Can a column of this type be indexed?
     */
    public static function isIndexable(int $type): bool
    {
        return in_array($type, [
            JetFormat::TYPE_BOOLEAN, JetFormat::TYPE_BYTE, JetFormat::TYPE_INT, JetFormat::TYPE_LONG, JetFormat::TYPE_BIG_INT,
            JetFormat::TYPE_MONEY, JetFormat::TYPE_FLOAT, JetFormat::TYPE_DOUBLE, JetFormat::TYPE_DATETIME, JetFormat::TYPE_NUMERIC,
            JetFormat::TYPE_TEXT, JetFormat::TYPE_MEMO, JetFormat::TYPE_GUID, JetFormat::TYPE_BINARY, JetFormat::TYPE_EXT_DATETIME,
        ], true);
    }

    /**
     * Key of one column.
     *
     * @param int $sortOrder TextIndexCodes::GENERAL_LEGACY or TextIndexCodes::GENERAL
     * @param bool $legacyNumeric NUMERIC keys of Access 2000 (Jet 4)
     */
    public static function encode(ColumnDefinition $column, bool $ascending, mixed $value, int $sortOrder, bool $legacyNumeric): string
    {
        if (JetFormat::TYPE_BOOLEAN === $column->type) {
            // Never null; true sorts first
            $true = true === $value || (!is_bool($value) && null !== $value && (bool) $value);

            return ($ascending ? self::ASC_START : self::DESC_START) . ($true === $ascending ? "\x00" : "\xFF");
        }
        if (null === $value) {
            return $ascending ? self::ASC_NULL : self::DESC_NULL;
        }
        $start = $ascending ? self::ASC_START : self::DESC_START;
        switch ($column->type) {
            case JetFormat::TYPE_TEXT:
            case JetFormat::TYPE_MEMO:
                return $start . TextIndexCodes::getInstance($sortOrder)->encode((string) $value, $ascending);
            case JetFormat::TYPE_BYTE:
                $bytes = chr(((int) $value) & 0xFF);

                return $start . ($ascending ? $bytes : ~$bytes);
            case JetFormat::TYPE_INT:
                return $start . self::integer(pack('n', ((int) $value) & 0xFFFF), $ascending);
            case JetFormat::TYPE_LONG:
                return $start . self::integer(pack('N', ((int) $value) & 0xFFFFFFFF), $ascending);
            case JetFormat::TYPE_BIG_INT:
                return $start . self::integer(pack('J', (int) $value), $ascending);
            case JetFormat::TYPE_MONEY:
                return $start . self::integer(strrev(ValueCodec::encodeMoney((string) $value)), $ascending);
            case JetFormat::TYPE_FLOAT:
                return $start . self::floatingPoint(pack('G', (float) $value), $ascending);
            case JetFormat::TYPE_DOUBLE:
                return $start . self::floatingPoint(pack('E', (float) $value), $ascending);
            case JetFormat::TYPE_DATETIME:
                $float = $value instanceof DateTimeImmutable ? ValueCodec::encodeDate($value) : (float) $value;

                return $start . self::floatingPoint(pack('E', $float), $ascending);
            case JetFormat::TYPE_EXT_DATETIME:
                // 42 bytes in blocks of 8 bytes separated by tabulations (not inverted when descending)
                $bytes = $value instanceof DateTimeImmutable ? ValueCodec::encodeExtendedDate($value) : str_pad((string) $value, 42, "\0");
                if (!$ascending) {
                    $bytes = ~$bytes;
                }

                return $start . implode("\x09", str_split(substr($bytes, 0, 40), 8)) . "\x09" . substr($bytes, 40, 2)
                    . ($ascending ? "\0\0\0\0\0\0\x02" : ~"\0\0\0\0\0\0\x02");
            case JetFormat::TYPE_NUMERIC:
                $littleEndian = ValueCodec::encodeNumeric((string) $value, $column->scale);
                $bytes = $littleEndian[0];
                foreach (str_split(substr($littleEndian, 1), 4) as $word) {
                    $bytes .= strrev($word);
                }

                return $start . self::fixedPoint($bytes, $ascending, $legacyNumeric);
            case JetFormat::TYPE_GUID:
                $hex = (string) preg_replace('/[^0-9a-fA-F]/', '', (string) $value);
                if (32 !== strlen($hex)) {
                    throw new WriterException(sprintf('Access: invalid GUID "%s"', (string) $value));
                }

                return $start . self::binary((string) hex2bin($hex), $ascending);
            case JetFormat::TYPE_BINARY:
                return $start . self::binary((string) $value, $ascending);
            default:
                throw new WriterException(sprintf('Access: columns of type %d can\'t be indexed', $column->type));
        }
    }

    /**
     * Integer types: big-endian, sign bit inverted.
     */
    private static function integer(string $bytes, bool $ascending): string
    {
        $bytes[0] = chr(ord($bytes[0]) ^ 0x80);

        return $ascending ? $bytes : ~$bytes;
    }

    /**
     * Floating point types: big-endian, sign bit inverted for the positive values, all the bits for the negative ones.
     */
    private static function floatingPoint(string $bytes, bool $ascending): string
    {
        $negative = 0 !== (ord($bytes[0]) & 0x80);
        if (!$negative) {
            $bytes[0] = chr(ord($bytes[0]) ^ 0x80);
        }

        return $negative === $ascending ? ~$bytes : $bytes;
    }

    /**
     * NUMERIC: sign byte then the unscaled value (big-endian).
     */
    private static function fixedPoint(string $bytes, bool $ascending, bool $legacy): string
    {
        $negative = 0 !== (ord($bytes[0]) & 0x80);
        if ($legacy) {
            if ($negative === $ascending) {
                $bytes = ~$bytes;
            }
            $bytes[0] = $negative ? "\x00" : "\xFF";

            return $bytes;
        }
        $bytes[0] = "\xFF";

        return $negative === $ascending ? ~$bytes : $bytes;
    }

    /**
     * Binary and GUID: segments of 8 bytes followed by their length (9: more data follows).
     */
    private static function binary(string $data, bool $ascending): string
    {
        $out = '';
        $segments = str_split($data, 8);
        $last = array_pop($segments);
        foreach ($segments as $segment) {
            $out .= ($ascending ? $segment : ~$segment) . "\x09";
        }
        if (null !== $last && '' !== $last) {
            $segment = str_pad($last, 8, "\0") . chr(strlen($last));
            $out .= $ascending ? $segment : ~$segment;
        }

        return $out;
    }
}
