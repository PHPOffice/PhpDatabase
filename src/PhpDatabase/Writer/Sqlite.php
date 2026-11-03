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

namespace PhpOffice\PhpDatabase\Writer;

use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use PhpOffice\PhpDatabase\Column;
use PhpOffice\PhpDatabase\ColumnType;
use PhpOffice\PhpDatabase\Database;
use PhpOffice\PhpDatabase\Exception\WriterException;
use PhpOffice\PhpDatabase\Expression;
use PhpOffice\PhpDatabase\ForeignKey;
use PhpOffice\PhpDatabase\Index;
use PhpOffice\PhpDatabase\Shared\Sql\Dialect;
use PhpOffice\PhpDatabase\Shared\Sql\TypeMapper;
use PhpOffice\PhpDatabase\Shared\Sqlite\Blob;
use PhpOffice\PhpDatabase\Table;
use PhpOffice\PhpDatabase\Writer\Sqlite\Evaluator;
use PhpOffice\PhpDatabase\Writer\Sqlite\SqliteBuilder;

/**
 * Writer for SQLite 3 databases.
 *
 * Dates are stored as ISO 8601 text, booleans as 0/1, decimals as numbers when they are exact.
 */
class Sqlite extends AbstractWriter
{
    /**
     * @var int
     */
    protected $pageSize = 4096;

    public function getPageSize(): int
    {
        return $this->pageSize;
    }

    public function setPageSize(int $pageSize): self
    {
        if ($pageSize < 512 || $pageSize > 65536 || ($pageSize & ($pageSize - 1)) !== 0) {
            throw new WriterException('The page size must be a power of two between 512 and 65536');
        }
        $this->pageSize = $pageSize;

        return $this;
    }

    public function save(string $filename): void
    {
        $this->writeFile($filename, $this->getContent());
    }

    public function getContent(): string
    {
        $this->warnings = [];
        $builder = new SqliteBuilder($this->pageSize);
        $database = $this->getDatabaseOrFail();
        foreach ($database->getTables() as $table) {
            $this->addTable($builder, $table, $database);
        }
        foreach ($database->getViews() as $view) {
            $sql = Dialect::convert($view->getSql(), Dialect::STANDARD, Dialect::SQLITE);
            if (!Dialect::isPortableSelect($view->getSql())) {
                $this->addWarning(sprintf('View "%s" not written: unsupported SQL', $view->getName()));

                continue;
            }
            $builder->addSchemaEntry('view', $view->getName(), $view->getName(), 'CREATE VIEW ' . self::quoteIdentifier($view->getName()) . ' AS ' . $sql);
        }
        foreach ($database->getTriggers() as $trigger) {
            if (null === $database->getTable($trigger->getTable()) && null === $database->getView($trigger->getTable())) {
                $this->addWarning(sprintf('Trigger "%s" not written: unknown table "%s"', $trigger->getName(), $trigger->getTable()));

                continue;
            }
            $builder->addSchemaEntry('trigger', $trigger->getName(), $trigger->getTable(), $trigger->getSql());
        }

        return $builder->build();
    }

