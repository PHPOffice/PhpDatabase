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

namespace PhpOffice\PhpDatabase\Reader\Odb;

use DateTimeInterface;
use InvalidArgumentException;
use PhpOffice\PhpDatabase\Column;
use PhpOffice\PhpDatabase\ColumnType;
use PhpOffice\PhpDatabase\Database;
use PhpOffice\PhpDatabase\Exception\ReaderException;
use PhpOffice\PhpDatabase\Index;
use PhpOffice\PhpDatabase\Shared\Sql\Dialect;
use PhpOffice\PhpDatabase\Shared\Sql\Parser;
use PhpOffice\PhpDatabase\Shared\Sql\Tokenizer;
use PhpOffice\PhpDatabase\Table;
use PhpOffice\PhpDatabase\View;
use Throwable;

/**
 * Reader of an HSQLDB 1.8 database: the "script" file (DDL + rows of MEMORY tables)
 * and the "data" file (rows of CACHED tables).
 */
class ScriptReader
{
    /**
     * @var Parser
     */
    private $parser;

    public function __construct()
    {
        $this->parser = new Parser(true, Dialect::HSQLDB);
    }

    /**
     * Parse "properties" (Java properties file).
     *
     * @return array<string, string>
     */
    public static function parseProperties(string $content): array
    {
        $properties = [];
        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            $line = trim($line);
            if ('' === $line || '#' === $line[0] || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $properties[trim($key)] = trim($value);
        }

        return $properties;
    }

    /**
     * Tables by upper case name, with the types declared for their columns.
     *
     * @var array<string, array{table: Table, types: array<int, string>}>
     */
    private $tables = [];

    /**
     * Roots of the indexes of the CACHED tables, by upper case name of table.
     *
     * @var array<string, array<int, int>>
     */
    private $roots = [];

    /**
     * Foreign keys to resolve once all the tables are known.
     *
     * @var array<int, array{0: Table, 1: array<int, mixed>}>
     */
    private $foreignKeys = [];

    /**
     * @var array<int, View>
     */
    private $views = [];

    /**
     * Read the database: the script, the rows of the CACHED tables, then the statements of the log
     * (changes not yet written in the script and the data file when the database was not closed).
     *
     * @param array<string, string> $properties
     */
    public function read(string $script, ?string $data, array $properties = [], bool $skipRows = false, ?string $log = null): Database
    {
        if ('3' === ($properties['hsqldb.script_format'] ?? '0') || ('' !== $script && !mb_check_encoding(substr($script, 0, 64), 'ASCII'))) {
            // Compressed script (java.util.zip.Deflater)
            $inflated = @gzuncompress($script);
            if (false === $inflated) {
                throw new ReaderException('HSQLDB: unsupported script format');
            }
            $script = $inflated;
        }
        $this->tables = [];
        $this->roots = [];
        $this->foreignKeys = [];
        $this->views = [];
        foreach (preg_split('/\R/', $script) ?: [] as $line) {
            $this->statement($line, $skipRows);
        }
        if (!$skipRows && null !== $data && '' !== $data) {
            $dataFile = new DataFile($data, (int) ($properties['hsqldb.cache_file_scale'] ?? 1));
            foreach ($this->roots as $key => $positions) {
                if (!isset($this->tables[$key]) || count($positions) < 2 || $positions[0] <= 0) {
                    continue;
                }
                // Last number: next value of the identity column
                $indexCount = count($positions) - 1;
                foreach ($dataFile->readRows($positions[0], $indexCount, $this->tables[$key]['types']) as $values) {
                    $this->addRow($this->tables[$key]['table'], $values);
                }
            }
        }
        if (null !== $log && '' !== $log) {
            $this->replayLog($log, $skipRows);
        }

        $database = new Database();
        foreach ($this->tables as $entry) {
            $database->addTable($entry['table']);
        }
        foreach ($this->foreignKeys as [$table, $specs]) {
            Parser::resolveForeignKeys($database, $table, $specs);
        }
        foreach ($this->views as $view) {
            if (!$database->hasTable($view->getName()) && null === $database->getView($view->getName())) {
                $database->addView($view);
            }
        }

        return $database;
    }

