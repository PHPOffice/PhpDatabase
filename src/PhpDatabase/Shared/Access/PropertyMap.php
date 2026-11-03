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

namespace PhpOffice\PhpDatabase\Shared\Access;

/**
 * Properties of an object of the catalog (column "LvProp" of MSysObjects): the properties of the object
 * itself (Description...) and of each column of a table (Required, DefaultValue, Description...).
 *
 * Format: "MR2\0", a block with the names of the properties, then one block per map
 * (type 0x00: object, 0x01: column), each value being written like a column value.
 */
final class PropertyMap
{
    public const SIGNATURE = "MR2\0";

    private const BLOCK_NAMES = 0x80;
    private const BLOCK_OBJECT = 0x00;
    private const BLOCK_COLUMN = 0x01;

    /**
     * Properties by map name ('' for the object, else column name): name => [type, value, ddl].
     *
     * @var array<string, array<string, array{0: int, 1: mixed, 2: bool}>>
     */
    private $maps = [];

    public static function parse(string $data): self
    {
        $map = new self();
        $length = strlen($data);
        if ($length < 4 || !in_array(substr($data, 0, 4), [self::SIGNATURE, "KKD\0"], true)) {
            return $map;
        }
        $names = [];
        $offset = 4;
        while ($offset + 6 <= $length) {
            $blockLength = self::uint32($data, $offset);
            $type = self::uint16($data, $offset + 4);
            if ($blockLength < 6 || $offset + $blockLength > $length) {
                break;
            }
            $block = substr($data, $offset + 6, $blockLength - 6);
            $offset += $blockLength;
            if (self::BLOCK_NAMES === $type) {
                $position = 0;
                while ($position + 2 <= strlen($block)) {
                    $nameLength = self::uint16($block, $position);
                    $names[] = (string) mb_convert_encoding(substr($block, $position + 2, $nameLength), 'UTF-8', 'UTF-16LE');
                    $position += 2 + $nameLength;
                }

                continue;
            }
            $mapName = '';
            $position = 0;
            if (strlen($block) >= 4) {
                $nameBlockLength = self::uint32($block, 0);
                if ($nameBlockLength > 6) {
                    $mapName = (string) mb_convert_encoding(substr($block, 6, self::uint16($block, 4)), 'UTF-8', 'UTF-16LE');
                }
                $position = max(4, $nameBlockLength);
            }
            while ($position + 8 <= strlen($block)) {
                $valueLength = self::uint16($block, $position);
                if ($valueLength < 8) {
                    break;
                }
                $ddl = 0 !== ord($block[$position + 2]);
                $dataType = ord($block[$position + 3]);
                $name = $names[self::uint16($block, $position + 4)] ?? '';
                $value = substr($block, $position + 8, self::uint16($block, $position + 6));
                $map->maps[$mapName][$name] = [$dataType, self::decodeValue($dataType, $value), $ddl];
                $position += $valueLength;
            }
        }

        return $map;
    }

    public function isEmpty(): bool
    {
        foreach ($this->maps as $properties) {
            if (!empty($properties)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Value of a property of the object ($map = '') or of a column (case insensitive names).
     */
    public function get(string $map, string $name): mixed
    {
        foreach ($this->maps as $mapName => $properties) {
            if (0 !== strcasecmp($mapName, $map)) {
                continue;
            }
            foreach ($properties as $propertyName => $property) {
                if (0 === strcasecmp($propertyName, $name)) {
                    return $property[1];
                }
            }
        }

        return null;
    }

    /**
     * @param int $type JetFormat::TYPE_* (BOOLEAN, BYTE, INT, LONG, TEXT, MEMO, BINARY)
     */
    public function set(string $map, string $name, int $type, mixed $value, bool $ddl = false): self
    {
        if (null === $value) {
            unset($this->maps[$map][$name]);

            return $this;
        }
        $this->maps[$map][$name] = [$type, $value, $ddl];

        return $this;
    }

    public function write(): string
    {
        $names = [];
        foreach ($this->maps as $properties) {
            foreach (array_keys($properties) as $name) {
                $names[$name] ??= count($names);
            }
        }
        if (empty($names)) {
            return '';
        }
        $nameList = '';
        foreach (array_keys($names) as $name) {
            $nameList .= self::name((string) $name);
        }
        $out = self::SIGNATURE . self::block(self::BLOCK_NAMES, $nameList);
        foreach ($this->maps as $mapName => $properties) {
            if (empty($properties)) {
                continue;
            }
            $mapNameBytes = self::name((string) $mapName);
            $content = pack('V', 4 + strlen($mapNameBytes)) . $mapNameBytes;
            foreach ($names as $name => $index) {
                if (!isset($properties[$name])) {
                    continue;
                }
                [$type, $value, $ddl] = $properties[$name];
                $bytes = self::encodeValue($type, $value);
                $content .= pack('v', 8 + strlen($bytes)) . chr($ddl ? 1 : 0) . chr($type) . pack('vv', $index, strlen($bytes)) . $bytes;
            }
            $out .= self::block('' === (string) $mapName ? self::BLOCK_OBJECT : self::BLOCK_COLUMN, $content);
        }

        return $out;
    }

    private static function block(int $type, string $content): string
    {
        return pack('V', 6 + strlen($content)) . pack('v', $type) . $content;
    }

    private static function name(string $name): string
    {
        $utf16 = (string) mb_convert_encoding($name, 'UTF-16LE', 'UTF-8');

        return pack('v', strlen($utf16)) . $utf16;
    }

    private static function decodeValue(int $type, string $bytes): mixed
    {
        switch ($type) {
            case JetFormat::TYPE_BOOLEAN:
                return '' !== $bytes && "\0" !== $bytes[0];
            case JetFormat::TYPE_BYTE:
                return ord($bytes[0] ?? "\0");
            case JetFormat::TYPE_INT:
                $value = self::uint16($bytes, 0);

                return $value >= 0x8000 ? $value - 0x10000 : $value;
            case JetFormat::TYPE_LONG:
                $value = self::uint32($bytes, 0);

                return $value >= 0x80000000 ? $value - 0x100000000 : $value;
            case JetFormat::TYPE_TEXT:
            case JetFormat::TYPE_MEMO:
                return ValueCodec::decodeText($bytes);
            default:
                return $bytes;
        }
    }

    private static function encodeValue(int $type, mixed $value): string
    {
        switch ($type) {
            case JetFormat::TYPE_BOOLEAN:
                return $value ? "\x01" : "\x00";
            case JetFormat::TYPE_BYTE:
                return chr(((int) $value) & 0xFF);
            case JetFormat::TYPE_INT:
                return pack('v', ((int) $value) & 0xFFFF);
            case JetFormat::TYPE_LONG:
                return pack('V', ((int) $value) & 0xFFFFFFFF);
            case JetFormat::TYPE_TEXT:
            case JetFormat::TYPE_MEMO:
                return (string) mb_convert_encoding((string) $value, 'UTF-16LE', 'UTF-8');
            default:
                return (string) $value;
        }
    }

    private static function uint16(string $data, int $offset): int
    {
        return isset($data[$offset + 1]) ? ord($data[$offset]) | (ord($data[$offset + 1]) << 8) : 0;
    }

    private static function uint32(string $data, int $offset): int
    {
        return isset($data[$offset + 3]) ? (int) unpack('V', $data, $offset)[1] : 0;
    }
}
