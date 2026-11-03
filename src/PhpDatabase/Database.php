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

namespace PhpOffice\PhpDatabase;

use InvalidArgumentException;

/**
 * The database document (like PhpPresentation for presentations): properties and tables.
 */
class Database
{
    /**
     * @var DocumentProperties
     */
    protected $documentProperties;

    /**
     * @var array<int, Table>
     */
    protected $tables = [];

    /**
     * @var array<int, View>
     */
    protected $views = [];

    /**
     * @var array<int, Trigger>
     */
    protected $triggers = [];

    public function __construct()
    {
        $this->documentProperties = new DocumentProperties();
    }

    public function getDocumentProperties(): DocumentProperties
    {
        return $this->documentProperties;
    }

    public function setDocumentProperties(DocumentProperties $documentProperties): self
    {
        $this->documentProperties = $documentProperties;

        return $this;
    }

    /**
     * Create a table and add it to the database.
     */
    public function createTable(string $name): Table
    {
        $table = new Table($name);
        $this->addTable($table);

        return $table;
    }

    public function addTable(Table $table): self
    {
        if ($this->hasTable($table->getName()) || null !== $this->getView($table->getName())) {
            throw new InvalidArgumentException(sprintf('The table "%s" already exists', $table->getName()));
        }
        $this->tables[] = $table;

        return $this;
    }

    /**
     * @return array<int, Table>
     */
    public function getTables(): array
    {
        return $this->tables;
    }

    public function getTableCount(): int
    {
        return count($this->tables);
    }

    /**
     * Table by name (case insensitive, like SQL identifiers of the supported engines).
     */
    public function getTable(string $name): ?Table
    {
        foreach ($this->tables as $table) {
            if (0 === strcasecmp($table->getName(), $name)) {
                return $table;
            }
        }

        return null;
    }

    public function hasTable(string $name): bool
    {
        return null !== $this->getTable($name);
    }

    public function removeTable(string $name): self
    {
        foreach ($this->tables as $index => $table) {
            if (0 === strcasecmp($table->getName(), $name)) {
                unset($this->tables[$index]);
                $this->tables = array_values($this->tables);
            }
        }

        return $this;
    }

    /**
     * Create a view (SELECT statement in "standard" SQL, see View) and add it to the database.
     */
    public function createView(string $name, string $sql): View
    {
        $view = new View($name, $sql);
        $this->addView($view);

        return $view;
    }

    public function addView(View $view): self
    {
        if ($this->hasTable($view->getName()) || null !== $this->getView($view->getName())) {
            throw new InvalidArgumentException(sprintf('The table or view "%s" already exists', $view->getName()));
        }
        $this->views[] = $view;

        return $this;
    }

    /**
     * @return array<int, View>
     */
    public function getViews(): array
    {
        return $this->views;
    }

    /**
     * View by name (case insensitive).
     */
    public function getView(string $name): ?View
    {
        foreach ($this->views as $view) {
            if (0 === strcasecmp($view->getName(), $name)) {
                return $view;
            }
        }

        return null;
    }

    public function removeView(string $name): self
    {
        $this->views = array_values(array_filter($this->views, static fn (View $view): bool => 0 !== strcasecmp($view->getName(), $name)));

        return $this;
    }

    /**
     * Create a trigger (complete statement, specific to SQLite) and add it to the database.
     */
    public function createTrigger(string $name, string $table, string $sql): Trigger
    {
        $trigger = new Trigger($name, $table, $sql);
        $this->addTrigger($trigger);

        return $trigger;
    }

    public function addTrigger(Trigger $trigger): self
    {
        $this->triggers[] = $trigger;

        return $this;
    }

    /**
     * @return array<int, Trigger>
     */
    public function getTriggers(): array
    {
        return $this->triggers;
    }

    public function removeTriggers(): self
    {
        $this->triggers = [];

        return $this;
    }
}
