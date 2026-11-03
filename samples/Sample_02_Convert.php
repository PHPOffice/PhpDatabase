<?php

/**
 * Convert a file to another format: php Sample_02_Convert.php input.accdb output.odb.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpDatabase\IOFactory;

$input = $argv[1] ?? __DIR__ . '/results/Sample_01_Create.accdb';
$output = $argv[2] ?? __DIR__ . '/results/Sample_02_Convert.odb';
if (!is_file($input)) {
    echo 'Run Sample_01_Create.php first or give an input file', PHP_EOL;
    exit(1);
}

$database = IOFactory::load($input);
IOFactory::save($database, $output);
echo sprintf('%s (%d table(s)) converted to %s', basename($input), $database->getTableCount(), basename($output)), PHP_EOL;
