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

namespace PhpOffice\PhpDatabase\Tests\Reader;

use DateTimeZone;
use PhpOffice\PhpDatabase\Attachment;
use PhpOffice\PhpDatabase\ColumnType;
use PhpOffice\PhpDatabase\Exception\FileNotFoundException;
use PhpOffice\PhpDatabase\Exception\InvalidFileFormatException;
use PhpOffice\PhpDatabase\Expression;
use PhpOffice\PhpDatabase\IOFactory;
use PhpOffice\PhpDatabase\Reader;
use PhpOffice\PhpDatabase\Tests\AbstractTestCase;

/**
 * Files created by the reference engines (see tests/resources/files/README.md).
 */
class FixturesTest extends AbstractTestCase
{
    public function testJackcessAccdb(): void
    {
        foreach (['jackcess-2007.accdb', 'jackcess-2000.mdb'] as $name) {
            $database = (new Reader\Accdb())->load($this->getResourceFile($name));
            $table = $database->getTable('Clients é');
            self::assertNotNull($table, $name);
            self::assertSame(12, $table->getRowCount());
            self::assertSame(ColumnType::DECIMAL, $table->getColumn('Money')?->getType());
            self::assertSame(['ID'], array_map(static fn ($column) => $column->getName(), $table->getPrimaryKeyColumns()));
            $row = $table->getRow(3) ?? [];
            self::assertSame(4, $row['ID']);
            self::assertSame('Client 3', $row['Nom']);
            self::assertSame('-3.25', $row['Prix']);
            self::assertSame('12.3456', $row['Money']);
            self::assertFalse($row['Actif']);
            self::assertSame(str_repeat('y€', 9000), $row['Notes']);
            self::assertSame(str_repeat('x', 3000), $table->getRow(2)['Notes'] ?? null);
            self::assertSame('short', $table->getRow(1)['Notes'] ?? null);
            self::assertSame("\x01\x02\x0a", $table->getRow(10)['Bin'] ?? null);
            self::assertSame('{4DBF0C8A-1D4E-4B39-9D5B-0123456789AB}', $row['G']);
            self::assertSame('2020-12-31 23:59:58', $row['Quand']?->format('Y-m-d H:i:s'));
            self::assertSame(-3, $row['Small']);
            self::assertSame(0.5, $row['F']);
            self::assertSame('Clïent €0', $table->getRow(0)['Nom'] ?? null);
            self::assertCount(2, $database->getTable('Pairs')?->getPrimaryKeyColumns() ?? []);
        }
    }

    public function testHsqldbOdb(): void
    {
        $timezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Paris');

        try {
            $database = (new Reader\Odb())->load($this->getResourceFile('hsqldb-cached.odb'));
        } finally {
            date_default_timezone_set($timezone);
        }
        $table = $database->getTable('Clients');
        self::assertNotNull($table);
        self::assertSame(200, $table->getRowCount());
        $row = $table->getRow(199) ?? [];
        self::assertSame(199, $row['ID']);
        self::assertSame("Clïent \\ ' €199", $row['Nom']);
        self::assertSame('-199.25', $row['Prix']);
        self::assertSame('2001-02-03', $row['Né']?->format('Y-m-d'));
        self::assertSame('04:05:06', $row['Heure']?->format('H:i:s'));
        self::assertSame('2020-12-31 23:59:58.500000', $row['Quand']?->setTimezone(new DateTimeZone('Europe/Paris'))->format('Y-m-d H:i:s.u'));
        self::assertSame("\x01\x02\xc7", $row['Bin']);
        self::assertSame(10000000199, $row['Big']);
        self::assertSame(-57, $row['Tiny']);
        self::assertNull(($table->getRow(0) ?? [])['Notes']);
        $memory = $database->getTable('Mem');
        self::assertSame('xé\\', $memory?->getRow(0)['A']);
        self::assertSame(5, $memory->getColumn('B')?->getDefaultValue());
        self::assertCount(2, $memory->getPrimaryKeyColumns());

        $noPk = (new Reader\Odb())->load($this->getResourceFile('hsqldb-nopk.odb'))->getTable('NoPk');
        self::assertSame(['y9', 'y8', 'y7', 'y6', 'y5'], array_column($noPk?->getRows() ?? [], 'Y'));
    }

    public function testSqlite3File(): void
    {
        $database = IOFactory::load($this->getResourceFile('sqlite3-features.sqlite'));
        self::assertSame(['contacts', 'pairs'], array_map(static fn ($table) => $table->getName(), $database->getTables()));
        $contacts = $database->getTable('contacts');
        $row = $contacts?->getRow(11) ?? [];
        self::assertSame(12, $row['id']);
        self::assertSame('Contact é 11', $row['name']);
        self::assertSame('2.75', $row['price']);
        self::assertSame(5500, strlen((string) $row['photo']));
        self::assertTrue($row['active']);
        self::assertSame('none', $contacts?->getColumn('name')?->getDefaultValue());
        $pairs = $database->getTable('pairs');
        self::assertSame(10, $pairs?->getRowCount());
        self::assertSame(['k' => 'k09', 'j' => 0, 'v' => 'vvvvvvvvv'], $pairs->getRow(9));
    }

