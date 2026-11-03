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
use PhpOffice\PhpDatabase\ColumnType;
use PhpOffice\PhpDatabase\Database;
use PhpOffice\PhpDatabase\Exception\WriterException;
use PhpOffice\PhpDatabase\ForeignKey;
use PhpOffice\PhpDatabase\IOFactory;
use PhpOffice\PhpDatabase\Tests\AbstractTestCase;
use PhpOffice\PhpDatabase\Writer\Accdb;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Indexes, foreign keys and properties of the columns.
 */
class RelationsTest extends AbstractTestCase
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

    private function createDatabase(int $rowCount = 500): Database
    {
        $database = new Database();
        $authors = $database->createTable('Authors');
        $authors->createColumn('ID', ColumnType::INTEGER)->setPrimaryKey()->setAutoIncrement();
        $authors->createColumn('Name', ColumnType::TEXT, 50)->setNullable(false);
        $books = $database->createTable('Books');
        $books->createColumn('Code', ColumnType::TEXT, 10)->setPrimaryKey();
        $books->createColumn('AuthorID', ColumnType::INTEGER);
        $books->createColumn('Title', ColumnType::TEXT, 100);
        $books->createIndex('BooksTitle', ['Title']);
        $books->createIndex('BooksAuthorTitle', ['AuthorID', 'Title'], true);
        $books->createForeignKey(['AuthorID'], 'Authors', ['ID'], 'BooksAuthor')->setOnDelete(ForeignKey::CASCADE);
        for ($i = 0; $i < $rowCount; ++$i) {
            $authors->addRow([null, 'Author ' . $i]);
            $books->addRow(['C' . $i, $i % 50 + 1, 'Titre é ' . str_repeat('x', $i % 20) . $i]);
        }

        return $database;
    }

    #[DataProvider('formats')]
    public function testIndexesAndForeignKeys(string $format, string $extension): void
    {
        $file = $this->getTemporaryFile($extension);
        IOFactory::createWriter($this->createDatabase(), $format)->save($file);
        $loaded = IOFactory::createReader($format)->load($file);

        $books = $loaded->getTable('Books');
        self::assertNotNull($books);
        self::assertSame(500, $books->getRowCount());
        self::assertSame(['Title'], $books->getIndex('BooksTitle')?->getColumns());
        self::assertFalse($books->getIndex('BooksTitle')->isUnique());
        self::assertSame(['AuthorID', 'Title'], $books->getIndex('BooksAuthorTitle')?->getColumns());
        self::assertTrue($books->getIndex('BooksAuthorTitle')->isUnique());
        self::assertCount(1, $books->getForeignKeys());
        $foreignKey = $books->getForeignKeys()[0];
        self::assertSame('BooksAuthor', $foreignKey->getName());
        self::assertSame(['AuthorID'], $foreignKey->getColumns());
        self::assertSame('Authors', $foreignKey->getReferencedTable());
        self::assertSame(['ID'], $foreignKey->getReferencedColumns());
        self::assertSame(ForeignKey::CASCADE, $foreignKey->getOnDelete());
        self::assertSame(ForeignKey::NO_ACTION, $foreignKey->getOnUpdate());
    }

    #[DataProvider('formats')]
    public function testDuplicateInUniqueIndex(string $format, string $extension): void
    {
        $database = $this->createDatabase(10);
        $database->getTable('Books')?->addRow(['X', 1, 'Titre é 0']);
        $this->expectException(WriterException::class);
        IOFactory::createWriter($database, $format)->save($this->getTemporaryFile($extension));
    }

    public function testAccessProperties(): void
    {
        $database = $this->createDatabase(3);
        $database->getDocumentProperties()->setTitle('Library')->setCreator('Me')->setDescription('Books and authors');
        $authors = $database->getTable('Authors');
        self::assertNotNull($authors);
        $authors->setDescription('The writers');
        $authors->getColumn('Name')?->setDescription('Full name');
        $authors->createColumn('Country', ColumnType::TEXT, 30)->setDefaultValue('Fr "FR"');
        $authors->createColumn('Books', ColumnType::SMALLINT)->setDefaultValue(0);
        $authors->createColumn('Alive', ColumnType::BOOLEAN)->setDefaultValue(true);
        foreach ([Accdb::FORMAT_MDB_2000, Accdb::FORMAT_ACCDB_2007, Accdb::FORMAT_ACCDB_2010, Accdb::FORMAT_ACCDB_2016] as $format) {
            $file = $this->getTemporaryFile('accdb');
            (new Accdb($database))->setFormat($format)->save($file);
            $loaded = IOFactory::load($file);
            self::assertSame('Library', $loaded->getDocumentProperties()->getTitle());
            self::assertSame('Me', $loaded->getDocumentProperties()->getCreator());
            self::assertSame('Books and authors', $loaded->getDocumentProperties()->getDescription());
            $table = $loaded->getTable('Authors');
            self::assertNotNull($table);
            self::assertSame('The writers', $table->getDescription());
            self::assertSame('Full name', $table->getColumn('Name')?->getDescription());
            self::assertFalse($table->getColumn('Name')->isNullable());
            self::assertTrue($table->getColumn('Country')?->isNullable());
            self::assertSame('Fr "FR"', $table->getColumn('Country')->getDefaultValue());
            self::assertSame(0, $table->getColumn('Books')?->getDefaultValue());
            self::assertTrue($table->getColumn('Alive')?->getDefaultValue());
        }
    }

    public function testAccessRelationshipWithoutUniqueKey(): void
    {
        $database = new Database();
        $a = $database->createTable('A');
        $a->createColumn('X', ColumnType::INTEGER);
        $b = $database->createTable('B');
        $b->createColumn('Y', ColumnType::INTEGER);
        $b->createForeignKey(['Y'], 'A', ['X'], 'NotEnforced');
        $a->addRow([1])->addRow([1]);
        $b->addRow([1]);
        $file = $this->getTemporaryFile('accdb');
        IOFactory::save($database, $file);
        $loaded = IOFactory::load($file);
        self::assertSame('NotEnforced', $loaded->getTable('B')?->getForeignKeys()[0]->getName());
        self::assertSame(2, $loaded->getTable('A')?->getRowCount());
    }

    public function testDefaultExpression(): void
    {
        self::assertSame('Yes', Accdb::defaultExpression(true));
        self::assertSame('12.50', Accdb::defaultExpression('12.50'));
        self::assertSame('"a ""b"""', Accdb::defaultExpression('a "b"'));
        self::assertSame('#2024-01-02#', Accdb::defaultExpression(new DateTimeImmutable('2024-01-02')));
        self::assertSame('a "b"', \PhpOffice\PhpDatabase\Reader\Accdb::parseDefaultExpression('"a ""b"""'));
        self::assertSame(-3, \PhpOffice\PhpDatabase\Reader\Accdb::parseDefaultExpression('-3'));
        self::assertFalse(\PhpOffice\PhpDatabase\Reader\Accdb::parseDefaultExpression('No'));
        self::assertNull(\PhpOffice\PhpDatabase\Reader\Accdb::parseDefaultExpression('=Date()'));
    }
}