    private function addTable(SqliteBuilder $builder, Table $table, Database $database): void
    {
        if (0 === $table->getColumnCount()) {
            throw new WriterException(sprintf('The table "%s" has no column', $table->getName()));
        }
        $primary = $table->getPrimaryKeyColumns();
        $withoutRowid = $table->isWithoutRowid() && !empty($primary);
        $rowidColumn = !$withoutRowid && 1 === count($primary) && ColumnType::INTEGER === $primary[0]->getType() ? $primary[0] : null;

        $definitions = [];
        foreach ($table->getColumns() as $column) {
            $definition = self::quoteIdentifier($column->getName()) . ' ' . TypeMapper::toSqlType($column);
            if ($column === $rowidColumn) {
                $definition .= ' PRIMARY KEY';
            }
            if (!$column->isNullable() && $column !== $rowidColumn) {
                $definition .= ' NOT NULL';
            }
            $default = $column->getDefaultValue();
            if ($default instanceof Expression) {
                $definition .= ' DEFAULT ' . ($default->isCurrentDateTime() ? $default->getSql() : '(' . Dialect::convert($default->getSql(), Dialect::STANDARD, Dialect::SQLITE) . ')');
            } elseif (null !== $default) {
                $definition .= ' DEFAULT ' . $this->literal($column, $default);
            }
            $definitions[] = $definition;
        }
        if (!empty($primary) && null === $rowidColumn) {
            $definitions[] = 'PRIMARY KEY (' . self::columnList(array_map(static fn (Column $column): string => $column->getName(), $primary)) . ')';
        }
        foreach ($table->getForeignKeys() as $foreignKey) {
            if (null === $database->getTable($foreignKey->getReferencedTable())) {
                $this->addWarning(sprintf('Foreign key of the table "%s" not written: unknown table "%s"', $table->getName(), $foreignKey->getReferencedTable()));

                continue;
            }
            $definition = '' !== $foreignKey->getName() ? 'CONSTRAINT ' . self::quoteIdentifier($foreignKey->getName()) . ' ' : '';
            $definition .= 'FOREIGN KEY (' . self::columnList($foreignKey->getColumns()) . ') REFERENCES '
                . self::quoteIdentifier($foreignKey->getReferencedTable()) . ' (' . self::columnList($foreignKey->getReferencedColumns()) . ')';
            if (ForeignKey::NO_ACTION !== $foreignKey->getOnDelete()) {
                $definition .= ' ON DELETE ' . $foreignKey->getOnDelete();
            }
            if (ForeignKey::NO_ACTION !== $foreignKey->getOnUpdate()) {
                $definition .= ' ON UPDATE ' . $foreignKey->getOnUpdate();
            }
            $definitions[] = $definition;
        }
        foreach ($table->getChecks() as $check) {
            $definitions[] = ('' !== $check->getName() ? 'CONSTRAINT ' . self::quoteIdentifier($check->getName()) . ' ' : '')
                . 'CHECK (' . Dialect::convert($check->getExpression(), Dialect::STANDARD, Dialect::SQLITE) . ')';
        }
        $sql = 'CREATE TABLE ' . self::quoteIdentifier($table->getName()) . ' (' . implode(', ', $definitions) . ')' . ($withoutRowid ? ' WITHOUT ROWID' : '');

        // Values of each row, by position of the columns
        $rows = [];
        $nextRowid = 1;
        if (null !== $rowidColumn) {
            foreach ($table->getRows() as $row) {
                if (is_int($row[$rowidColumn->getName()])) {
                    $nextRowid = max($nextRowid, $row[$rowidColumn->getName()] + 1);
                }
            }
        }
        foreach ($table->getRows() as $row) {
            $values = [];
            foreach ($table->getColumns() as $column) {
                $values[] = $this->storageValue($column, $row[$column->getName()]);
            }
            $rowid = null !== $rowidColumn && is_int($row[$rowidColumn->getName()]) ? $row[$rowidColumn->getName()] : $nextRowid++;
            if (isset($rows[$rowid])) {
                throw new WriterException(sprintf('Duplicate primary key %d in the table "%s"', $rowid, $table->getName()));
            }
            $rows[$rowid] = [$rowid, $values];
        }
        ksort($rows);
        $positions = array_flip(array_map(static fn (Column $column): string => $column->getName(), $table->getColumns()));
        $primaryPositions = array_map(static fn (Column $column): int => $positions[$column->getName()], $primary);

        if ($withoutRowid) {
            // Records: primary key columns first, then the other columns
            $others = array_values(array_diff(array_keys($table->getColumns()), $primaryPositions));
            $records = [];
            $keys = [];
            foreach ($rows as [, $values]) {
                $key = serialize(array_map(static fn (int $position) => $values[$position], $primaryPositions));
                if (isset($keys[$key])) {
                    throw new WriterException(sprintf('Duplicate primary key in the table "%s"', $table->getName()));
                }
                $keys[$key] = true;
                $records[] = array_merge(array_map(static fn (int $position) => $values[$position], $primaryPositions), array_map(static fn (int $position) => $values[$position], $others));
            }
            $builder->addWithoutRowidTable($table->getName(), $sql, $records);
        } else {
            $tableRows = [];
            foreach ($rows as $rowid => [, $values]) {
                if (null !== $rowidColumn) {
                    $values[$positions[$rowidColumn->getName()]] = null;
                }
                $tableRows[] = [$rowid, $values];
            }
            $builder->addTable($table->getName(), $sql, $tableRows);
            if (!empty($primary) && null === $rowidColumn) {
                $entries = [];
                $keys = [];
                foreach ($rows as $rowid => [, $values]) {
                    $entry = array_map(static fn (int $position) => $values[$position], $primaryPositions);
                    $key = serialize($entry);
                    if (!in_array(null, $entry, true) && isset($keys[$key])) {
                        throw new WriterException(sprintf('Duplicate primary key in the table "%s"', $table->getName()));
                    }
                    $keys[$key] = true;
                    $entry[] = $rowid;
                    $entries[] = $entry;
                }
                $builder->addIndex('sqlite_autoindex_' . $table->getName() . '_1', $table->getName(), null, $entries);
            }
        }

        foreach ($table->getIndexes() as $index) {
            try {
                $this->addIndex($builder, $table, $index, $rows, $positions, $rowidColumn, $withoutRowid ? $primaryPositions : null);
            } catch (InvalidArgumentException $exception) {
                $this->addWarning(sprintf('Index "%s" of the table "%s" not written: %s', $index->getName(), $table->getName(), $exception->getMessage()));
            }
        }
    }

