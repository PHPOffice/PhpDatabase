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

namespace PhpOffice\PhpDatabase\Tests\Writer;

use DateTimeImmutable;
use PhpOffice\PhpDatabase\Attachment;
use PhpOffice\PhpDatabase\ColumnType;
use PhpOffice\PhpDatabase\Database;
use PhpOffice\PhpDatabase\Expression;
use PhpOffice\PhpDatabase\Index;
use PhpOffice\PhpDatabase\IOFactory;
use PhpOffice\PhpDatabase\Reader\Odb;
use PhpOffice\PhpDatabase\Tests\AbstractTestCase;
use PhpOffice\PhpDatabase\Writer\Accdb;

/**
 * Views, check constraints, default expressions, index options, triggers and the types of Access.
 */
class FeaturesTest extends AbstractTestCase
{
    private function createDatabase(): Database
    {
        $database = new Database();
        $items = $database->createTable('Items');
        $items->createColumn('ID', ColumnType::INTEGER)->setPrimaryKey()->setAutoIncrement();
        $items->createColumn('Name', ColumnType::TEXT, 50);
        $items->createColumn('Price', ColumnType::DOUBLE)->setDefaultValue(0);
        $items->createColumn('Created', ColumnType::DATETIME)->setDefaultValue(Expression::currentTimestamp());
        $items->createCheck('"Price" >= 0');
        $items->addIndex(new Index('ByPrice', ['Price' => 'DESC', 'Name' => 'ASC']));
        $items->addIndex((new Index('Named', ['Name'], true))->setWhere('"Price" > 0'));
        $items->addIndex(new Index('LowerName', [new Expression('lower("Name")')]));
        for ($i = 1; $i <= 30; ++$i) {
            $items->addRow([null, 'Item ' . $i, $i * 1.5, new DateTimeImmutable('2024-01-02 03:04:05')]);
        }
        $pairs = $database->createTable('Pairs');
        $pairs->createColumn('K', ColumnType::TEXT, 10)->setPrimaryKey();
        $pairs->createColumn('V', ColumnType::INTEGER);
        $pairs->setWithoutRowid();
        $pairs->addRow(['b', 2])->addRow(['a', 1]);
        $database->createView('Cheap', 'SELECT "Name", "Price" FROM "Items" WHERE "Price" < 10 ORDER BY "Price" DESC');
        $database->createView('Subquery', 'SELECT * FROM (SELECT 1 AS x) s');
        $database->createTrigger('ItemsAudit', 'Items', 'CREATE TRIGGER "ItemsAudit" AFTER DELETE ON "Items" BEGIN DELETE FROM "Pairs" WHERE "V" = OLD."ID"; END');

        return $database;
    }

    public function testSqlite(): void
    {
        $file = $this->getTemporaryFile('sqlite');
        $writer = IOFactory::createWriter($this->createDatabase(), 'Sqlite');
        $writer->save($file);
        self::assertSame([], $writer->getWarnings());
        $database = IOFactory::load($file);
        $items = $database->getTable('Items');
        self::assertNotNull($items);
        self::assertSame(['"Price" >= 0'], array_map(static fn ($check): string => $check->getExpression(), $items->getChecks()));
        self::assertEquals(Expression::currentTimestamp(), $items->getColumn('Created')?->getDefaultValue());
        self::assertTrue($items->getIndex('ByPrice')?->isDescending(0));
        self::assertFalse($items->getIndex('ByPrice')->isDescending(1));
        self::assertSame('"Price" > 0', $items->getIndex('Named')?->getWhere());
        self::assertSame('lower("Name")', $items->getIndex('LowerName')?->getExpression(0)?->getSql());
        self::assertTrue($database->getTable('Pairs')?->isWithoutRowid());
        self::assertSame(['a', 'b'], array_column($database->getTable('Pairs')->getRows(), 'K'));
        self::assertSame(['Cheap', 'Subquery'], array_map(static fn ($view): string => $view->getName(), $database->getViews()));
        self::assertSame('ItemsAudit', $database->getTriggers()[0]->getName());

        if (null === ($python = $this->findExecutable('python3'))) {
            return;
        }
        $script = 'import sqlite3,sys;c=sqlite3.connect(sys.argv[1]);'
            . 'print(c.execute("pragma integrity_check").fetchone()[0]);'
            . 'print(c.execute("select count(*) from Cheap").fetchone()[0]);'
            . 'print(c.execute("select ID from Items indexed by LowerName where lower(Name) = \'item 7\'").fetchone()[0]);'
            . 'c.execute("delete from Items where ID = 2");print(c.execute("select count(*) from Pairs").fetchone()[0])';
        exec(escapeshellarg($python) . ' -I -c ' . escapeshellarg($script) . ' ' . escapeshellarg($file) . ' 2>&1', $output, $status);
        self::assertSame(0, $status, implode("\n", $output));
        self::assertSame(['ok', '6', '7', '1'], $output);
    }

