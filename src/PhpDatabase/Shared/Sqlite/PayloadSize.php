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

/**
 * Part of a cell payload stored on the b-tree page, the rest going to overflow pages.
 */
final class PayloadSize
{
    public static function local(int $payloadSize, int $usableSize, bool $index): int
    {
        $maxLocal = $index ? intdiv(($usableSize - 12) * 64, 255) - 23 : $usableSize - 35;
        if ($payloadSize <= $maxLocal) {
            return $payloadSize;
        }
        $minLocal = intdiv(($usableSize - 12) * 32, 255) - 23;
        $local = $minLocal + (($payloadSize - $minLocal) % ($usableSize - 4));

        return $local <= $maxLocal ? $local : $minLocal;
    }
}