    /**
     * Entries of an index: values of its columns/expressions, then the rowid (or the primary key of a
     * WITHOUT ROWID table).
     *
     * @param array<int, array{0: int, 1: array<int, mixed>}> $rows
     * @param array<string, int> $positions
     * @param null|array<int, int> $primaryPositions primary key of a WITHOUT ROWID table
     */
    private function addIndex(SqliteBuilder $builder, Table $table, Index $index, array $rows, array $positions, ?Column $rowidColumn, ?array $primaryPositions): void
    {
        $getters = [];
        $sqlColumns = [];
        foreach ($index->getColumns() as $position => $name) {
            $expression = $index->getExpression($position);
            if (null !== $expression) {
                $getters[] = Evaluator::compile($expression->getSql());
                $sqlColumns[] = Dialect::convert($expression->getSql(), Dialect::STANDARD, Dialect::SQLITE);
            } else {
                $column = $table->getColumn($name) ?? throw new WriterException(sprintf('Unknown column "%s" in the index "%s"', $name, $index->getName()));
                $columnPosition = $positions[$column->getName()];
                $getters[] = $column === $rowidColumn ? null : $columnPosition;
                $sqlColumns[] = self::quoteIdentifier($column->getName());
            }
            if ($index->isDescending($position)) {
                $sqlColumns[count($sqlColumns) - 1] .= ' DESC';
            }
        }
        $where = null === $index->getWhere() ? null : Evaluator::compile($index->getWhere());
        $suffix = null === $primaryPositions ? [] : array_values(array_filter($primaryPositions, static function (int $position) use ($getters): bool {
            // The columns of the primary key already in the index are not repeated
            return !in_array($position, $getters, true);
        }));
        $entries = [];
        $keys = [];
        foreach ($rows as $rowid => [, $values]) {
            $named = [];
            if (null !== $where || !empty(array_filter($getters, static fn ($getter): bool => $getter instanceof Closure))) {
                $named = ['rowid' => $rowid];
                foreach ($table->getColumns() as $columnPosition => $column) {
                    $named[strtolower($column->getName())] = $column === $rowidColumn ? $rowid : $values[$columnPosition];
                }
            }
            if (null !== $where && true !== Evaluator::isTrue($where($named))) {
                continue;
            }
            $entry = [];
            foreach ($getters as $getter) {
                if ($getter instanceof Closure) {
                    $entry[] = $getter($named);
                } else {
                    $entry[] = null === $getter ? $rowid : $values[$getter];
                }
            }
            if ($index->isUnique() && !in_array(null, $entry, true)) {
                $key = serialize($entry);
                if (isset($keys[$key])) {
                    throw new WriterException(sprintf('Duplicate value in the unique index "%s" of the table "%s"', $index->getName(), $table->getName()));
                }
                $keys[$key] = true;
            }
            if (null === $primaryPositions) {
                $entry[] = $rowid;
            } else {
                foreach ($suffix as $position) {
                    $entry[] = $values[$position];
                }
            }
            $entries[] = $entry;
        }
        $sql = 'CREATE ' . ($index->isUnique() ? 'UNIQUE ' : '') . 'INDEX ' . self::quoteIdentifier($index->getName())
            . ' ON ' . self::quoteIdentifier($table->getName()) . ' (' . implode(', ', $sqlColumns) . ')';
        if (null !== $index->getWhere()) {
            $sql .= ' WHERE ' . Dialect::convert($index->getWhere(), Dialect::STANDARD, Dialect::SQLITE);
        }
        $descending = [];
        foreach (array_keys($index->getColumns()) as $position) {
            $descending[$position] = $index->isDescending($position);
        }
        $builder->addIndex($index->getName(), $table->getName(), $sql, $entries, $descending);
    }

