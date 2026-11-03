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

namespace PhpOffice\PhpDatabase\Writer\Accdb;

use PhpOffice\PhpDatabase\Exception\WriterException;

/**
 * Index keys of the text columns with the "General" sort orders of Access (language 1033):
 * "General - Legacy" (Access 2000-2007) and "General" (Access 2010+).
 *
 * The code tables (resources/Access/index_codes_*.txt) and the algorithm come from Jackcess
 * (GeneralLegacyIndexCodes, Apache License 2.0). Each line describes the codes of one UTF-16 code unit:
 * S (simple: inline bytes), I (international: inline and extra bytes), U (unprintable bytes),
 * P (unprintable, extra byte modifier), Z (international with a "crazy" flag), G (significant), X (ignored).
 */
class TextIndexCodes
{
    public const GENERAL_LEGACY = 0;
    public const GENERAL = 1;

    private const MAX_LENGTH = 255;
    private const END_TEXT = "\x01";
    private const END_EXTRA_TEXT = "\x00";
    private const UNPRINTABLE_COUNT_START = 7;
    private const UNPRINTABLE_COUNT_MULTIPLIER = 4;
    private const UNPRINTABLE_OFFSET_FLAGS = 0x8000;
    private const UNPRINTABLE_MIDFIX = "\x06";
    private const INTERNATIONAL_EXTRA_PLACEHOLDER = 0x02;
    private const CRAZY_CODE_START = 0x80;
    private const CRAZY_CODE_1 = 0x02;
    private const CRAZY_CODE_2 = 0x03;
    private const CRAZY_CODES_SUFFIX = "\xFF\x02\x80\xFF\x80";
    private const CRAZY_CODES_UNPRINT_SUFFIX = "\xFF";

    /**
     * @var array<int, self>
     */
    private static $instances = [];

    /**
     * Codes of the characters 0x00-0xFF.
     *
     * @var array<int, array{0: string, 1: null|string, 2: null|string, 3: null|string, 4: int, 5: int}>
     */
    private $codes = [];

    /**
     * Lines of the characters 0x100-0xFFFF (without the surrogates), parsed when used.
     *
     * @var null|array<int, string>
     */
    private $extLines;

    /**
     * @var array<int, array{0: string, 1: null|string, 2: null|string, 3: null|string, 4: int, 5: int}>
     */
    private $extCodes = [];

    /**
     * @var string
     */
    private $prefix;

    private function __construct(int $sortOrder)
    {
        $this->prefix = self::GENERAL === $sortOrder ? 'index_codes_gen' : 'index_codes_genleg';
        foreach (self::readLines($this->prefix . '.txt.gz') as $char => $line) {
            $this->codes[$char] = self::parse($line);
        }
    }

    public static function getInstance(int $sortOrder): self
    {
        return self::$instances[$sortOrder] ??= new self($sortOrder);
    }

    /**
     * Key of a non null text value (without the start flag of the entry).
     */
    public function encode(string $value, bool $ascending = true): string
    {
        $units = self::toCodeUnits($value);
        $out = '';
        $extraCodes = null;
        $extraChars = 0;
        $unprintablePrefixLength = 0;
        $unprintableCodes = null;
        $crazyCodes = null;
        $charOffset = 0;
        foreach ($units as $unit) {
            [$type, $inline, $extra, $unprintable, $modifier, $crazy] = $this->getCodes($unit);
            $currentOffset = $charOffset;
            if (null !== $inline) {
                $out .= $inline;
                ++$charOffset;
            }
            if ('S' === $type) {
                continue;
            }
            if (null !== $extra || 0 !== $modifier) {
                $extraCodes ??= '';
                if ($extraChars < $currentOffset) {
                    $fill = $currentOffset - $extraChars;
                    $extraCodes .= str_repeat(chr(self::INTERNATIONAL_EXTRA_PLACEHOLDER), $fill);
                    $extraChars += $fill;
                }
                if (null !== $extra) {
                    $extraCodes .= $extra;
                    ++$extraChars;
                } elseif ('' !== $extraCodes) {
                    $last = strlen($extraCodes) - 1;
                    $extraCodes[$last] = chr((ord($extraCodes[$last]) + $modifier) & 0xFF);
                } else {
                    $extraCodes .= chr($modifier);
                    $unprintablePrefixLength = 1;
                }
            }
            if (null !== $unprintable) {
                $unprintableCodes ??= '';
                $unprintOffset = $currentOffset;
                if (null !== $extraCodes) {
                    $unprintOffset = strlen($extraCodes) + ($currentOffset - $extraChars) - $unprintablePrefixLength;
                }
                $offset = (self::UNPRINTABLE_COUNT_START + self::UNPRINTABLE_COUNT_MULTIPLIER * $unprintOffset) | self::UNPRINTABLE_OFFSET_FLAGS;
                $unprintableCodes .= chr(($offset >> 8) & 0xFF) . chr($offset & 0xFF) . self::UNPRINTABLE_MIDFIX . $unprintable;
            }
            if (0 !== $crazy) {
                $crazyCodes ??= '';
                $crazyCodes .= chr($crazy);
            }
        }
        $out .= self::END_TEXT;
        $hasExtraCodes = null !== $extraCodes && '' !== ($extraCodes = self::trimTrailing($extraCodes, 0, self::INTERNATIONAL_EXTRA_PLACEHOLDER));
        $hasUnprintableCodes = null !== $unprintableCodes;
        $hasCrazyCodes = null !== $crazyCodes;
        if ($hasExtraCodes) {
            $out .= $extraCodes;
        }
        if ($hasCrazyCodes || $hasUnprintableCodes) {
            $out .= self::END_TEXT . self::END_TEXT;
            if ($hasCrazyCodes) {
                $out .= self::crazyCodes((string) $crazyCodes);
                if ($hasUnprintableCodes) {
                    $out .= self::CRAZY_CODES_UNPRINT_SUFFIX;
                }
            }
            if ($hasUnprintableCodes) {
                $out .= self::END_TEXT . $unprintableCodes;
            }
        }
        if (!$ascending) {
            $out = ~($out . self::END_EXTRA_TEXT);
        }

        return $out . self::END_EXTRA_TEXT;
    }

