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
 * Secondary index of a table (the primary key is defined on the columns).
 */
class Index
{
    /**
     * @var string
     */
    protected $name;

    /**
     * Names of the indexed columns, in the order of the index.
     *
     * @var array<int, string>
     */
    protected $columns;

    /**
     * @var bool
     */
    protected $unique;

    /**
     * Positions of the columns sorted in descending order.
     *
     * @var array<int, bool>
     */
    protected $descending = [];

    /**
     * Expressions indexed instead of columns (SQLite), by position.
     *
     * @var array<int, Expression>
     */
    protected $expressions = [];

    /**
     * Condition of a partial index (SQLite), "standard" SQL.
     *
     * @var null|string
     */
    protected $where;

    /**
     * @param array<int|string, Expression|string> $columns names of the columns (list), or name => 'ASC'|'DESC';
     *                                                      an Expression indexes an expression (SQLite)
     */
    public function __construct(string $name, array $columns, bool $unique = false)
    {
        if ('' === trim($name)) {
            throw new InvalidArgumentException('The name of an index can\'t be empty');
        }
        if (empty($columns)) {
            throw new InvalidArgumentException(sprintf('The index "%s" has no column', $name));
        }
        $this->name = $name;
        $this->columns = [];
        foreach ($columns as $key => $value) {
            $position = count($this->columns);
            if (is_string($key)) {
                $this->columns[] = $key;
                $this->descending[$position] = 0 === strcasecmp((string) $value, 'DESC');
            } elseif ($value instanceof Expression) {
                $this->columns[] = $value->getSql();
                $this->expressions[$position] = $value;
            } else {
                $this->columns[] = $value;
            }
        }
        $this->unique = $unique;
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

    /**
     * Names of the columns (SQL text for the expressions, see getExpression()).
     *
     * @return array<int, string>
     */
    public function getColumns(): array
    {
        return $this->columns;
    }

    /**
     * Is the column (name or position) sorted in descending order?
     */
    public function isDescending(int|string $column): bool
    {
        $position = is_int($column) ? $column : $this->getPosition($column);

        return null !== $position && ($this->descending[$position] ?? false);
    }

    public function setDescending(int|string $column, bool $descending = true): self
    {
        $position = is_int($column) ? $column : $this->getPosition($column);
        if (null === $position || !isset($this->columns[$position])) {
            throw new InvalidArgumentException(sprintf('The column "%s" is not in the index "%s"', $column, $this->name));
        }
        $this->descending[$position] = $descending;

        return $this;
    }

    /**
     * Expression indexed at a position, null for a column.
     */
    public function getExpression(int $position): ?Expression
    {
        return $this->expressions[$position] ?? null;
    }

    public function hasExpressions(): bool
    {
        return !empty($this->expressions);
    }

    /**
     * Condition of a partial index, null for an index of all the rows.
     */
    public function getWhere(): ?string
    {
        return $this->where;
    }

    public function setWhere(?string $where): self
    {
        $this->where = null === $where || '' === trim($where) ? null : trim($where);

        return $this;
    }

    /**
     * Names of the indexed columns, without the expressions.
     *
     * @return array<int, string>
     */
    public function getColumnNames(): array
    {
        return array_values(array_diff_key($this->columns, $this->expressions));
    }

    private function getPosition(string $column): ?int
    {
        foreach ($this->columns as $position => $name) {
            if (!isset($this->expressions[$position]) && 0 === strcasecmp($name, $column)) {
                return $position;
            }
        }

        return null;
    }

    public function isUnique(): bool
    {
        return $this->unique;
    }

    public function setUnique(bool $unique = true): self
    {
        $this->unique = $unique;

        return $this;
    }
}