    /**
     * Indexes, relationship and properties written by Jackcess.
     */
    public function testJackcessRelations(): void
    {
        $database = IOFactory::load($this->getResourceFile('jackcess-relations.accdb'));
        self::assertSame('Library', $database->getDocumentProperties()->getTitle());
        self::assertSame('Jackcess', $database->getDocumentProperties()->getCreator());
        $authors = $database->getTable('Authors');
        self::assertNotNull($authors);
        self::assertSame('Writers', $authors->getDescription());
        self::assertSame('Full name', $authors->getColumn('Name')?->getDescription());
        self::assertFalse($authors->getColumn('Name')->isNullable());
        self::assertSame('France', $authors->getColumn('Country')?->getDefaultValue());
        self::assertTrue($authors->getIndex('NameIdx')?->isUnique());
        $books = $database->getTable('Books');
        self::assertNotNull($books);
        self::assertSame(3, $books->getColumn('Stock')?->getDefaultValue());
        $foreignKey = $books->getForeignKeys()[0] ?? null;
        self::assertSame('AuthorsBooks', $foreignKey?->getName());
        self::assertSame(['AuthorID'], $foreignKey->getColumns());
        self::assertSame('Authors', $foreignKey->getReferencedTable());
        self::assertSame(['ID'], $foreignKey->getReferencedColumns());
        self::assertSame('CASCADE', $foreignKey->getOnDelete());
    }

    /**
     * Indexes and foreign key written by HSQLDB 1.8 (CACHED tables).
     */
    public function testHsqldbRelations(): void
    {
        $database = IOFactory::load($this->getResourceFile('hsqldb-relations.odb'));
        self::assertSame(2999, $database->getTable('Authors')?->getRowCount());
        $books = $database->getTable('Books');
        self::assertSame(2970, $books?->getRowCount());
        self::assertSame(['Title'], $books->getIndex('idx_books_title')?->getColumns());
        self::assertFalse($books->getIndex('idx_books_title')->isUnique());
        self::assertSame(['AuthorID', 'Title'], $books->getIndex('uq_books_author_title')?->getColumns());
        self::assertTrue($books->getIndex('uq_books_author_title')->isUnique());
        $foreignKey = $books->getForeignKeys()[0] ?? null;
        self::assertSame('fk_books_author', $foreignKey?->getName());
        self::assertSame('Authors', $foreignKey->getReferencedTable());
        self::assertSame('CASCADE', $foreignKey->getOnDelete());
    }

    /**
     * Pages modified in the write-ahead log (not checkpointed) are read.
     */
    public function testSqliteWal(): void
    {
        $database = IOFactory::load($this->getResourceFile('sqlite3-wal.sqlite'));
        $checkpointed = $database->getTable('Checkpointed');
        self::assertSame(40, $checkpointed?->getRowCount());
        self::assertSame(['id' => 1, 'v' => 'changed'], $checkpointed->getRow(0));
        $table = $database->getTable('InWal');
        self::assertSame(100, $table?->getRowCount());
        self::assertSame(['label'], $table->getIndex('InWal_label')?->getColumns());
        self::assertSame(['code'], $table->getForeignKeys()[0]->getReferencedColumns());
        self::assertSame('SET NULL', $table->getForeignKeys()[0]->getOnDelete());

        // Without the WAL file, only the checkpointed data is visible
        $withoutWal = (new Reader\Sqlite())->loadFromString((string) file_get_contents($this->getResourceFile('sqlite3-wal.sqlite')));
        self::assertNull($withoutWal->getTable('InWal'));
    }

    /**
     * Database closed without a checkpoint: changes in database/log, the last ones not committed.
     */
    public function testHsqldbLog(): void
    {
        $database = (new Reader\Odb())->load($this->getResourceFile('hsqldb-log.odb'));
        $items = $database->getTable('Items');
        self::assertNotNull($items);
        self::assertSame(['Pen é', 'Book'], array_column($items->getRows(), 'Name'));
        self::assertSame('2.00', $items->getRow(1)['Price'] ?? null);
        self::assertTrue($items->getColumn('Created')?->getDefaultValue() instanceof Expression);
        self::assertCount(2, $items->getChecks());
        self::assertSame([[1, 'one'], [4, 'committed']], array_map(static fn (array $row): array => array_values($row), $database->getTable('NoPk')?->getRows() ?? []));
        // Views of HSQLDB and query of LibreOffice Base
        self::assertSame('SELECT "Name" FROM "Items" ORDER BY "Price" DESC LIMIT 2', $database->getView('Top2')?->getSql());
        self::assertSame('SELECT "Name" FROM "Items" WHERE "Price" > 1', $database->getView('BaseQuery')?->getSql());
    }

