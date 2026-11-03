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

namespace PhpOffice\PhpDatabase\Writer\Sqlite;

use PhpOffice\PhpDatabase\Shared\Sqlite\PayloadSize;
use PhpOffice\PhpDatabase\Shared\Sqlite\Record;
use PhpOffice\PhpDatabase\Shared\Sqlite\SqliteFile;

/**
 * Builder of SQLite 3 files: table b-trees, index b-trees and overflow pages (UTF-8, no free page).
 */
class SqliteBuilder
{
    /**
     * @var int
     */
    private $pageSize;

    /**
     * Content of the pages, by page number (page 1 is written at the end).
     *
     * @var array<int, string>
     */
    private $pages = [];

    /**
     * Rows of sqlite_schema.
     *
     * @var array<int, array<int, null|int|string>>
     */
    private $schema = [];

    public function __construct(int $pageSize = 4096)
    {
        $this->pageSize = $pageSize;
        // Page 1: root of sqlite_schema
        $this->pages[1] = '';
    }

    /**
     * Add a table.
     *
     * @param array<int, array{0: int, 1: array<int, mixed>}> $rows rowid and values, sorted by rowid
     *
     * @return int root page
     */
    public function addTable(string $name, string $sql, array $rows): int
    {
        $cells = [];
        foreach ($rows as [$rowid, $values]) {
            $payload = Record::encode($values);
            $cells[] = ['key' => $rowid, 'cell' => Record::writeVarint(strlen($payload)) . Record::writeVarint($rowid) . $this->localPayload($payload, false)];
        }
        $root = $this->buildTableTree($cells, null);
        $this->schema[] = ['table', $name, $name, $root, $sql];

        return $root;
    }

    /**
     * Add an index.
     *
     * @param array<int, array<int, mixed>> $entries records of the index, will be sorted
     * @param array<int, bool> $descending positions of the columns sorted in descending order
     */
    public function addIndex(string $name, string $table, ?string $sql, array $entries, array $descending = []): int
    {
        $root = $this->buildIndexTree(array_map([Record::class, 'encode'], self::sortRecords($entries, $descending)));
        $this->schema[] = ['index', $name, $table, $root, $sql];

        return $root;
    }

    /**
     * Add a WITHOUT ROWID table: an index b-tree holding the records (primary key columns first).
     *
     * @param array<int, array<int, mixed>> $records will be sorted
     */
    public function addWithoutRowidTable(string $name, string $sql, array $records): int
    {
        $root = $this->buildIndexTree(array_map([Record::class, 'encode'], self::sortRecords($records, [])));
        $this->schema[] = ['table', $name, $name, $root, $sql];

        return $root;
    }

    /**
     * Add an object without b-tree (view, trigger).
     */
    public function addSchemaEntry(string $type, string $name, string $table, string $sql): void
    {
        $this->schema[] = [$type, $name, $table, 0, $sql];
    }

    /**
     * Sort the records of an index (descending columns: by position).
     *
     * @param array<int, array<int, mixed>> $records
     * @param array<int, bool> $descending
     *
     * @return array<int, array<int, mixed>>
     */
    public static function sortRecords(array $records, array $descending): array
    {
        $descending = array_filter($descending);
        if (empty($descending)) {
            usort($records, [Record::class, 'compareRecords']);

            return $records;
        }
        usort($records, static function (array $a, array $b) use ($descending): int {
            foreach ($a as $index => $value) {
                $result = Record::compareValues($value, $b[$index] ?? null);
                if (0 !== $result) {
                    return isset($descending[$index]) ? -$result : $result;
                }
            }

            return count($a) <=> count($b);
        });

        return $records;
    }

