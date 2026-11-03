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

namespace PhpOffice\PhpDatabase\Shared\Access;

/**
 * Constants of the Jet 4 / ACE file formats (Access 2000 to 2019: .mdb and .accdb).
 */
final class JetFormat
{
    public const PAGE_SIZE = 4096;

    public const VERSION_JET3 = 0;
    public const VERSION_JET4 = 1;  // Access 2000-2003 (.mdb)
    public const VERSION_ACE12 = 2; // Access 2007
    public const VERSION_ACE14 = 3; // Access 2010
    public const VERSION_ACE16 = 5; // Access 2016
    public const VERSION_ACE17 = 6; // Access 2019

    public const HEADER_MASK_KEY = 0x6b39dac7;
    public const OFFSET_MASKED_HEADER = 0x18;
    public const SIZE_MASKED_HEADER_JET4 = 128;
    public const SIZE_MASKED_HEADER_ACE = 126;
    public const OFFSET_CODE_PAGE = 60;
    public const OFFSET_ENCODING_KEY = 62;
    public const OFFSET_PASSWORD = 66;
    public const OFFSET_SORT_ORDER = 110;
    public const OFFSET_HEADER_DATE = 114;

    public const PAGE_DATA = 0x01;
    public const PAGE_TABLE_DEFINITION = 0x02;
    public const PAGE_INDEX_NODE = 0x03;
    public const PAGE_INDEX_LEAF = 0x04;
    public const PAGE_USAGE_MAP = 0x05;

    // Relationship (flags of MSysRelationships.grbit)
    public const RELATIONSHIP_ONE_TO_ONE = 0x00000001;
    public const RELATIONSHIP_NO_REFERENTIAL_INTEGRITY = 0x00000002;
    public const RELATIONSHIP_CASCADE_UPDATES = 0x00000100;
    public const RELATIONSHIP_CASCADE_DELETES = 0x00001000;
    public const RELATIONSHIP_CASCADE_NULL = 0x00002000;

    // Table definition
    public const OFFSET_NEXT_TDEF_PAGE = 4;
    public const OFFSET_TDEF_LENGTH = 8;
    public const OFFSET_NUM_ROWS = 16;
    public const OFFSET_NEXT_AUTO_NUMBER = 20;
    public const OFFSET_TABLE_TYPE = 40;
    public const OFFSET_MAX_COLS = 41;
    public const OFFSET_NUM_VAR_COLS = 43;
    public const OFFSET_NUM_COLS = 45;
    public const OFFSET_NUM_INDEX_SLOTS = 47;
    public const OFFSET_NUM_INDEXES = 51;
    public const OFFSET_OWNED_PAGES = 55;
    public const OFFSET_FREE_SPACE_PAGES = 59;
    public const SIZE_TDEF_HEADER = 63;
    public const SIZE_INDEX_DEFINITION = 12;
    public const SIZE_COLUMN_DEFINITION = 25;
    public const SIZE_INDEX_COLUMN_BLOCK = 52;
    public const SIZE_INDEX_INFO_BLOCK = 28;

    // Column definition
    public const OFFSET_COLUMN_TYPE = 0;
    public const OFFSET_COLUMN_NUMBER = 5;
    public const OFFSET_COLUMN_VARIABLE_INDEX = 7;
    public const OFFSET_COLUMN_PRECISION = 11;
    public const OFFSET_COLUMN_SCALE = 12;
    public const OFFSET_COLUMN_FLAGS = 15;
    public const OFFSET_COLUMN_FIXED_OFFSET = 21;
    public const OFFSET_COLUMN_LENGTH = 23;

    public const COLUMN_FLAG_FIXED = 0x01;
    public const COLUMN_FLAG_UPDATABLE = 0x02;
    public const COLUMN_FLAG_AUTO_NUMBER = 0x04;
    public const COLUMN_FLAG_AUTO_NUMBER_GUID = 0x40;
    public const COLUMN_FLAG_HYPERLINK = 0x80;

    // Data page
    public const OFFSET_DATA_PAGE_TDEF = 4;
    public const OFFSET_DATA_NUM_ROWS = 12;
    public const OFFSET_DATA_ROW_START = 14;
    public const ROW_DELETED = 0x8000;
    public const ROW_LOOKUP = 0x4000;
    public const ROW_OFFSET_MASK = 0x1fff;

    // Column types
    public const TYPE_BOOLEAN = 0x01;
    public const TYPE_BYTE = 0x02;
    public const TYPE_INT = 0x03;
    public const TYPE_LONG = 0x04;
    public const TYPE_MONEY = 0x05;
    public const TYPE_FLOAT = 0x06;
    public const TYPE_DOUBLE = 0x07;
    public const TYPE_DATETIME = 0x08;
    public const TYPE_BINARY = 0x09;
    public const TYPE_TEXT = 0x0a;
    public const TYPE_OLE = 0x0b;
    public const TYPE_MEMO = 0x0c;
    public const TYPE_GUID = 0x0f;
    public const TYPE_NUMERIC = 0x10;
    public const TYPE_COMPLEX = 0x12;
    public const TYPE_BIG_INT = 0x13;
    public const TYPE_EXT_DATETIME = 0x14;

    // MSysObjects
    public const TDEF_PAGE_MSYS_OBJECTS = 2;
    public const OBJECT_TYPE_TABLE = 1;
    public const OBJECT_TYPE_QUERY = 5;
    public const OBJECT_FLAG_SYSTEM = 0x80000002;
    public const OBJECT_FLAG_HIDDEN = 0x8;

    /**
     * Size of the fixed length types (0 for variable length).
     */
    public static function fixedSize(int $type): int
    {
        return [
            self::TYPE_BOOLEAN => 0,
            self::TYPE_BYTE => 1,
            self::TYPE_INT => 2,
            self::TYPE_LONG => 4,
            self::TYPE_MONEY => 8,
            self::TYPE_FLOAT => 4,
            self::TYPE_DOUBLE => 8,
            self::TYPE_DATETIME => 8,
            self::TYPE_GUID => 16,
            self::TYPE_NUMERIC => 17,
            self::TYPE_COMPLEX => 4,
            self::TYPE_BIG_INT => 8,
            self::TYPE_EXT_DATETIME => 42,
        ][$type] ?? 0;
    }

    /**
     * RC4 (used for the header and the pages of encoded Jet 4 databases).
     */
    public static function rc4(string $key, string $data): string
    {
        $state = range(0, 255);
        $keyLength = strlen($key);
        for ($i = 0, $j = 0; $i < 256; ++$i) {
            $j = ($j + $state[$i] + ord($key[$i % $keyLength])) & 0xff;
            [$state[$i], $state[$j]] = [$state[$j], $state[$i]];
        }
        $result = '';
        $length = strlen($data);
        for ($n = 0, $i = 0, $j = 0; $n < $length; ++$n) {
            $i = ($i + 1) & 0xff;
            $j = ($j + $state[$i]) & 0xff;
            [$state[$i], $state[$j]] = [$state[$j], $state[$i]];
            $result .= chr(ord($data[$n]) ^ $state[($state[$i] + $state[$j]) & 0xff]);
        }

        return $result;
    }
}
