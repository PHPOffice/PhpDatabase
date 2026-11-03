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

namespace PhpOffice\PhpDatabase\Tests\Writer;

use DateTimeImmutable;
use PhpOffice\PhpDatabase\IOFactory;
use PhpOffice\PhpDatabase\Tests\AbstractTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class RoundTripTest extends AbstractTestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function formats(): array
    {
        return [
            'sqlite' => ['Sqlite', 'sqlite'],
            'odb' => ['Odb', 'odb'],
            'accdb' => ['Accdb', 'accdb'],
            'mdb' => ['Accdb', 'mdb'],
        ];
    }

    #[DataProvider('formats')]
    public function testRoundTrip(string $format, string $extension): void
    {
        $database = $this->createSampleDatabase(300);
        $file = $this->getTemporaryFile($extension);
        IOFactory::createWriter($database, $format)->save($file);
        $loaded = IOFactory::createReader($format)->load($file);

        self::assertSame(2, $loaded->getTableCount());
        $table = $loaded->getTable('Clients é');
        self::assertNotNull($table);
        self::assertSame(['ID'], array_map(static fn ($column) => $column->getName(), $table->getPrimaryKeyColumns()));
        self::assertTrue($table->getColumn('ID')?->isAutoIncrement());
        self::assertEquals($database->getTable('Clients é')?->getRows(), $table->getRows());

        $pairs = $loaded->getTable('Pairs');
        self::assertNotNull($pairs);
        self::assertSame('abc', $pairs->getRow(0)['Code'] ?? null);
        self::assertSame(2, $pairs->getRow(1)['Number'] ?? null);
    }

    #[DataProvider('formats')]
    public function testEmptyDatabase(string $format, string $extension): void
    {
        $database = new \PhpOffice\PhpDatabase\Database();
        $database->createTable('Empty')->createColumn('Text');
        $file = $this->getTemporaryFile($extension);
        IOFactory::createWriter($database, $format)->save($file);
        $loaded = IOFactory::createReader($format)->load($file);
        self::assertSame(0, $loaded->getTable('Empty')?->getRowCount());
    }

    #[DataProvider('formats')]
    public function testSkipRows(string $format, string $extension): void
    {
        $file = $this->getTemporaryFile($extension);
        IOFactory::createWriter($this->createSampleDatabase(5), $format)->save($file);
        $loaded = IOFactory::createReader($format)->load($file, \PhpOffice\PhpDatabase\Reader\ReaderInterface::SKIP_ROWS);
        self::assertSame(0, $loaded->getTable('Clients é')?->getRowCount());
        self::assertSame(10, $loaded->getTable('Clients é')->getColumnCount());
    }

    public function testSqliteIsValidForSqlite3(): void
    {
        if (null === ($python = $this->findExecutable('python3'))) {
            self::markTestSkipped('python3 is not available');
        }
        $file = $this->getTemporaryFile('sqlite');
        IOFactory::save($this->createSampleDatabase(2000), $file);
        $script = 'import sqlite3,sys;c=sqlite3.connect(sys.argv[1]);'
            . 'print(c.execute("pragma integrity_check").fetchone()[0]);'
            . 'print(c.execute("select count(*), sum(Small) from \\"Clients é\\"").fetchone());'
            . 'print(c.execute("select Value from Pairs where Code=? and Number=?", ("abc", 1)).fetchone()[0])';
        exec(escapeshellarg($python) . ' -I -c ' . escapeshellarg($script) . ' ' . escapeshellarg($file) . ' 2>&1', $output, $status);
        self::assertSame(0, $status, implode("\n", $output));
        self::assertSame(['ok', '(2000, -1999000)', 'first'], $output);
    }

    public function testDates(): void
    {
        $database = new \PhpOffice\PhpDatabase\Database();
        $table = $database->createTable('Dates');
        $table->createColumn('D', \PhpOffice\PhpDatabase\ColumnType::DATE);
        $table->createColumn('T', \PhpOffice\PhpDatabase\ColumnType::TIME);
        $table->createColumn('DT', \PhpOffice\PhpDatabase\ColumnType::DATETIME);
        $table->addRow(['1899-12-29', '13:14:15', '1850-06-07 08:09:10']);
        foreach (self::formats() as [$format, $extension]) {
            $file = $this->getTemporaryFile($extension);
            IOFactory::createWriter($database, $format)->save($file);
            $row = IOFactory::createReader($format)->load($file)->getTable('Dates')?->getRow(0) ?? [];
            self::assertInstanceOf(DateTimeImmutable::class, $row['D']);
            self::assertSame('1899-12-29', $row['D']->format('Y-m-d'), $format);
            self::assertSame('13:14:15', $row['T']?->format('H:i:s'), $format);
            self::assertSame('1850-06-07 08:09:10', $row['DT']?->format('Y-m-d H:i:s'), $format);
        }
    }
}