    /**
     * @param array<int, string> $names
     */
    private static function columnList(array $names): string
    {
        return implode(', ', array_map([self::class, 'quoteIdentifier'], $names));
    }

    /**
     * @return null|Blob|float|int|string
     */
    private function storageValue(Column $column, mixed $value)
    {
        if (null === $value) {
            return null;
        }
        if (TypeMapper::isComplex($column)) {
            return TypeMapper::flatten($column, $value);
        }
        switch ($column->getType()) {
            case ColumnType::BOOLEAN:
                return $value ? 1 : 0;
            case ColumnType::FLOAT:
            case ColumnType::DOUBLE:
                return (float) $value;
            case ColumnType::DECIMAL:
                $string = (string) $value;
                if (1 === preg_match('/^-?\d+(\.0*)?$/', $string) && abs((float) $string) < 9.2e18) {
                    return (int) $string;
                }
                $float = (float) $string;
                if (Column::formatDecimal((string) $float, $column->getScale()) === Column::formatDecimal($string, $column->getScale())) {
                    return $float;
                }

                return $string;
            case ColumnType::DATE:
            case ColumnType::TIME:
            case ColumnType::DATETIME:
                return self::formatDate($column, $value);
            case ColumnType::BLOB:
                return new Blob((string) $value);
            default:
                return is_int($value) ? $value : (string) $value;
        }
    }

    private function literal(Column $column, mixed $value): string
    {
        $value = $this->storageValue($column, $value);
        if (null === $value) {
            return 'NULL';
        }
        if ($value instanceof Blob) {
            return "X'" . bin2hex($value->data) . "'";
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return "'" . str_replace("'", "''", $value) . "'";
    }

    public static function formatDate(Column $column, mixed $value): string
    {
        if (!$value instanceof DateTimeImmutable) {
            return (string) $value;
        }
        switch ($column->getType()) {
            case ColumnType::DATE:
                return $value->format('Y-m-d');
            case ColumnType::TIME:
                return $value->format('H:i:s');
            default:
                $digits = $column->getFractionalSeconds();

                return $value->format('Y-m-d H:i:s') . (null !== $digits && $digits > 0 ? '.' . substr($value->format('u'), 0, min(6, $digits)) : '');
        }
    }

    public static function quoteIdentifier(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }
}
