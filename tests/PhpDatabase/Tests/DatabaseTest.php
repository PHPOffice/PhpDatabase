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

namespace PhpOffice\PhpDatabase\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use PhpOffice\PhpDatabase\Column;
use PhpOffice\PhpDatabase\ColumnType;
use PhpOffice\PhpDatabase\Database;

class DatabaseTest extends AbstractTestCase
{
    public function testTables(): void
    {
        $database = new Database();
        $table = $database->createTable('Contacts');
        self::assertSame(1, $database->getTableCount());
        self::assertSame($table, $database->getTable('contacts'));
        self::assertTrue($database->hasTable('CONTACTS'));
        $database->removeTable('Contacts');
        self::assertSame(0, $database->getTableCount());
    }

    public function testDuplicateTable(): void
    {
        $database = new Database();
        $database->createTable('Contacts');
        $this->expectException(InvalidArgumentException::class);
        $database->createTable('contacts');
    }

    public function testRows(): void
    {
        $database = new Database();
        $table = $database->createTable('Contacts');
        $table->createColumn('id', ColumnType::INTEGER)->setPrimaryKey()->setAutoIncrement();
        $table->createColumn('name');
        $table->createColumn('active', ColumnType::BOOLEAN)->setDefaultValue(true);
        $table->createColumn('born', ColumnType::DATE);
        $table->addRow(['name' => 'Alice', 'born' => '2000-01-02']);
        $table->addRow([10, 'Bob', 'no', null]);
        $table->addRow(['name' => 'Carol']);

        self::assertSame(3, $table->getRowCount());
        self::assertSame(['id' => 1, 'name' => 'Alice', 'active' => true, 'born' => $table->getRow(0)['born'] ?? null], $table->getRow(0));
        self::assertInstanceOf(DateTimeImmutable::class, $table->getRow(0)['born']);
        self::assertFalse($table->getRow(1)['active']);
        self::assertSame(11, $table->getRow(2)['id']);
        self::assertSame(['id', 'name', 'active', 'born'], $table->getColumnNames());
        self::assertCount(1, $table->getPrimaryKeyColumns());
    }

    public function testUnknownColumn(): void
    {
        $table = (new Database())->createTable('T');
        $table->createColumn('a');
        $this->expectException(InvalidArgumentException::class);
        $table->addRow(['b' => 1]);
    }

    public function testNormalizeValue(): void
    {
        $decimal = (new Column('d', ColumnType::DECIMAL))->setPrecision(10, 2);
        self::assertSame('1.50', $decimal->normalizeValue(1.5));
        self::assertSame('-0.25', $decimal->normalizeValue('-.25'));
        self::assertSame('12345678901234567.10', $decimal->normalizeValue('12345678901234567.1'));
        self::assertSame(3, (new Column('i', ColumnType::INTEGER))->normalizeValue('3'));
        self::assertSame(2.5, (new Column('f', ColumnType::DOUBLE))->normalizeValue('2.5'));
        self::assertSame('1', (new Column('t'))->normalizeValue(true));
        $this->expectException(InvalidArgumentException::class);
        (new Column('i', ColumnType::INTEGER))->normalizeValue('abc');
    }

    public function testInvalidType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Column('x', 'varchar');
    }
}