    public function testJackcessQueries(): void
    {
        $database = (new Reader\Accdb())->load($this->getResourceFile('jackcess-queries.accdb'));
        // Select and union queries only
        self::assertSame(['SelectQuery', 'UnionQuery'], array_map(static fn ($view): string => $view->getName(), $database->getViews()));
        self::assertSame(
            "SELECT DISTINCT Table1.*, Table2.col1, Table2.col2, Table3.col3\nFROM (Table1 LEFT JOIN Table3 ON Table1.col1 = Table3.col1) INNER JOIN Table2 ON ((Table3.col1 = Table2.col2) AND (Table3.col1 = Table2.col1))\nWHERE (((Table2.col2)='foo' Or (Table2.col2) In ('buzz','bazz')))\nORDER BY Table2.col1",
            $database->getView('SelectQuery')?->getSql()
        );
        self::assertStringContainsString("UNION ALL Select Table3.col1, Table3.col2\nwhere Table3.col3 > 'blah'", (string) $database->getView('UnionQuery')?->getSql());
    }

    public function testJackcessComplexColumns(): void
    {
        $table = (new Reader\Accdb())->load($this->getResourceFile('jackcess-complex.accdb'))->getTable('Table1');
        self::assertNotNull($table);
        // The version history of the "append only" memo is skipped
        self::assertSame(['id', 'memo-data', 'append-memo-data', 'multi-value-data', 'attach-data'], $table->getColumnNames());
        self::assertTrue($table->getColumn('multi-value-data')?->isMultiValued());
        self::assertSame(ColumnType::ATTACHMENT, $table->getColumn('attach-data')?->getType());
        $row = $table->getRow(1) ?? [];
        self::assertSame(['value1', 'value4'], $row['multi-value-data']);
        self::assertCount(2, $row['attach-data']);
        $attachment = $row['attach-data'][0];
        self::assertInstanceOf(Attachment::class, $attachment);
        self::assertSame('test_data.txt', $attachment->getName());
        self::assertSame('txt', $attachment->getType());
        self::assertStringStartsWith('this is some test data', $attachment->getData());
        self::assertSame([], $table->getRow(0)['attach-data'] ?? null);
    }

    public function testJackcessExtendedDate(): void
    {
        $table = (new Reader\Accdb())->load($this->getResourceFile('jackcess-extdate.accdb'))->getTable('Table1');
        self::assertNotNull($table);
        self::assertSame(7, $table->getColumn('DateExt')?->getFractionalSeconds());
        self::assertSame('2021-06-14 12:45:00', $table->getRow(2)['DateExt']?->format('Y-m-d H:i:s'));
        self::assertTrue($table->getIndex('DateExtDesc')?->isDescending(0));
        self::assertFalse($table->getIndex('DateExtAsc')?->isDescending(0));
    }

    public function testJackcessCalculatedColumns(): void
    {
        $table = (new Reader\Accdb())->load($this->getResourceFile('jackcess-calculated.accdb'))->getTable('Table1');
        self::assertNotNull($table);
        $row = $table->getRow(0) ?? [];
        self::assertSame('Wayne, Bruce', $row['LastFirst']);
        self::assertSame(12, $row['LastFirstLen']);
        self::assertSame('83333.3333', $row['MonthlySalary']);
        self::assertTrue($row['IsRich']);
        self::assertSame('Wayne, Bruce=Wayne, Bruce', $row['AllNames']);
        self::assertSame('19230.769230769200000000', $row['WeeklySalary']);
        self::assertSame('56505085819.424791296572280180', $row['BigNumTest']);
        self::assertSame('-36.222200000000000000', $table->getRow(1)['DecimalTest'] ?? null);
    }

    public function testCanRead(): void
    {
        self::assertTrue((new Reader\Accdb())->canRead($this->getResourceFile('jackcess-2000.mdb')));
        self::assertFalse((new Reader\Accdb())->canRead($this->getResourceFile('hsqldb-cached.odb')));
        self::assertTrue((new Reader\Odb())->canRead($this->getResourceFile('hsqldb-cached.odb')));
        self::assertFalse((new Reader\Sqlite())->canRead($this->getResourceFile('hsqldb-cached.odb')));
    }

    public function testFileNotFound(): void
    {
        $this->expectException(FileNotFoundException::class);
        (new Reader\Sqlite())->load('/not/found.sqlite');
    }

    public function testInvalidFile(): void
    {
        $this->expectException(InvalidFileFormatException::class);
        (new Reader\Sqlite())->load($this->getResourceFile('jackcess-2000.mdb'));
    }
}
