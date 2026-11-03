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
use PhpOffice\PhpDatabase\ColumnType;
use PhpOffice\PhpDatabase\Database;
use PHPUnit\Framework\TestCase;

abstract class AbstractTestCase extends TestCase
{
    /**
     * @var array<int, string>
     */
    private $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->temporaryFiles = [];
    }

    protected function getTemporaryFile(string $extension): string
    {
        $file = tempnam(sys_get_temp_dir(), 'PhpDatabase');
        unlink($file);
        $file .= '.' . $extension;
        $this->temporaryFiles[] = $file;

        return $file;
    }

    protected function getResourceFile(string $name): string
    {
        return dirname(__DIR__, 2) . '/resources/files/' . $name;
    }

    protected function findExecutable(string $name): ?string
    {
        $path = trim((string) shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null'));

        return '' === $path ? null : $path;
    }

    /**
     * A database using all the column types.
     */
    protected function createSampleDatabase(int $rowCount = 20): Database
    {
        $database = new Database();
        $database->getDocumentProperties()->setTitle('Sample')->setCreated(new DateTimeImmutable('2024-01-02 03:04:05'));
        $table = $database->createTable('Clients é');
        $table->createColumn('ID', ColumnType::INTEGER)->setPrimaryKey()->setAutoIncrement();
        $table->createColumn('Name', ColumnType::TEXT, 100)->setNullable(false);
        $table->createColumn('Price', ColumnType::DECIMAL)->setPrecision(10, 2);
        $table->createColumn('Active', ColumnType::BOOLEAN);
        $table->createColumn('Born', ColumnType::DATE);
        $table->createColumn('Updated', ColumnType::DATETIME);
        $table->createColumn('Notes', ColumnType::LONGTEXT);
        $table->createColumn('Photo', ColumnType::BLOB);
        $table->createColumn('Score', ColumnType::DOUBLE);
        $table->createColumn('Small', ColumnType::SMALLINT);
        for ($i = 0; $i < $rowCount; ++$i) {
            $table->addRow([
                'Name' => (0 === $i % 3 ? 'Clïent € ' : "O'Brien ") . $i,
                'Price' => -$i - 0.25,
                'Active' => 0 === $i % 2,
                'Born' => (new DateTimeImmutable('2001-02-03'))->modify('+' . $i . ' days'),
                'Updated' => new DateTimeImmutable('2020-12-31 23:59:58'),
                'Notes' => 0 === $i ? null : (1 === $i ? str_repeat('Long note 😀 ', 2000) : 'Note ' . $i),
                'Photo' => 0 === $i % 5 ? str_repeat("\x00\x01\x02\xff", 1000 + $i) : null,
                'Score' => $i * 1.5,
                'Small' => -$i,
            ]);
        }
        $pairs = $database->createTable('Pairs');
        $pairs->createColumn('Code', ColumnType::TEXT, 10)->setPrimaryKey();
        $pairs->createColumn('Number', ColumnType::INTEGER)->setPrimaryKey();
        $pairs->createColumn('Value', ColumnType::TEXT);
        $pairs->addRow(['abc', 1, 'first'])->addRow(['ABD', 2, null]);

        return $database;
    }
}
