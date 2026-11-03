<?php

/**
 * Display the structure and the first rows of a database: php Sample_03_Read.php file.sqlite.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpDatabase\IOFactory;

$input = $argv[1] ?? __DIR__ . '/results/Sample_02_Convert.odb';
if (!is_file($input)) {
    echo 'Run Sample_02_Convert.php first or give an input file', PHP_EOL;
    exit(1);
}

$database = IOFactory::load($input);
echo $database->getDocumentProperties()->getTitle(), PHP_EOL;
foreach ($database->getTables() as $table) {
    echo PHP_EOL, sprintf('== %s (%d rows)', $table->getName(), $table->getRowCount()), PHP_EOL;
    foreach ($table->getColumns() as $column) {
        echo sprintf(
            '   %-12s %-9s%s%s%s',
            $column->getName(),
            $column->getType(),
            null !== $column->getLength() ? '(' . $column->getLength() . ')' : '',
            $column->isPrimaryKey() ? ' PRIMARY KEY' : '',
            $column->isAutoIncrement() ? ' AUTO' : ''
        ), PHP_EOL;
    }
    foreach (array_slice($table->getRows(), 0, 5) as $row) {
        $values = array_map(static function ($value): string {
            if ($value instanceof DateTimeInterface) {
                return $value->format('Y-m-d H:i:s');
            }
            if (is_bool($value)) {
                return $value ? 'true' : 'false';
            }
            if (null === $value) {
                return 'NULL';
            }
            $value = (string) $value;

            return mb_strlen($value) > 30 ? mb_substr($value, 0, 27) . '...' : $value;
        }, $row);
        echo '   | ', implode(' | ', $values), PHP_EOL;
    }
}