    /**
     * Content of the file.
     */
    public function build(int $userVersion = 0): string
    {
        $cells = [];
        foreach ($this->schema as $index => $row) {
            $payload = Record::encode($row);
            $cells[] = ['key' => $index + 1, 'cell' => Record::writeVarint(strlen($payload)) . Record::writeVarint($index + 1) . $this->localPayload($payload, false)];
        }
        $this->buildTableTree($cells, 1);
        ksort($this->pages);
        $pageCount = count($this->pages);
        $header = SqliteFile::SIGNATURE
            . pack('n', 65536 === $this->pageSize ? 1 : $this->pageSize)
            . "\x01\x01\x00\x40\x20\x20" // versions, reserved bytes, payload fractions
            . pack('N', 1)               // file change counter
            . pack('N', $pageCount)      // database size in pages
            . pack('N', 0) . pack('N', 0) // freelist trunk page, number of freelist pages
            . pack('N', 1)               // schema cookie
            . pack('N', 4)               // schema format number
            . pack('N', 0)               // default page cache size
            . pack('N', 0)               // largest root b-tree page (no auto-vacuum)
            . pack('N', 1)               // text encoding: UTF-8
            . pack('N', $userVersion)
            . pack('N', 0)               // incremental vacuum
            . pack('N', 0)               // application id
            . str_repeat("\0", 20)
            . pack('N', 1)               // version-valid-for
            . pack('N', 3046000);        // SQLITE_VERSION_NUMBER
        $this->pages[1] = $header . substr($this->pages[1], 100);

        return implode('', $this->pages);
    }

    /**
     * @param array<int, array{key: int, cell: string}> $cells
     */
    private function buildTableTree(array $cells, ?int $rootPage): int
    {
        // Page 1 has 100 bytes less: all the pages of sqlite_schema are filled with this limit
        $capacity = $this->pageSize - (1 === $rootPage ? 100 : 0);
        $level = [];
        foreach ($this->pack($cells, $capacity - 8) as $group) {
            $level[] = ['cells' => $group, 'key' => end($group)['key'] ?? 0];
        }
        if (empty($level)) {
            $level[] = ['cells' => [], 'key' => 0];
        }
        $leaf = true;
        while (true) {
            $isRoot = 1 === count($level);
            $children = [];
            foreach ($level as $node) {
                $page = $isRoot && null !== $rootPage ? $rootPage : $this->allocate();
                $this->pages[$page] = $this->page(
                    $leaf ? 0x0d : 0x05,
                    array_column($node['cells'], 'cell'),
                    $node['right'] ?? null,
                    1 === $page
                );
                $children[] = ['page' => $page, 'key' => $node['key']];
            }
            if ($isRoot) {
                return $children[0]['page'];
            }
            // Interior level: one cell per child (child page + max key), the last child of a page being the right pointer
            $cells = [];
            foreach ($children as $child) {
                $cells[] = ['key' => $child['key'], 'cell' => pack('N', $child['page']) . Record::writeVarint($child['key']), 'page' => $child['page']];
            }
            $level = [];
            foreach ($this->pack($cells, $capacity - 12, 2) as $group) {
                $last = array_pop($group);
                $level[] = ['cells' => $group, 'key' => $last['key'], 'right' => $last['page']];
            }
            $leaf = false;
        }
    }

