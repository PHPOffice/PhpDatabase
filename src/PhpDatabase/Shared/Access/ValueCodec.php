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

use DateTimeImmutable;
use DateTimeZone;

/**
 * Encoding of the Access values: text (with the "Unicode compression"), dates, money, numeric, GUID.
 */
final class ValueCodec
{
    private const COMPRESSED_FORMATS = ['jpg', 'zip', 'gz', 'bz2', 'z', '7z', 'cab', 'rar', 'mp3', 'mpg'];

    /**
     * Decode a text value (UTF-16LE, possibly compressed) to UTF-8.
     */
    public static function decodeText(string $bytes): string
    {
        if (strlen($bytes) >= 2 && "\xff\xfe" === substr($bytes, 0, 2)) {
            $utf16 = '';
            $compressed = true;
            $length = strlen($bytes);
            for ($i = 2; $i < $length;) {
                if ("\0" === $bytes[$i]) {
                    $compressed = !$compressed;
                    ++$i;

                    continue;
                }
                if ($compressed) {
                    $utf16 .= $bytes[$i] . "\0";
                    ++$i;
                } else {
                    $utf16 .= substr($bytes, $i, 2);
                    $i += 2;
                }
            }
            $bytes = $utf16;
        }
        if (strlen($bytes) % 2 === 1) {
            $bytes = substr($bytes, 0, -1);
        }

        return (string) mb_convert_encoding($bytes, 'UTF-8', 'UTF-16LE');
    }

    /**
     * Encode a text value; compressed when all the characters are in U+0001-U+00FF (like Access).
     */
    public static function encodeText(string $value, bool $compress = true): string
    {
        $utf16 = (string) mb_convert_encoding($value, 'UTF-16LE', 'UTF-8');
        if (!$compress || '' === $utf16) {
            return $utf16;
        }
        $compressed = "\xff\xfe";
        for ($i = 0, $length = strlen($utf16); $i < $length; $i += 2) {
            if ("\0" !== $utf16[$i + 1] || "\0" === $utf16[$i]) {
                return $utf16;
            }
            $compressed .= $utf16[$i];
        }

        return $compressed;
    }

    /**
     * Access date (days since 1899-12-30, time as the fraction) to a date.
     */
    public static function decodeDate(float $value, ?DateTimeZone $timezone = null): DateTimeImmutable
    {
        $timezone ??= new DateTimeZone(date_default_timezone_get());
        $days = (int) $value;
        // Negative dates: the time part is still positive
        $fraction = abs($value - $days);
        $microseconds = (int) round($fraction * 86400000000);
        if ($microseconds >= 86400000000) {
            ++$days;
            $microseconds = 0;
        }
        $seconds = intdiv($microseconds, 1000000);
        $micro = $microseconds % 1000000;
        // Rounded to the millisecond, the precision of Access
        $micro = (int) (round($micro / 1000) * 1000);
        if ($micro >= 1000000) {
            ++$seconds;
            $micro = 0;
        }
        $date = new DateTimeImmutable('1899-12-30 00:00:00', $timezone);

        return $date->modify(sprintf('%+d days', $days))->setTime(intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60, $micro);
    }

    public static function encodeDate(DateTimeImmutable $date): float
    {
        $epoch = new DateTimeImmutable('1899-12-30 00:00:00', $date->getTimezone());
        $midnight = $date->setTime(0, 0);
        $days = (int) round(($midnight->getTimestamp() - $epoch->getTimestamp()) / 86400);
        $fraction = ((int) $date->format('G') * 3600 + (int) $date->format('i') * 60 + (int) $date->format('s') + (int) $date->format('u') / 1000000) / 86400;

        return $days >= 0 ? $days + $fraction : $days - $fraction;
    }

    /**
     * Money: signed 64 bits integer, value * 10000.
     */
    /**
     * "Date/Time Extended" (Access 2019): ASCII "<19 digits: days since 0001-01-01>:<12 digits: seconds>
     * <7 digits: 100 ns>:7\0" (42 bytes).
     */
    public static function decodeExtendedDate(string $bytes): ?DateTimeImmutable
    {
        if (1 !== preg_match('/^(\d{19}):(\d{12})(\d{7})/', $bytes, $match)) {
            return null;
        }
        $date = (new DateTimeImmutable('0001-01-01 00:00:00', new DateTimeZone(date_default_timezone_get())))
            ->modify('+' . (int) $match[1] . ' days')
            ->modify('+' . (int) $match[2] . ' seconds');

        return $date->setTime((int) $date->format('H'), (int) $date->format('i'), (int) $date->format('s'), intdiv((int) $match[3], 10));
    }

    public static function encodeExtendedDate(DateTimeImmutable $date): string
    {
        $base = new DateTimeImmutable('0001-01-01 00:00:00', $date->getTimezone());
        $days = (int) $base->diff($date->setTime(0, 0))->format('%a');
        $seconds = (int) $date->format('H') * 3600 + (int) $date->format('i') * 60 + (int) $date->format('s');

        return sprintf('%019d:%012d%07d:7', $days, $seconds, (int) $date->format('u') * 10) . "\0";
    }