    /**
     * Statement of the script or of the log. Returns false for the statements changing rows when
     * $rowAction is given: they are passed to it instead of being applied.
     *
     * @param null|callable(array{0: string, 1: string, 2: mixed}): void $rowAction
     */
    private function statement(string $line, bool $skipRows, ?callable $rowAction = null): void
    {
        try {
            if (str_starts_with($line, 'CREATE ') && 1 === preg_match('/^CREATE (?:(?:MEMORY|CACHED|TEXT|TEMP|GLOBAL|TEMPORARY) )*TABLE /', $line)) {
                $definition = $this->parser->parseCreateTable($line);
                $table = $definition['table'];
                $types = [];
                foreach ($table->getColumns() as $column) {
                    $types[] = strtoupper((string) preg_replace('/\s*\(.*$/', '', $definition['declaredTypes'][$column->getName()]));
                }
                $this->tables[strtoupper($table->getName())] = ['table' => $table, 'types' => $types];
                $this->foreignKeys[] = [$table, $definition['foreignKeys']];
            } elseif (1 === preg_match('/^CREATE (UNIQUE )?INDEX /', $line)) {
                $definition = $this->parser->parseCreateIndex($line);
                $table = $this->tables[strtoupper($definition['table'])]['table'] ?? null;
                if (null !== $table && null === $table->getIndex($definition['name'])) {
                    $table->addIndex(Parser::createIndex($definition));
                }
            } elseif (str_starts_with($line, 'CREATE VIEW ')) {
                $definition = $this->parser->parseCreateView($line);
                $this->views[] = new View($definition['name'], $definition['sql']);
            } elseif (str_starts_with($line, 'DROP TABLE ') || str_starts_with($line, 'DROP VIEW ')) {
                $tokens = (new Tokenizer(true))->tokenize($line);
                $name = $tokens[2] ?? null;
                if (null !== $name) {
                    unset($this->tables[strtoupper($name->value)]);
                    $this->views = array_values(array_filter($this->views, static fn (View $view): bool => 0 !== strcasecmp($view->getName(), $name->value)));
                }
            } elseif (str_starts_with($line, 'ALTER TABLE ') && str_contains($line, ' ADD ')) {
                $definition = $this->parser->parseAlterTable($line);
                $table = $this->tables[strtoupper($definition['table'])]['table'] ?? null;
                if (null !== $table) {
                    $this->foreignKeys[] = [$table, $definition['foreignKeys']];
                    foreach ($definition['uniques'] as $unique) {
                        $name = '' !== $unique['name'] ? $unique['name'] : $table->getName() . '_' . implode('_', $unique['columns']) . '_key';
                        if (null === $table->getIndex($name)) {
                            $table->addIndex(new Index($name, $unique['columns'], true));
                        }
                    }
                    foreach ($definition['checks'] as $check) {
                        $table->addCheck($check);
                    }
                }
            } elseif (str_starts_with($line, 'SET TABLE ') && 1 === preg_match("/^SET TABLE (.+) INDEX'([-0-9 ]*)'$/", $line, $match)) {
                $tokens = (new Tokenizer(true))->tokenize($match[1]);
                $name = end($tokens);
                if (false !== $name) {
                    $this->roots[strtoupper($name->value)] = array_map('intval', explode(' ', trim($match[2])));
                }
            } elseif (!$skipRows && str_starts_with($line, 'INSERT INTO ')) {
                $insert = $this->parser->parseInsert($line);
                $key = strtoupper($insert['table']);
                if (null !== $rowAction) {
                    $rowAction(['insert', $key, $insert['values']]);
                } elseif (isset($this->tables[$key])) {
                    $this->addRow($this->tables[$key]['table'], $insert['values']);
                }
            } elseif (!$skipRows && null !== $rowAction && str_starts_with($line, 'DELETE FROM ')) {
                $delete = $this->parser->parseDelete($line);
                $rowAction(['delete', strtoupper($delete['table']), $delete['conditions']]);
            }
        } catch (ReaderException|InvalidArgumentException $exception) {
            // Unsupported statement, unknown column...
        }
    }

