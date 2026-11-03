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

namespace PhpOffice\PhpDatabase;

use InvalidArgumentException;

/**
 * Table: columns and rows.
 */
class Table
{
    /**
     * @var string
     */
    protected $name;

    /**
     * @var string
     */
    protected $description = '';

    /**
     * @var array<int, Column>
     */
    protected $columns = [];

    /**
     * Rows, each one indexed by column name, in the order of the columns.
     *
     * @var array<int, array<string, mixed>>
     */
    protected $rows = [];

    /**
     * @var array<int, Index>
     */
    protected $indexes = [];

    /**
     * @var array<int, ForeignKey>
     */
    protected $foreignKeys = [];

    /**
     * @var array<int, CheckConstraint>
     */
    protected $checks = [];

    /**
     * SQLite: table without rowid (clustered on its primary key).
     *
     * @var bool
     */
    protected $withoutRowid = false;

    /**
     * Last value used by each auto-increment column.
     *
     * @var array<string, int>
     */
    private $autoIncrements = [];

    public function __construct(string $name)
    {
        if ('' === trim($name)) {
            throw new InvalidArgumentException('The name of a table can\'t be empty');
        }
        $this->name = $name;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Create a column and add it to the table.
     */
    public function createColumn(string $name, string $type = ColumnType::TEXT, ?int $length = null): Column
    {
        $column = new Column($name, $type, $length);
        $this->addColumn($column);

        return $column;
    }

    public function addColumn(Column $column): self
    {
        if (null !== $this->getColumn($column->getName())) {
            throw new InvalidArgumentException(sprintf('The column "%s" already exists in the table "%s"', $column->getName(), $this->name));
        }
        $this->columns[] = $column;
        foreach ($this->rows as &$row) {
            $row[$column->getName()] = $column->getDefaultValue();
        }

        return $this;
    }

    /**
     * @return array<int, Column>
     */
    public function getColumns(): array
    {
        return $this->columns;
    }

    public function getColumnCount(): int
    {
        return count($this->columns);
    }

    /**
     * @return array<int, string>
     */
    public function getColumnNames(): array
    {
        return array_map(static fn (Column $column): string => $column->getName(), $this->columns);
    }

    /**
     * Column by name (case insensitive).
     */
    public function getColumn(string $name): ?Column
    {
        foreach ($this->columns as $column) {
            if (0 === strcasecmp($column->getName(), $name)) {
                return $column;
            }
        }

        return null;
    }

    /**
     * @return array<int, Column>
     */
    public function getPrimaryKeyColumns(): array
    {
        return array_values(array_filter($this->columns, static fn (Column $column): bool => $column->isPrimaryKey()));
    }

    /**
     * Create a secondary index and add it to the table.
     *
     * @param array<int|string, Expression|string> $columns names (list) or name => 'ASC'|'DESC', see Index
     */
    public function createIndex(string $name, array $columns, bool $unique = false): Index
    {
        $index = new Index($name, $columns, $unique);
        $this->addIndex($index);

        return $index;
    }

    public function addIndex(Index $index): self
    {
        if (null !== $this->getIndex($index->getName())) {
            throw new InvalidArgumentException(sprintf('The index "%s" already exists in the table "%s"', $index->getName(), $this->name));
        }
        $this->checkColumns($index->getColumnNames());
        $this->indexes[] = $index;

        return $this;
    }

    /**
     * @return array<int, Index>
     */
    public function getIndexes(): array
    {
        return $this->indexes;
    }

    /**
     * Index by name (case insensitive).
     */
    public function getIndex(string $name): ?Index
    {
        foreach ($this->indexes as $index) {
            if (0 === strcasecmp($index->getName(), $name)) {
                return $index;
            }
        }

        return null;
    }

    public function removeIndex(string $name): self
    {
        $this->indexes = array_values(array_filter($this->indexes, static fn (Index $index): bool => 0 !== strcasecmp($index->getName(), $name)));

        return $this;
    }

    /**
     * Create a foreign key and add it to the table.
     *
     * @param array<int, string> $columns
     * @param array<int, string> $referencedColumns
     */
    public function createForeignKey(array $columns, string $referencedTable, array $referencedColumns, string $name = ''): ForeignKey
    {
        $foreignKey = new ForeignKey($name, $columns, $referencedTable, $referencedColumns);
        $this->addForeignKey($foreignKey);

        return $foreignKey;
    }

    public function addForeignKey(ForeignKey $foreignKey): self
    {
        $this->checkColumns($foreignKey->getColumns());
        $this->foreignKeys[] = $foreignKey;

        return $this;
    }

    /**
     * @return array<int, ForeignKey>
     */
    public function getForeignKeys(): array
    {
        return $this->foreignKeys;
    }

    public function removeForeignKeys(): self
    {
        $this->foreignKeys = [];

        return $this;
    }

    /**
     * Create a check constraint ("standard" SQL boolean expression) and add it to the table.
     */
    public function createCheck(string $expression, string $name = ''): CheckConstraint
    {
        $check = new CheckConstraint($expression, $name);
        $this->addCheck($check);

        return $check;
    }

    public function addCheck(CheckConstraint $check): self
    {
        $this->checks[] = $check;

        return $this;
    }

    /**
     * @return array<int, CheckConstraint>
     */
    public function getChecks(): array
    {
        return $this->checks;
    }

    public function removeChecks(): self
    {
        $this->checks = [];

        return $this;
    }

    public function isWithoutRowid(): bool
    {
        return $this->withoutRowid;
    }

    /**
     * SQLite: write the table WITHOUT ROWID (it needs a primary key).
     */
    public function setWithoutRowid(bool $withoutRowid = true): self
    {
        $this->withoutRowid = $withoutRowid;

        return $this;
    }

    /**
     * Whether the columns are the primary key or the columns of a unique index (in any order),
     * as required for the columns referenced by a foreign key.
     *
     * @param array<int, string> $names
     */
    public function isUniqueKey(array $names): bool
    {
        $normalize = static function (array $names): array {
            $names = array_map('strtolower', $names);
            sort($names);

            return $names;
        };
        $names = $normalize($names);
        if ($names === $normalize(array_map(static fn (Column $column): string => $column->getName(), $this->getPrimaryKeyColumns()))) {
            return true;
        }
        foreach ($this->indexes as $index) {
            if ($index->isUnique() && $names === $normalize($index->getColumns())) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, string> $names
     */
    private function checkColumns(array $names): void
    {
        foreach ($names as $name) {
            if (null === $this->getColumn($name)) {
                throw new InvalidArgumentException(sprintf('Unknown column "%s" in the table "%s"', $name, $this->name));
            }
        }
    }

    /**
     * Add a row.
     *
     * @param array<int|string, mixed> $values indexed by column name, or a list in the order of the columns;
     *                                       missing columns get their default value
     */
    public function addRow(array $values): self
    {
        $this->rows[] = $this->normalizeRow($values);

        return $this;
    }

    /**
     * @param array<int, array<int|string, mixed>> $rows
     */
    public function addRows(array $rows): self
    {
        foreach ($rows as $row) {
            $this->addRow($row);
        }

        return $this;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRows(): array
    {
        return $this->rows;
    }

    /**
     * @return null|array<string, mixed>
     */
    public function getRow(int $index): ?array
    {
        return $this->rows[$index] ?? null;
    }

    public function getRowCount(): int
    {
        return count($this->rows);
    }

    /**
     * Remove a row (by position); the following rows are renumbered.
     */
    public function removeRow(int $index): self
    {
        if (isset($this->rows[$index])) {
            unset($this->rows[$index]);
            $this->rows = array_values($this->rows);
        }

        return $this;
    }

    public function removeRows(): self
    {
        $this->rows = [];
        $this->autoIncrements = [];

        return $this;
    }

    /**
     * @param array<int|string, mixed> $values
     *
     * @return array<string, mixed>
     */
    protected function normalizeRow(array $values): array
    {
        if (empty($this->columns)) {
            throw new InvalidArgumentException(sprintf('The table "%s" has no column', $this->name));
        }
        $isList = array_is_list($values) && !empty($values);
        if ($isList && count($values) > count($this->columns)) {
            throw new InvalidArgumentException(sprintf('Too many values for the table "%s"', $this->name));
        }
        $byName = [];
        if (!$isList) {
            foreach ($values as $key => $value) {
                $column = $this->getColumn((string) $key);
                if (null === $column) {
                    throw new InvalidArgumentException(sprintf('Unknown column "%s" in the table "%s"', $key, $this->name));
                }
                $byName[$column->getName()] = $value;
            }
        }
        $row = [];
        foreach ($this->columns as $position => $column) {
            if ($isList) {
                $value = array_key_exists($position, $values) ? $values[$position] : $column->getDefaultValue();
            } else {
                $value = array_key_exists($column->getName(), $byName) ? $byName[$column->getName()] : $column->getDefaultValue();
            }
            $value = $column->normalizeValue($value);
            if ($column->isAutoIncrement() && ColumnType::isInteger($column->getType())) {
                $last = $this->autoIncrements[$column->getName()] ?? $this->getMaxValue($column);
                if (null === $value) {
                    $value = $last + 1;
                }
                $this->autoIncrements[$column->getName()] = max($last, (int) $value);
            }
            $row[$column->getName()] = $value;
        }

        return $row;
    }

    /**
     * Next value of an auto-increment column.
     */
    public function getNextAutoIncrement(Column $column): int
    {
        return ($this->autoIncrements[$column->getName()] ?? $this->getMaxValue($column)) + 1;
    }

    private function getMaxValue(Column $column): int
    {
        $max = 0;
        foreach ($this->rows as $row) {
            if (is_int($row[$column->getName()] ?? null)) {
                $max = max($max, $row[$column->getName()]);
            }
        }

        return $max;
    }
}
