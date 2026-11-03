<?php

/**
 * Create a database and save it as SQLite, ODB (LibreOffice Base) and ACCDB (Access).
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpDatabase\ColumnType;
use PhpOffice\PhpDatabase\Database;
use PhpOffice\PhpDatabase\IOFactory;

$database = new Database();
$database->getDocumentProperties()
    ->setTitle('Library')
    ->setCreator('PhpOffice\PhpDatabase');

$authors = $database->createTable('Authors');
$authors->createColumn('ID', ColumnType::INTEGER)->setPrimaryKey()->setAutoIncrement();
$authors->createColumn('Name', ColumnType::TEXT, 100)->setNullable(false);
$authors->createColumn('Born', ColumnType::DATE);
$authors->addRows([
    ['Name' => 'Victor Hugo', 'Born' => '1802-02-26'],
    ['Name' => 'George Sand', 'Born' => '1804-07-01'],
    ['Name' => 'Émile Zola', 'Born' => '1840-04-02'],
]);

$books = $database->createTable('Books');
$books->createColumn('ID', ColumnType::INTEGER)->setPrimaryKey()->setAutoIncrement();
$books->createColumn('AuthorID', ColumnType::INTEGER);
$books->createColumn('Title', ColumnType::TEXT, 200)->setNullable(false);
$books->createColumn('Price', ColumnType::DECIMAL)->setPrecision(8, 2);
$books->createColumn('Available', ColumnType::BOOLEAN)->setDefaultValue(true);
$books->createColumn('Summary', ColumnType::LONGTEXT);
$books->addRows([
    ['AuthorID' => 1, 'Title' => 'Les Misérables', 'Price' => '12.90', 'Summary' => str_repeat('Jean Valjean… ', 200)],
    ['AuthorID' => 1, 'Title' => 'Notre-Dame de Paris', 'Price' => 9.5],
    ['AuthorID' => 2, 'Title' => 'La Mare au diable', 'Price' => 5, 'Available' => false],
    ['AuthorID' => 3, 'Title' => 'Germinal', 'Price' => '8.20'],
]);

foreach (['sqlite', 'odb', 'accdb', 'mdb'] as $extension) {
    $filename = __DIR__ . '/results/Sample_01_Create.' . $extension;
    IOFactory::save($database, $filename); // the writer is chosen from the extension
    echo sprintf('%s: %d bytes', basename($filename), filesize($filename)), PHP_EOL;
}