    public function testOdb(): void
    {
        $file = $this->getTemporaryFile('odb');
        $writer = IOFactory::createWriter($this->createDatabase(), 'Odb');
        $writer->save($file);
        self::assertCount(4, $writer->getWarnings(), implode("\n", $writer->getWarnings()));
        // ODF: "mimetype" is the first entry of the package, stored (compression method 0)
        $header = (string) file_get_contents($file, false, null, 0, 30 + 8 + strlen(Odb::MIMETYPE));
        self::assertSame("PK\x03\x04", substr($header, 0, 4));
        self::assertSame("\x00\x00", substr($header, 8, 2));
        self::assertSame('mimetype' . Odb::MIMETYPE, substr($header, 30));
        $database = IOFactory::load($file);
        $items = $database->getTable('Items');
        self::assertNotNull($items);
        self::assertCount(1, $items->getChecks());
        self::assertEquals(Expression::currentTimestamp(), $items->getColumn('Created')?->getDefaultValue());
        self::assertNull($items->getIndex('Named'));
        self::assertSame('SELECT "Name", "Price" FROM "Items" WHERE "Price" < 10 ORDER BY "Price" DESC', $database->getView('Cheap')?->getSql());
        // HSQLDB 1.8 requires FROM (an invalid view would prevent the database from opening)
        self::assertNull($database->getView('Subquery'));
    }

    public function testAccess(): void
    {
        $database = $this->createDatabase();
        $items = $database->getTable('Items');
        self::assertNotNull($items);
        $items->createColumn('Precise', ColumnType::DATETIME)->setFractionalSeconds(7);
        $items->createColumn('Uid', ColumnType::GUID);
        $items->createColumn('Link', ColumnType::HYPERLINK);
        $items->createColumn('Tags', ColumnType::TEXT)->setMultiValued();
        $items->createColumn('Files', ColumnType::ATTACHMENT);
        $items->createCheck('"Name" <> \'\'');
        $items->addIndex(new Index('ByPrecise', ['Precise' => 'DESC']));
        foreach ($items->getRows() as $index => $row) {
            $items->removeRow(0);
            $items->addRow(array_merge($row, [
                'Precise' => new DateTimeImmutable(sprintf('2024-02-29 13:45:07.12345%d', $index % 10)),
                'Uid' => sprintf('%08x-1234-5678-9abc-def012345678', $index),
                'Link' => '#http://example.com/' . $index . '#',
                'Tags' => ['a', 'b'],
                'Files' => [new Attachment('f.txt', 'hello')],
            ]));
        }
        $file = $this->getTemporaryFile('accdb');
        $writer = (new Accdb($database))->setFormat(Accdb::FORMAT_ACCDB_2019);
        $writer->save($file);
        self::assertCount(6, $writer->getWarnings(), implode("\n", $writer->getWarnings()));
        $loaded = IOFactory::load($file);
        $table = $loaded->getTable('Items');
        self::assertNotNull($table);
        self::assertSame(['("Price" >= 0) And ("Name" <> \'\')'], array_map(static fn ($check): string => $check->getExpression(), $table->getChecks()));
        self::assertEquals(Expression::currentTimestamp(), $table->getColumn('Created')?->getDefaultValue());
        self::assertTrue($table->getIndex('ByPrice')?->isDescending(0));
        self::assertTrue($table->getIndex('ByPrecise')?->isDescending(0));
        self::assertSame(7, $table->getColumn('Precise')?->getFractionalSeconds());
        self::assertSame(ColumnType::GUID, $table->getColumn('Uid')?->getType());
        self::assertSame(ColumnType::HYPERLINK, $table->getColumn('Link')?->getType());
        $row = $table->getRow(3) ?? [];
        self::assertSame('2024-02-29 13:45:07.123453', $row['Precise']?->format('Y-m-d H:i:s.u'));
        self::assertSame('{00000003-1234-5678-9ABC-DEF012345678}', $row['Uid']);
        self::assertSame('a; b', $row['Tags']);
        self::assertSame('SELECT "Name", "Price" FROM Items WHERE "Price" < 10 ORDER BY "Price" DESC', str_replace("\n", ' ', (string) $loaded->getView('Cheap')?->getSql()));

        // Without the 2019 format: standard date/time
        $writer->setFormat(Accdb::FORMAT_ACCDB_2010)->save($file);
        self::assertCount(7, $writer->getWarnings());
        self::assertNull(IOFactory::load($file)->getTable('Items')?->getColumn('Precise')?->getFractionalSeconds());
    }
}
