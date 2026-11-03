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

namespace PhpOffice\PhpDatabase\Reader\Sqlite;

/**
 * Write-ahead log of a database in WAL mode (https://www.sqlite.org/fileformat2.html#walformat).
 *
 * The committed frames are applied to the content of the main file, like a checkpoint does.
 */
class Wal
{
    private const MAGIC = 0x377F0682;
    private const HEADER_SIZE = 32;
    private const FRAME_HEADER_SIZE = 24;

    /**
     * Content of the database with the committed transactions of the WAL file.
     * An invalid or empty WAL file (no valid commit frame) leaves the database unchanged.
     */
    public static function apply(string $database, string $wal): string
    {
        if (strlen($wal) < self::HEADER_SIZE) {
            return $database;
        }
        $header = unpack('Nmagic/Nversion/NpageSize/Ncheckpoint/Nsalt1/Nsalt2/Nchecksum1/Nchecksum2', $wal);
        if (false === $header || ($header['magic'] & 0xFFFFFFFE) !== self::MAGIC) {
            return $database;
        }
        $bigEndian = 1 === ($header['magic'] & 1);
        $pageSize = 1 === $header['pageSize'] ? 65536 : $header['pageSize'];
        if ($pageSize < 512 || ($pageSize & ($pageSize - 1)) !== 0) {
            return $database;
        }
        $checksum = self::checksum(substr($wal, 0, 24), [0, 0], $bigEndian);
        if ($checksum !== [$header['checksum1'], $header['checksum2']]) {
            return $database;
        }

        $frameSize = self::FRAME_HEADER_SIZE + $pageSize;
        $pages = [];
        $pending = [];
        $databaseSize = null;
        for ($offset = self::HEADER_SIZE; $offset + $frameSize <= strlen($wal); $offset += $frameSize) {
            $frame = unpack('Npage/Nsize/Nsalt1/Nsalt2/Nchecksum1/Nchecksum2', $wal, $offset);
            if (false === $frame || $frame['salt1'] !== $header['salt1'] || $frame['salt2'] !== $header['salt2'] || $frame['page'] < 1) {
                break;
            }
            $data = substr($wal, $offset + self::FRAME_HEADER_SIZE, $pageSize);
            $checksum = self::checksum(substr($wal, $offset, 8) . $data, $checksum, $bigEndian);
            if ($checksum !== [$frame['checksum1'], $frame['checksum2']]) {
                break;
            }
            $pending[$frame['page']] = $data;
            if (0 !== $frame['size']) {
                // Commit frame: the transaction is complete
                $pages = array_replace($pages, $pending);
                $pending = [];
                $databaseSize = $frame['size'];
            }
        }
        if (null === $databaseSize) {
            return $database;
        }

        $database = str_pad(substr($database, 0, $databaseSize * $pageSize), $databaseSize * $pageSize, "\0");
        foreach ($pages as $number => $data) {
            if ($number <= $databaseSize) {
                $database = substr_replace($database, $data, ($number - 1) * $pageSize, $pageSize);
            }
        }

        return $database;
    }

    /**
     * Cumulative checksum of the WAL format (pairs of 32-bit words).
     *
     * @param array{0: int, 1: int} $seed
     *
     * @return array{0: int, 1: int}
     */
    private static function checksum(string $data, array $seed, bool $bigEndian): array
    {
        [$s0, $s1] = $seed;
        $words = array_values(unpack($bigEndian ? 'N*' : 'V*', $data) ?: []);
        for ($i = 0, $count = count($words); $i + 1 < $count; $i += 2) {
            $s0 = ($s0 + $words[$i] + $s1) & 0xFFFFFFFF;
            $s1 = ($s1 + $words[$i + 1] + $s0) & 0xFFFFFFFF;
        }

        return [$s0, $s1];
    }
}