    /**
     * File of an attachment (FileData column): flag (1 when compressed with zlib), length of the content,
     * then the content: header length, 1, length of the type, type (UTF-16LE, ended by a null), data.
     *
     * @return array{type: string, data: string}
     */
    public static function decodeAttachment(string $bytes): array
    {
        $header = unpack('Vflag/Vlength', $bytes);
        if (false === $header || strlen($bytes) < 8) {
            return ['type' => '', 'data' => ''];
        }
        $content = 0 !== $header['flag'] ? @zlib_decode(substr($bytes, 8)) : substr($bytes, 8);
        if (false === $content || strlen($content) < 12) {
            return ['type' => '', 'data' => ''];
        }
        $info = unpack('VheaderLength/Vunknown/VtypeLength', $content);
        $headerLength = false === $info ? 12 : (int) $info['headerLength'];
        $typeLength = false === $info ? 0 : (int) $info['typeLength'];
        $type = (string) mb_convert_encoding(substr($content, 12, max(0, $typeLength - 1) * 2), 'UTF-8', 'UTF-16LE');

        return ['type' => $type, 'data' => substr($content, $headerLength)];
    }

    public static function encodeAttachment(string $type, string $data): string
    {
        $encodedType = (string) mb_convert_encoding($type, 'UTF-16LE', 'UTF-8') . "\0\0";
        $content = pack('VVV', 12 + strlen($encodedType), 1, intdiv(strlen($encodedType), 2)) . $encodedType . $data;
        // Formats already compressed are stored as is (like Access)
        if (in_array(strtolower($type), self::COMPRESSED_FORMATS, true)) {
            return pack('VV', 0, strlen($content)) . $content;
        }

        return pack('VV', 1, strlen($content)) . (string) zlib_encode($content, ZLIB_ENCODING_DEFLATE, 3);
    }

    public static function decodeMoney(string $bytes): string
    {
        $unpacked = unpack('P', $bytes);
        $value = false === $unpacked ? 0 : (int) $unpacked[1];
        $negative = $value < 0;
        $digits = $negative ? ltrim((string) $value, '-') : (string) $value;
        $digits = str_pad($digits, 5, '0', STR_PAD_LEFT);

        return ($negative ? '-' : '') . substr($digits, 0, -4) . '.' . substr($digits, -4);
    }

    /**
     * Numeric: sign byte (0x80: negative) then the unscaled value on 128 bits,
     * as four 32 bits little-endian words from the most significant one.
     */
    public static function decodeNumeric(string $bytes, int $scale): string
    {
        $negative = (ord($bytes[0]) & 0x80) !== 0;
        $words = unpack('V4', substr($bytes, 1, 16));
        $bigEndian = '';
        foreach ($words === false ? [] : $words as $word) {
            $bigEndian .= pack('N', $word);
        }
        $digits = self::bytesToDecimal($bigEndian);
        if ($scale > 0) {
            $digits = str_pad($digits, $scale + 1, '0', STR_PAD_LEFT);
            $digits = substr($digits, 0, -$scale) . '.' . substr($digits, -$scale);
        }

        return ($negative && '' !== trim($digits, '0.') ? '-' : '') . $digits;
    }

    public static function encodeNumeric(string $value, int $scale): string
    {
        $value = \PhpOffice\PhpDatabase\Column::formatDecimal($value, $scale);
        $negative = str_starts_with($value, '-');
        $digits = ltrim(str_replace(['-', '.'], '', $value), '0');
        $bigEndian = self::decimalToBytes('' === $digits ? '0' : $digits, 16);
        $result = $negative ? "\x80" : "\x00";
        foreach (str_split($bigEndian, 4) as $word) {
            $result .= strrev($word);
        }

        return $result;
    }

    public static function encodeMoney(string $value): string
    {
        $value = \PhpOffice\PhpDatabase\Column::formatDecimal($value, 4);

        return pack('P', (int) str_replace('.', '', $value));
    }

    /**
     * GUID: 16 bytes (Data1-3 little-endian) to {XXXXXXXX-XXXX-XXXX-XXXX-XXXXXXXXXXXX}.
     */
    public static function decodeGuid(string $bytes): string
    {
        $hex = bin2hex(strrev(substr($bytes, 0, 4))) . '-' . bin2hex(strrev(substr($bytes, 4, 2))) . '-'
            . bin2hex(strrev(substr($bytes, 6, 2))) . '-' . bin2hex(substr($bytes, 8, 2)) . '-' . bin2hex(substr($bytes, 10, 6));

        return '{' . strtoupper($hex) . '}';
    }

    public static function encodeGuid(string $guid): ?string
    {
        $hex = strtolower((string) preg_replace('/[^0-9a-fA-F]/', '', $guid));
        if (32 !== strlen($hex)) {
            return null;
        }
        $bytes = (string) hex2bin($hex);

        return strrev(substr($bytes, 0, 4)) . strrev(substr($bytes, 4, 2)) . strrev(substr($bytes, 6, 2)) . substr($bytes, 8);
    }

    private static function bytesToDecimal(string $bytes): string
    {
        $digits = '0';
        for ($i = 0, $length = strlen($bytes); $i < $length; ++$i) {
            $carry = ord($bytes[$i]);
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

        return $digits;
    }

    private static function decimalToBytes(string $digits, int $size): string
    {
        $bytes = '';
        while ('0' !== $digits && '' !== $digits) {
            // Division by 256
            $quotient = '';
            $remainder = 0;
            for ($i = 0, $length = strlen($digits); $i < $length; ++$i) {
                $value = $remainder * 10 + (int) $digits[$i];
                $quotient .= intdiv($value, 256);
                $remainder = $value % 256;
            }
            $bytes = chr($remainder) . $bytes;
            $digits = ltrim($quotient, '0');
        }

        return str_pad(substr($bytes, -$size), $size, "\0", STR_PAD_LEFT);
    }
}
