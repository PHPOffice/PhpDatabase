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

namespace PhpOffice\PhpDatabase\Reader;

use DateTimeImmutable;
use InvalidArgumentException;
use PhpOffice\PhpDatabase\Column;
use PhpOffice\PhpDatabase\ColumnType;
use PhpOffice\PhpDatabase\Database;
use PhpOffice\PhpDatabase\Exception\ReaderException;
use PhpOffice\PhpDatabase\Reader\Sqlite\Wal;
use PhpOffice\PhpDatabase\Shared\Sql\Parser;
use PhpOffice\PhpDatabase\Shared\Sqlite\Blob;
use PhpOffice\PhpDatabase\Shared\Sqlite\SqliteFile;
use PhpOffice\PhpDatabase\Table;
use PhpOffice\PhpDatabase\Trigger;
use PhpOffice\PhpDatabase\View;
use Throwable;

/**
 * Reader for SQLite 3 databases (.sqlite, .sqlite3, .db).
 */
class Sqlite extends AbstractReader
{
    public function canRead(string $filename): bool
    {
        return SqliteFile::SIGNATURE === $this->readSignature($filename, 16);
    }

    public function load(string $filename, int $flags = 0): Database
    {
        $this->flags = $flags;
        $this->assertReadable($filename);
        $data = file_get_contents($filename);
        if (false === $data) {
            throw new ReaderException(sprintf('Unable to read the file "%s"', $filename));
        }

        // Database in WAL mode: the transactions not yet checkpointed are in the "-wal" file
        $wal = is_file($filename . '-wal') ? file_get_contents($filename . '-wal') : false;

        return $this->loadFromString($data, pathinfo($filename, PATHINFO_FILENAME), false === $wal ? null : $wal);
    }

    /**
     * @param null|string $wal content of the write-ahead log of a database in WAL mode
     */
    public function loadFromString(string $data, string $title = '', ?string $wal = null): Database
    {
        if (null !== $wal) {
            $data = Wal::apply($data, $wal);
        }
        $file = new SqliteFile($data);
        $database = new Database();
        $database->getDocumentProperties()->setTitle($title);
        $parser = new Parser();
        $foreignKeys = [];
        foreach ($file->getSchema() as $entry) {
            if ('table' !== $entry['type'] || null === $entry['sql'] || str_starts_with(strtolower($entry['name']), 'sqlite_')) {
                continue;
            }

            try {
                $definition = $parser->parseCreateTable($entry['sql']);
            } catch (ReaderException $exception) {
                // Virtual tables and unsupported definitions
                continue;
            }
            $table = $definition['table'];
            $table->setName($entry['name']);
            $database->addTable($table);
            $foreignKeys[] = [$table, $definition['foreignKeys']];
            if ($this->skipRows() || $entry['rootpage'] <= 0) {
                continue;
            }
            $columns = $table->getColumns();
            if ($definition['withoutRowid']) {
                // Records of WITHOUT ROWID tables: primary key columns first, then the others
                $primary = $table->getPrimaryKeyColumns();
                $others = array_values(array_filter($columns, static fn (Column $column): bool => !$column->isPrimaryKey()));
                $columns = array_merge($primary, $others);
            }
            foreach ($file->readTable($entry['rootpage']) as $rowid => $values) {
                $row = [];
                foreach ($columns as $position => $column) {
                    if ($column->getName() === $definition['rowidColumn']) {
                        $row[$column->getName()] = $rowid;

                        continue;
                    }
                    $row[$column->getName()] = array_key_exists($position, $values)
                        ? $this->convert($file, $column, $values[$position])
                        : $column->getDefaultValue();
                }
                $this->addRow($table, $row);
            }
        }
        foreach ($foreignKeys as [$table, $specs]) {
            Parser::resolveForeignKeys($database, $table, $specs);
        }
        foreach ($file->getSchema() as $entry) {
            if (null === $entry['sql']) {
                continue;
            }

            try {
                switch ($entry['type']) {
                    case 'index':
                        $definition = $parser->parseCreateIndex($entry['sql']);
                        $table = $database->getTable($definition['table']);
                        if (null !== $table && null === $table->getIndex($entry['name'])) {
                            $table->addIndex(Parser::createIndex($definition, $entry['name']));
                        }

                        break;
                    case 'view':
                        $definition = $parser->parseCreateView($entry['sql']);
                        if (!$database->hasTable($entry['name']) && null === $database->getView($entry['name'])) {
                            $database->addView(new View($entry['name'], $definition['sql']));
                        }

                        break;
                    case 'trigger':
                        $definition = $parser->parseCreateTrigger($entry['sql']);
                        $database->addTrigger(new Trigger($entry['name'], $definition['table'], $entry['sql']));

                        break;
                }
            } catch (ReaderException|InvalidArgumentException $exception) {
                // Unsupported statement, unknown column (rowid...)
            }
        }

        return $database;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function addRow(Table $table, array $row): void
    {
        try {
            $table->addRow($row);
        } catch (Throwable $exception) {
            // Values not matching the declared type (SQLite is dynamically typed): kept as text
            foreach ($table->getColumns() as $column) {
                try {
                    $column->normalizeValue($row[$column->getName()]);
                } catch (Throwable $exception) {
                    $row[$column->getName()] = null;
                }
            }
            $table->addRow($row);
        }
    }

    private function convert(SqliteFile $file, Column $column, mixed $value): mixed
    {
        if (null === $value) {
            return null;
        }
        if ($value instanceof Blob) {
            return ColumnType::BLOB === $column->getType() || ColumnType::isText($column->getType()) ? $value->data : null;
        }
        if (is_string($value)) {
            $value = $file->text($value);
        }
        switch ($column->getType()) {
            case ColumnType::DATE:
            case ColumnType::TIME:
            case ColumnType::DATETIME:
                try {
                    if (is_int($value)) {
                        return new DateTimeImmutable('@' . $value);
                    }
                    if (is_float($value)) {
                        // Julian day
                        return new DateTimeImmutable('@' . (string) round(($value - 2440587.5) * 86400));
                    }

                    return new DateTimeImmutable($value);
                } catch (Throwable $exception) {
                    return null;
                }
            case ColumnType::DECIMAL:
                return is_numeric($value) ? Column::formatDecimal((string) $value, $column->getScale()) : null;
            case ColumnType::BLOB:
                return (string) $value;
            case ColumnType::BOOLEAN:
                return is_string($value) ? $column->normalizeValue($value) : (bool) $value;
            default:
                if (is_float($value) && ColumnType::isInteger($column->getType()) && floor($value) !== $value) {
                    return null;
                }

                return $value;
        }
    }
}