    /**
     * UTF-16 code units of the value, limited to 255 and without the trailing spaces.
     *
     * @return array<int, int>
     */
    private static function toCodeUnits(string $value): array
    {
        $utf16 = (string) mb_convert_encoding($value, 'UTF-16BE', 'UTF-8');
        $units = '' === $utf16 ? [] : array_values(unpack('n*', $utf16) ?: []);
        $units = array_slice($units, 0, self::MAX_LENGTH);
        while (!empty($units) && 0x20 === end($units)) {
            array_pop($units);
        }

        return $units;
    }

    /**
     * @return array{0: string, 1: null|string, 2: null|string, 3: null|string, 4: int, 5: int}
     */
    private function getCodes(int $unit): array
    {
        if ($unit <= 0xFF) {
            return $this->codes[$unit];
        }
        if ($unit >= 0xD800 && $unit <= 0xDFFF) {
            // Surrogates (characters outside of the BMP): not handled by Access sort orders
            return ['X', null, null, null, 0, 0];
        }
        if (!isset($this->extCodes[$unit])) {
            $this->extLines ??= self::readLines(str_replace('index_codes_', 'index_codes_ext_', $this->prefix) . '.txt.gz');
            $index = $unit - 0x100 - ($unit > 0xDFFF ? 0x800 : 0);
            $this->extCodes[$unit] = self::parse($this->extLines[$index] ?? 'X');
        }

        return $this->extCodes[$unit];
    }

    /**
     * @return array<int, string>
     */
    private static function readLines(string $resource): array
    {
        $path = dirname(__DIR__, 2) . '/Resources/Access/' . $resource;
        $content = is_file($path) ? @gzdecode((string) file_get_contents($path)) : false;
        if (false === $content) {
            throw new WriterException(sprintf('Access: missing resource "%s"', $resource));
        }

        return explode("\n", rtrim(str_replace("\r", '', $content), "\n"));
    }

    /**
     * Parse a line of a code table: type, inline bytes, extra bytes, unprintable bytes, extra byte modifier, crazy flag.
     *
     * @return array{0: string, 1: null|string, 2: null|string, 3: null|string, 4: int, 5: int}
     */
    private static function parse(string $line): array
    {
        $type = substr($line, 0, 1);
        $parts = explode(',', (string) substr($line, 1));
        $bytes = static function (string $hex): ?string {
            if ('' === $hex) {
                return null;
            }

            return (string) hex2bin(0 === strlen($hex) % 2 ? $hex : '0' . $hex);
        };
        switch ($type) {
            case 'S':
            case 'G':
                return [$type, $bytes($parts[0]), null, null, 0, 0];
            case 'I':
                return [$type, $bytes($parts[0]), $bytes($parts[1] ?? ''), null, 0, 0];
            case 'U':
                return [$type, null, null, $bytes($parts[0]), 0, 0];
            case 'P':
                return [$type, null, null, null, ord((string) $bytes($parts[0])), 0];
            case 'Z':
                return [$type, $bytes($parts[0]), $bytes($parts[1] ?? ''), null, 0, '1' === ($parts[2] ?? '') ? self::CRAZY_CODE_1 : self::CRAZY_CODE_2];
            default:
                return ['X', null, null, null, 0, 0];
        }
    }

    private static function trimTrailing(string $codes, int $minimum, int $maximum): string
    {
        $length = strlen($codes);
        while ($length > 0 && ord($codes[$length - 1]) >= $minimum && ord($codes[$length - 1]) <= $maximum) {
            --$length;
        }

        return substr($codes, 0, $length);
    }

    private static function crazyCodes(string $codes): string
    {
        $codes = self::trimTrailing($codes, self::CRAZY_CODE_2, self::CRAZY_CODE_2);
        $out = '';
        $current = self::CRAZY_CODE_START;
        $index = 0;
        for ($i = 0, $length = strlen($codes); $i < $length; ++$i) {
            $current |= (ord($codes[$i]) << ((2 - $index) * 2)) & 0xFF;
            if (3 === ++$index) {
                $out .= chr($current);
                $current = self::CRAZY_CODE_START;
                $index = 0;
            }
        }
        if ($index > 0) {
            $out .= chr($current);
        }

        return $out . self::CRAZY_CODES_SUFFIX;
    }
}