    /**
     * Build an index b-tree (keys are stored once: in the leaves or as dividers in interior pages).
     *
     * @param array<int, string> $payloads sorted records
     */
    private function buildIndexTree(array $payloads): int
    {
        $entries = [];
        foreach ($payloads as $payload) {
            $entries[] = Record::writeVarint(strlen($payload)) . $this->localPayload($payload, true);
        }
        $usable = $this->pageSize - 8;
        // Leaves separated by dividers
        $leaves = [];
        $dividers = [];
        $current = [];
        $size = 0;
        $count = count($entries);
        for ($i = 0; $i < $count; ++$i) {
            $cellSize = strlen($entries[$i]) + 2;
            if (!empty($current) && $size + $cellSize > $usable) {
                // This entry becomes the divider between this leaf and the next one
                if ($i === $count - 1) {
                    // Keep at least one entry for the last leaf
                    $dividers[] = array_pop($current);
                    $leaves[] = $current;
                    $current = [$entries[$i]];

                    break;
                }
                $leaves[] = $current;
                $dividers[] = $entries[$i];
                $current = [];
                $size = 0;

                continue;
            }
            $current[] = $entries[$i];
            $size += $cellSize;
        }
        $leaves[] = $current;
        $pages = [];
        foreach ($leaves as $leaf) {
            $page = $this->allocate();
            $this->pages[$page] = $this->page(0x0a, $leaf, null, false);
            $pages[] = $page;
        }
        // Interior levels: divider i is between child i and child i + 1
        while (count($pages) > 1) {
            $groups = [];
            $current = [];
            $size = 0;
            foreach ($pages as $i => $page) {
                $cellSize = 6 + strlen($dividers[$i] ?? '');
                if (count($current) >= 2 && $size + $cellSize > $this->pageSize - 12) {
                    $groups[] = $current;
                    $current = [];
                    $size = 0;
                }
                $current[] = $i;
                $size += $cellSize;
            }
            if (1 === count($current) && !empty($groups)) {
                array_unshift($current, array_pop($groups[count($groups) - 1]));
            }
            $groups[] = $current;
            $nextPages = [];
            $nextDividers = [];
            foreach ($groups as $group) {
                $last = array_pop($group);
                $cells = [];
                foreach ($group as $i) {
                    $cells[] = pack('N', $pages[$i]) . $dividers[$i];
                }
                $page = $this->allocate();
                $this->pages[$page] = $this->page(0x02, $cells, $pages[$last], false);
                $nextPages[] = $page;
                if (isset($dividers[$last])) {
                    $nextDividers[] = $dividers[$last];
                }
            }
            $pages = $nextPages;
            $dividers = $nextDividers;
        }

        return $pages[0];
    }

    /**
     * Split cells in groups fitting in a page.
     *
     * @template T of array
     *
     * @param array<int, T> $cells
     *
     * @return array<int, array<int, T>>
     */
    private function pack(array $cells, int $capacity, int $minimum = 1): array
    {
        $groups = [];
        $current = [];
        $size = 0;
        foreach ($cells as $cell) {
            $cellSize = strlen($cell['cell']) + 2;
            if (count($current) >= $minimum && $size + $cellSize > $capacity) {
                $groups[] = $current;
                $current = [];
                $size = 0;
            }
            $current[] = $cell;
            $size += $cellSize;
        }
        if (!empty($current)) {
            // An interior page needs at least 2 children: borrow one from the previous group
            if (count($current) < $minimum && !empty($groups)) {
                array_unshift($current, array_pop($groups[count($groups) - 1]));
            }
            $groups[] = $current;
        }

        return $groups;
    }

    /**
     * Local part of a payload followed by the number of its first overflow page.
     */
    private function localPayload(string $payload, bool $index): string
    {
        $size = strlen($payload);
        $local = PayloadSize::local($size, $this->pageSize, $index);
        if ($local >= $size) {
            return $payload;
        }
        $chunks = str_split(substr($payload, $local), $this->pageSize - 4);
        $pages = [];
        foreach ($chunks as $chunk) {
            $pages[] = $this->allocate();
        }
        foreach ($chunks as $position => $chunk) {
            $next = $pages[$position + 1] ?? 0;
            $this->pages[$pages[$position]] = str_pad(pack('N', $next) . $chunk, $this->pageSize, "\0");
        }

        return substr($payload, 0, $local) . pack('N', $pages[0]);
    }

    /**
     * @param array<int, string> $cells
     */
    private function page(int $type, array $cells, ?int $rightPointer, bool $first): string
    {
        $headerOffset = $first ? 100 : 0;
        $interior = 0x02 === $type || 0x05 === $type;
        $content = '';
        $pointers = '';
        $end = $this->pageSize;
        foreach ($cells as $cell) {
            $end -= strlen($cell);
            $pointers .= pack('n', $end);
            $content = $cell . $content;
        }
        $contentStart = empty($cells) ? $this->pageSize : $end;
        $header = chr($type) . pack('n', 0) . pack('n', count($cells)) . pack('n', 65536 === $contentStart ? 0 : $contentStart) . "\0";
        if ($interior) {
            $header .= pack('N', (int) $rightPointer);
        }
        $page = str_repeat("\0", $headerOffset) . $header . $pointers;
        $page .= str_repeat("\0", $end - strlen($page)) . $content;

        return $page;
    }

    private function allocate(): int
    {
        $page = count($this->pages) + 1;
        $this->pages[$page] = '';

        return $page;
    }
}
