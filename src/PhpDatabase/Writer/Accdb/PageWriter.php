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

use PhpOffice\PhpDatabase\Shared\Access\JetFormat;

/**
 * Fill data pages (rows of a table) or long value pages (LVAL) with rows.
 */
class PageWriter
{
    private const MAX_ROWS = 255;

    /**
     * @var AccessBuilder
     */
    private $builder;

    /**
     * @var int
     */
    private $tdef;

    /**
     * @var bool
     */
    private $longValues;

    /**
     * @var int
     */
    private $page = 0;

    /**
     * @var array<int, string>
     */
    private $rows = [];

    /**
     * @var int
     */
    private $used = 0;

    /**
     * @var array<int, string>
     */
    private $pages = [];

    public function __construct(AccessBuilder $builder, int $tdef, bool $longValues)
    {
        $this->builder = $builder;
        $this->tdef = $tdef;
        $this->longValues = $longValues;
    }

    /**
     * Add a row, return its pointer (page << 8 | row).
     */
    public function add(string $row): int
    {
        $size = strlen($row) + 2;
        if (0 === $this->page || count($this->rows) >= self::MAX_ROWS || $this->used + $size > JetFormat::PAGE_SIZE - JetFormat::OFFSET_DATA_ROW_START) {
            $this->flush();
            $this->page = $this->builder->allocate();
        }
        $this->rows[] = $row;
        $this->used += $size;

        return ($this->page << 8) | (count($this->rows) - 1);
    }

    /**
     * @return array<int, string> content of the pages, by page number
     */
    public function finish(): array
    {
        $this->flush();

        return $this->pages;
    }

    private function flush(): void
    {
        if (0 !== $this->page) {
            $this->pages[$this->page] = self::dataPage($this->tdef, $this->rows, $this->longValues);
        }
        $this->page = 0;
        $this->rows = [];
        $this->used = 0;
    }

    /**
     * @param array<int, string> $rows
     */
    public static function dataPage(int $tdef, array $rows, bool $longValues): string
    {
        $offsets = '';
        $content = '';
        $end = JetFormat::PAGE_SIZE;
        foreach ($rows as $row) {
            $end -= strlen($row);
            $offsets .= pack('v', $end);
            $content = $row . $content;
        }
        $free = $end - JetFormat::OFFSET_DATA_ROW_START - strlen($offsets);
        $header = "\x01\x01" . pack('v', $free) . ($longValues ? 'LVAL' : pack('V', $tdef)) . pack('V', 0) . pack('v', count($rows));

        return $header . $offsets . str_repeat("\0", $free) . $content;
    }
}