    /**
     * Replay the log: statements prefixed by the session ("/*C1*\/") when it changes; the row changes of a
     * session without auto-commit are applied on COMMIT, dropped on ROLLBACK or at the end of the log.
     */
    private function replayLog(string $log, bool $skipRows): void
    {
        /** @var array<string, array{autoCommit: bool, pending: array<int, array{0: string, 1: string, 2: mixed}>}> $sessions */
        $sessions = [];
        $session = '0';
        foreach (preg_split('/\R/', $log) ?: [] as $line) {
            if (1 === preg_match('#^/\*C(\d+)\*/#', $line, $match)) {
                $session = $match[1];
                $line = substr($line, strlen($match[0]));
            }
            if ('' === trim($line)) {
                continue;
            }
            $sessions[$session] ??= ['autoCommit' => true, 'pending' => []];
            $state = &$sessions[$session];
            $upper = strtoupper(trim($line));
            if ('COMMIT' === $upper || str_starts_with($upper, 'COMMIT ') || 'SET AUTOCOMMIT TRUE' === $upper) {
                foreach ($state['pending'] as $action) {
                    $this->applyRowAction($action);
                }
                $state['pending'] = [];
                if ('SET AUTOCOMMIT TRUE' === $upper) {
                    $state['autoCommit'] = true;
                }
            } elseif ('ROLLBACK' === $upper || str_starts_with($upper, 'ROLLBACK ') || 'DISCONNECT' === $upper) {
                $state['pending'] = [];
            } elseif ('SET AUTOCOMMIT FALSE' === $upper) {
                $state['autoCommit'] = false;
            } else {
                $this->statement($line, $skipRows, function (array $action) use (&$state): void {
                    if ($state['autoCommit']) {
                        $this->applyRowAction($action);
                    } else {
                        $state['pending'][] = $action;
                    }
                });
            }
            unset($state);
        }
    }

    /**
     * @param array{0: string, 1: string, 2: mixed} $action
     */
    private function applyRowAction(array $action): void
    {
        [$type, $key, $arguments] = $action;
        if (!isset($this->tables[$key])) {
            return;
        }
        $table = $this->tables[$key]['table'];
        if ('insert' === $type) {
            $this->addRow($table, (array) $arguments);

            return;
        }
        // Delete the first row matching the conditions
        $conditions = [];
        foreach ((array) $arguments as $name => $value) {
            $column = $table->getColumn((string) $name);
            if (null === $column) {
                return;
            }

            try {
                $conditions[$column->getName()] = $this->normalize($column, $value);
            } catch (Throwable $exception) {
                return;
            }
        }
        foreach ($table->getRows() as $position => $row) {
            foreach ($conditions as $name => $value) {
                if (!self::sameValue($row[$name], $value)) {
                    continue 2;
                }
            }
            $table->removeRow($position);

            return;
        }
    }

    private static function sameValue(mixed $a, mixed $b): bool
    {
        if ($a instanceof DateTimeInterface && $b instanceof DateTimeInterface) {
            return $a->format('Y-m-d H:i:s.u') === $b->format('Y-m-d H:i:s.u');
        }

        return $a === $b;
    }

    /**
     * @param array<int, mixed> $values
     */
    private function addRow(Table $table, array $values): void
    {
        $row = [];
        foreach ($table->getColumns() as $position => $column) {
            try {
                $row[] = $this->normalize($column, $values[$position] ?? null);
            } catch (Throwable $exception) {
                $row[] = null;
            }
        }
        $table->addRow($row);
    }

    private function normalize(Column $column, mixed $value): mixed
    {
        if (is_string($value) && ColumnType::BLOB === $column->getType() && 1 === preg_match('/^(?:[0-9a-fA-F]{2})*$/', $value)) {
            // Binary values are written as hexadecimal strings in the script
            $value = (string) hex2bin($value);
        }

        return $column->normalizeValue($value);
    }
}
