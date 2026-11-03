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

namespace PhpOffice\PhpDatabase\Shared\Sql;

use InvalidArgumentException;
use PhpOffice\PhpDatabase\CheckConstraint;
use PhpOffice\PhpDatabase\Column;
use PhpOffice\PhpDatabase\Database;
use PhpOffice\PhpDatabase\Exception\ReaderException;
use PhpOffice\PhpDatabase\Expression;
use PhpOffice\PhpDatabase\ForeignKey;
use PhpOffice\PhpDatabase\Index;
use PhpOffice\PhpDatabase\Table;
use Throwable;

/**
 * Parser of the statements found in the files: CREATE TABLE, CREATE INDEX, CREATE VIEW, CREATE TRIGGER,
 * ALTER TABLE ... ADD CONSTRAINT, INSERT INTO ... VALUES and DELETE FROM ... WHERE.
 * Expressions (checks, defaults, views...) are converted to the "standard" SQL of the object model.
 *
 * @phpstan-type ForeignKeySpec array{name: string, columns: array<int, string>, table: string, referencedColumns: array<int, string>, onDelete: string, onUpdate: string}
 */
class Parser
{
    private const CONSTRAINT_KEYWORDS = [
        'CONSTRAINT', 'PRIMARY', 'NOT', 'NULL', 'UNIQUE', 'CHECK', 'DEFAULT', 'COLLATE',
        'REFERENCES', 'GENERATED', 'AS', 'IDENTITY', 'AUTOINCREMENT', 'AUTO_INCREMENT',
    ];

    /**
     * @var array<int, Token>
     */
    private $tokens = [];

    /**
     * @var int
     */
    private $position = 0;

    /**
     * @var Tokenizer
     */
    private $tokenizer;

    /**
     * @var bool
     */
    private $unicodeEscapes;

    /**
     * Dialect of the statements (Dialect::SQLITE or Dialect::HSQLDB).
     *
     * @var string
     */
    private $dialect;

    /**
     * SQL text being parsed.
     *
     * @var string
     */
    private $sql = '';

    /**
     * Check constraints found while parsing a table.
     *
     * @var array<int, CheckConstraint>
     */
    private $checks = [];

    /**
     * Unique constraints found while parsing a table.
     *
     * @var array<int, array{name: string, columns: array<int, string>}>
     */
    private $uniques = [];

    /**
     * Foreign keys found while parsing a table.
     *
     * @var array<int, ForeignKeySpec>
     */
    private $foreignKeys = [];

    public function __construct(bool $unicodeEscapes = false, string $dialect = Dialect::SQLITE)
    {
        $this->tokenizer = new Tokenizer($unicodeEscapes);
        $this->unicodeEscapes = $unicodeEscapes;
        $this->dialect = $dialect;
    }

    /**
     * Parse a CREATE TABLE statement.
     *
     * The returned Table holds the columns. Extra information: `withoutRowid` (SQLite),
     * `rowidColumn` (SQLite INTEGER PRIMARY KEY alias of the rowid), `declaredTypes` and
     * `foreignKeys` (to resolve with resolveForeignKeys() once all the tables are known).
     * Unique constraints are added to the table as unique indexes.
     *
     * @return array{table: Table, withoutRowid: bool, rowidColumn: null|string, declaredTypes: array<string, string>, foreignKeys: array<int, ForeignKeySpec>}
     */
    public function parseCreateTable(string $sql): array
    {
        $this->start($sql);
        $this->uniques = [];
        $this->foreignKeys = [];
        $this->checks = [];
        $this->expect('CREATE');
        while ($this->current()->is('TEMP', 'TEMPORARY', 'MEMORY', 'CACHED', 'TEXT', 'GLOBAL', 'LOCAL', 'VIRTUAL')) {
            if ($this->current()->is('VIRTUAL')) {
                throw new ReaderException('Virtual tables are not supported');
            }
            ++$this->position;
        }
        $this->expect('TABLE');
        if ($this->current()->is('IF')) {
            $this->expect('IF');
            $this->expect('NOT');
            $this->expect('EXISTS');
        }
        $name = $this->readName();
        if ($this->current()->is('.')) {
            ++$this->position;
            $name = $this->readName();
        }
        $table = new Table($name);
        $declaredTypes = [];
        $tablePrimaryKey = [];
        $rowidColumn = null;
        $this->expect('(');
        while (true) {
            $token = $this->current();
            if ($token->is('CONSTRAINT', 'PRIMARY', 'UNIQUE', 'CHECK', 'FOREIGN')) {
                $constraintName = '';
                if ($token->is('CONSTRAINT')) {
                    ++$this->position;
                    $constraintName = $this->readName();
                }
                if ($this->current()->is('PRIMARY')) {
                    $this->expect('PRIMARY');
                    $this->expect('KEY');
                    $tablePrimaryKey = $this->readNameList();
                } else {
                    $this->parseTableConstraint($constraintName);
                }
                $this->skipUntilSeparator();
            } else {
                [$column, $declared, $integerPrimaryKey] = $this->parseColumn();
                $table->addColumn($column);
                $declaredTypes[$column->getName()] = $declared;
                if ($integerPrimaryKey) {
                    $rowidColumn = $column->getName();
                }
            }
            if ($this->current()->is(',')) {
                ++$this->position;

                continue;
            }
            $this->expect(')');

            break;
        }
        foreach ($tablePrimaryKey as $columnName) {
            $column = $table->getColumn($columnName);
            if (null !== $column) {
                $column->setPrimaryKey();
            }
        }
        if (1 === count($tablePrimaryKey)) {
            $column = $table->getColumn($tablePrimaryKey[0]);
            if (null !== $column && 'INTEGER' === strtoupper($declaredTypes[$column->getName()])) {
                $rowidColumn = $column->getName();
            }
        }
        foreach ($this->uniques as $unique) {
            $primaryNames = array_map(static fn (Column $column): string => strtolower($column->getName()), $table->getPrimaryKeyColumns());
            if (array_map('strtolower', $unique['columns']) === $primaryNames) {
                continue;
            }
            $indexName = '' !== $unique['name'] ? $unique['name'] : $name . '_' . implode('_', $unique['columns']) . '_key';
            if (null === $table->getIndex($indexName)) {
                try {
                    $table->addIndex(new Index($indexName, $unique['columns'], true));
                } catch (InvalidArgumentException $exception) {
                    // unknown column
                }
            }
        }
        foreach ($this->checks as $check) {
            $table->addCheck($check);
        }
        $withoutRowid = false;
        while (!$this->isEnd()) {
            if ($this->current()->is('WITHOUT')) {
                $withoutRowid = true;
            }
            ++$this->position;
        }
        if ($withoutRowid) {
            $rowidColumn = null;
            $table->setWithoutRowid();
        }

        return ['table' => $table, 'withoutRowid' => $withoutRowid, 'rowidColumn' => $rowidColumn, 'declaredTypes' => $declaredTypes, 'foreignKeys' => $this->foreignKeys];
    }

    /**
     * Parse a CREATE [UNIQUE] INDEX statement.
     * The columns are names or Expression objects (indexes on expressions); `where` is the condition
     * of a partial index (see createIndex()).
     *
     * @return array{name: string, table: string, columns: array<int, Expression|string>, descending: array<int, bool>, unique: bool, where: null|string, partial: bool}
     */
    public function parseCreateIndex(string $sql): array
    {
        $this->start($sql);
        $this->expect('CREATE');
        $unique = false;
        if ($this->current()->is('UNIQUE')) {
            $unique = true;
            ++$this->position;
        }
        $this->expect('INDEX');
        if ($this->current()->is('IF')) {
            $this->expect('IF');
            $this->expect('NOT');
            $this->expect('EXISTS');
        }
        $name = $this->readQualifiedName();
        $this->expect('ON');
        $table = $this->readQualifiedName();
        $this->expect('(');
        $columns = [];
        $descending = [];
        while (true) {
            $start = $this->position;
            $depth = 0;
            while (!$this->isEnd()) {
                $token = $this->current();
                if ($token->is('(')) {
                    ++$depth;
                } elseif ($token->is(')')) {
                    if (0 === $depth) {
                        break;
                    }
                    --$depth;
                } elseif (0 === $depth && $token->is(',')) {
                    break;
                }
                ++$this->position;
            }
            $end = $this->position;
            $isDescending = false;
            while ($end > $start) {
                if ($this->tokens[$end - 1]->is('ASC', 'DESC')) {
                    $isDescending = $isDescending || $this->tokens[$end - 1]->is('DESC');
                    --$end;
                } elseif ($end - 2 >= $start && $this->tokens[$end - 2]->is('COLLATE')) {
                    $end -= 2;
                } else {
                    break;
                }
            }
            if ($end <= $start) {
                throw new ReaderException('SQL parse error: empty index column');
            }
            if (1 === $end - $start && $this->tokens[$start]->isName()) {
                $columns[] = $this->tokens[$start]->value;
            } else {
                $columns[] = new Expression($this->standard($start, $end));
            }
            $descending[] = $isDescending;
            if ($this->current()->is(',')) {
                ++$this->position;

                continue;
            }
            $this->expect(')');

            break;
        }
        $where = null;
        if ($this->current()->is('WHERE')) {
            $start = ++$this->position;
            while (!$this->isEnd()) {
                ++$this->position;
            }
            $where = $this->standard($start, $this->position);
        }

        return ['name' => $name, 'table' => $table, 'columns' => $columns, 'descending' => $descending, 'unique' => $unique, 'where' => $where, 'partial' => null !== $where];
    }

    /**
     * Index from the result of parseCreateIndex().
     *
     * @param array{name: string, columns: array<int, Expression|string>, descending: array<int, bool>, unique: bool, where: null|string} $definition
     */
    public static function createIndex(array $definition, ?string $name = null): Index
    {
        $index = new Index($name ?? $definition['name'], $definition['columns'], $definition['unique']);
        foreach ($definition['descending'] as $position => $descending) {
            if ($descending) {
                $index->setDescending($position);
            }
        }

        return $index->setWhere($definition['where']);
    }

    /**
     * Parse a CREATE [TEMP] VIEW name [(columns)] AS select statement.
     *
     * @return array{name: string, sql: string}
     */
    public function parseCreateView(string $sql): array
    {
        $this->start($sql);
        $this->expect('CREATE');
        while ($this->current()->is('TEMP', 'TEMPORARY')) {
            ++$this->position;
        }
        $this->expect('VIEW');
        if ($this->current()->is('IF')) {
            $this->expect('IF');
            $this->expect('NOT');
            $this->expect('EXISTS');
        }
        $name = $this->readQualifiedName();
        if ($this->current()->is('(')) {
            $this->readNameList();
        }
        $this->expect('AS');
        $start = $this->position;
        while (!$this->isEnd()) {
            ++$this->position;
        }

        return ['name' => $name, 'sql' => $this->standard($start, $this->position)];
    }

    /**
     * Name and table of a CREATE TRIGGER statement.
     *
     * @return array{name: string, table: string}
     */
    public function parseCreateTrigger(string $sql): array
    {
        $this->start($sql);
        $this->expect('CREATE');
        while ($this->current()->is('TEMP', 'TEMPORARY')) {
            ++$this->position;
        }
        $this->expect('TRIGGER');
        if ($this->current()->is('IF')) {
            $this->expect('IF');
            $this->expect('NOT');
            $this->expect('EXISTS');
        }
        $name = $this->readQualifiedName();
        while (!$this->isEnd() && !$this->current()->is('ON')) {
            ++$this->position;
        }
        $this->expect('ON');

        return ['name' => $name, 'table' => $this->readQualifiedName()];
    }

    /**
     * Parse a DELETE FROM table WHERE column = literal [AND column IS NULL...] statement (HSQLDB log).
     *
     * @return array{table: string, conditions: array<string, mixed>}
     */
    public function parseDelete(string $sql): array
    {
        $this->start($sql);
        $this->expect('DELETE');
        $this->expect('FROM');
        $table = $this->readQualifiedName();
        $conditions = [];
        if ($this->current()->is('WHERE')) {
            ++$this->position;
            while (!$this->isEnd()) {
                $column = $this->readName();
                if ($this->current()->is('.')) {
                    ++$this->position;
                    $column = $this->readName();
                }
                if ($this->current()->is('IS')) {
                    $this->expect('IS');
                    $this->expect('NULL');
                    $conditions[$column] = null;
                } else {
                    $this->expect('=');
                    $conditions[$column] = $this->readLiteral();
                }
                if (!$this->current()->is('AND')) {
                    break;
                }
                ++$this->position;
            }
        }

        return ['table' => $table, 'conditions' => $conditions];
    }

    /**
     * Parse an ALTER TABLE t ADD [CONSTRAINT n] FOREIGN KEY (...) REFERENCES ... | UNIQUE (...) statement.
     *
     * @return array{table: string, uniques: array<int, array{name: string, columns: array<int, string>}>, foreignKeys: array<int, ForeignKeySpec>, checks: array<int, CheckConstraint>}
     */
    public function parseAlterTable(string $sql): array
    {
        $this->start($sql);
        $this->uniques = [];
        $this->foreignKeys = [];
        $this->checks = [];
        $this->expect('ALTER');
        $this->expect('TABLE');
        $table = $this->readQualifiedName();
        if ($this->current()->is('ADD')) {
            ++$this->position;
            $constraintName = '';
            if ($this->current()->is('CONSTRAINT')) {
                ++$this->position;
                $constraintName = $this->readName();
            }
            $this->parseTableConstraint($constraintName);
        }

        return ['table' => $table, 'uniques' => $this->uniques, 'foreignKeys' => $this->foreignKeys, 'checks' => $this->checks];
    }

    /**
     * Add the foreign keys found by parseCreateTable()/parseAlterTable() to the tables of a database.
     * Missing referenced columns are those of the primary key of the referenced table.
     * Foreign keys referencing an unknown table are ignored.
     *
     * @param array<int, ForeignKeySpec> $foreignKeys
     */
    public static function resolveForeignKeys(Database $database, Table $table, array $foreignKeys): void
    {
        foreach ($foreignKeys as $spec) {
            $referenced = $database->getTable($spec['table']);
            if (null === $referenced) {
                continue;
            }
            $referencedColumns = $spec['referencedColumns'];
            if (empty($referencedColumns)) {
                $referencedColumns = array_map(static fn (Column $column): string => $column->getName(), $referenced->getPrimaryKeyColumns());
            }

            try {
                $foreignKey = new ForeignKey($spec['name'], $spec['columns'], $referenced->getName(), $referencedColumns);
                $foreignKey->setOnDelete($spec['onDelete']);
                $foreignKey->setOnUpdate($spec['onUpdate']);
                $table->addForeignKey($foreignKey);
            } catch (InvalidArgumentException $exception) {
                // Columns not matching
            }
        }
    }

    /**
     * UNIQUE (...), FOREIGN KEY (...) REFERENCES ..., CHECK (...).
     */
    private function parseTableConstraint(string $constraintName): void
    {
        if ($this->current()->is('CHECK')) {
            $this->readCheck($constraintName);

            return;
        }
        if ($this->current()->is('UNIQUE')) {
            ++$this->position;
            if ($this->current()->is('KEY', 'INDEX')) {
                ++$this->position;
            }
            if ($this->current()->isName() && !$this->current()->is('(')) {
                // MySQL: UNIQUE KEY name (...)
                ++$this->position;
            }
            $this->uniques[] = ['name' => $constraintName, 'columns' => $this->readNameList()];
        } elseif ($this->current()->is('FOREIGN')) {
            $this->expect('FOREIGN');
            $this->expect('KEY');
            $columns = $this->readNameList();
            if ($this->current()->is('REFERENCES')) {
                $this->readReferences($columns, $constraintName);
            }
        }
    }

    /**
     * REFERENCES table [(columns)] [ON DELETE action] [ON UPDATE action] [MATCH x] [DEFERRABLE...].
     *
     * @param array<int, string> $columns
     */
    private function readReferences(array $columns, string $constraintName): void
    {
        $this->expect('REFERENCES');
        $table = $this->readQualifiedName();
        $referencedColumns = $this->current()->is('(') ? $this->readNameList() : [];
        $actions = ['DELETE' => ForeignKey::NO_ACTION, 'UPDATE' => ForeignKey::NO_ACTION];
        while (!$this->isEnd()) {
            if ($this->current()->is('ON') && $this->peek(1)->is('DELETE', 'UPDATE')) {
                $event = strtoupper($this->peek(1)->value);
                $this->position += 2;
                if ($this->current()->is('SET', 'NO')) {
                    $actions[$event] = strtoupper($this->current()->value . ' ' . $this->peek(1)->value);
                    $this->position += 2;
                } else {
                    $actions[$event] = strtoupper($this->current()->value);
                    ++$this->position;
                }
            } elseif ($this->current()->is('MATCH', 'INITIALLY')) {
                $this->position += 2;
            } elseif ($this->current()->is('DEFERRABLE')) {
                ++$this->position;
            } elseif ($this->current()->is('NOT') && $this->peek(1)->is('DEFERRABLE')) {
                $this->position += 2;
            } else {
                break;
            }
        }
        foreach ($actions as $event => $action) {
            if (!in_array($action, [ForeignKey::NO_ACTION, ForeignKey::CASCADE, ForeignKey::SET_NULL, ForeignKey::SET_DEFAULT, ForeignKey::RESTRICT], true)) {
                $actions[$event] = ForeignKey::NO_ACTION;
            }
        }
        $this->foreignKeys[] = [
            'name' => $constraintName,
            'columns' => $columns,
            'table' => $table,
            'referencedColumns' => $referencedColumns,
            'onDelete' => $actions['DELETE'],
            'onUpdate' => $actions['UPDATE'],
        ];
    }

    /**
     * CHECK (expression).
     */
    private function readCheck(string $constraintName): void
    {
        $this->expect('CHECK');
        $start = $this->position + 1;
        $this->skipParentheses();
        if ($this->position - 1 > $start) {
            // HSQLDB names the unnamed constraints SYS_CT_n
            $name = 1 === preg_match('/^SYS_CT_\d+$/i', $constraintName) ? '' : $constraintName;
            $this->checks[] = new CheckConstraint($this->standard($start, $this->position - 1), $name);
        }
    }

    /**
     * Default value: literal, CURRENT_DATE/TIME/TIMESTAMP or expression.
     */
    private function readDefault(Column $column): mixed
    {
        $token = $this->current();
        $current = ['CURRENT_DATE' => Expression::CURRENT_DATE, 'CURRENT_TIME' => Expression::CURRENT_TIME,
            'CURRENT_TIMESTAMP' => Expression::CURRENT_TIMESTAMP, 'LOCALTIMESTAMP' => Expression::CURRENT_TIMESTAMP,
            'NOW' => Expression::CURRENT_TIMESTAMP, 'TODAY' => Expression::CURRENT_DATE, 'SYSDATE' => Expression::CURRENT_DATE];
        if (Token::WORD === $token->type && isset($current[strtoupper($token->value)])) {
            ++$this->position;
            if ($this->current()->is('(') && $this->peek(1)->is(')')) {
                $this->position += 2;
            }

            return new Expression($current[strtoupper($token->value)]);
        }
        $start = $this->position;
        if ($token->is('(')) {
            $this->skipParentheses();
            $end = $this->position;
            $this->position = $start + 1;
            $value = $this->readLiteral();
            $isLiteral = $this->position === $end - 1 && null !== $value;
            $this->position = $end;
            if ($isLiteral) {
                return $this->checkedLiteral($column, $value);
            }

            return $end - 1 > $start + 1 ? new Expression($this->standard($start + 1, $end - 1)) : null;
        }
        if (Token::WORD === $token->type && $this->peek(1)->is('(')) {
            ++$this->position;
            $this->skipParentheses();

            return new Expression($this->standard($start, $this->position));
        }

        return $this->safeLiteral($column);
    }

    private function checkedLiteral(Column $column, mixed $value): mixed
    {
        try {
            $column->normalizeValue($value);
        } catch (Throwable $exception) {
            return null;
        }

        return $value;
    }

    /**
     * SQL text of the tokens [start, end[ converted to standard SQL.
     */
    private function standard(int $start, int $end): string
    {
        if ($end <= $start || !isset($this->tokens[$start], $this->tokens[$end - 1])) {
            return '';
        }
        $sql = substr($this->sql, $this->tokens[$start]->start, $this->tokens[$end - 1]->end - $this->tokens[$start]->start);
        if ($this->unicodeEscapes) {
            $sql = Tokenizer::decodeUnicodeEscapes($sql);
        }

        return Dialect::convert($sql, $this->dialect, Dialect::STANDARD);
    }

    private function readQualifiedName(): string
    {
        $name = $this->readName();
        if ($this->current()->is('.')) {
            ++$this->position;
            $name = $this->readName();
        }

        return $name;
    }

    private function peek(int $offset): Token
    {
        return $this->tokens[$this->position + $offset] ?? new Token(Token::SYMBOL, '');
    }

    /**
     * Parse an INSERT INTO "table" [(columns)] VALUES (...) statement.
     *
     * @return array{table: string, columns: array<int, string>, values: array<int, mixed>}
     */
    public function parseInsert(string $sql): array
    {
        $this->start($sql);
        $this->expect('INSERT');
        $this->expect('INTO');
        $table = $this->readName();
        if ($this->current()->is('.')) {
            ++$this->position;
            $table = $this->readName();
        }
        $columns = [];
        if ($this->current()->is('(')) {
            $columns = $this->readNameList();
        }
        $this->expect('VALUES');
        $this->expect('(');
        $values = [];
        while (true) {
            $values[] = $this->readLiteral();
            if ($this->current()->is(',')) {
                ++$this->position;

                continue;
            }
            $this->expect(')');

            break;
        }

        return ['table' => $table, 'columns' => $columns, 'values' => $values];
    }

    /**
     * Value of a literal expression (string, number, NULL, boolean, blob).
     * Unsupported expressions (functions...) are returned as null.
     */
    public static function parseLiteral(string $sql, bool $unicodeEscapes = false): mixed
    {
        $parser = new self($unicodeEscapes);
        $parser->start($sql);
        if ($parser->isEnd()) {
            return null;
        }

        return $parser->readLiteral();
    }

    /**
     * @return array{0: Column, 1: string, 2: bool}
     */
    private function parseColumn(): array
    {
        $column = new Column($this->readName());
        $typeWords = [];
        while ($this->current()->isName() && !$this->current()->is(...self::CONSTRAINT_KEYWORDS)) {
            $typeWords[] = $this->current()->value;
            ++$this->position;
        }
        $arguments = [];
        if ($this->current()->is('(')) {
            ++$this->position;
            while (!$this->current()->is(')')) {
                if (Token::NUMBER === $this->current()->type) {
                    $arguments[] = (int) $this->current()->value;
                }
                ++$this->position;
            }
            ++$this->position;
            // "VARCHAR(10) UNSIGNED" and alike
            while ($this->current()->isName() && !$this->current()->is(...self::CONSTRAINT_KEYWORDS)) {
                ++$this->position;
            }
        }
        $declared = implode(' ', $typeWords);
        TypeMapper::applySqlType($column, $declared, $arguments);
        $integerPrimaryKey = false;
        $constraintName = '';
        while (!$this->isEnd() && !$this->current()->is(',', ')')) {
            $token = $this->current();
            if ($token->is('CONSTRAINT')) {
                ++$this->position;
                $constraintName = $this->readName();

                continue;
            } elseif ($token->is('UNIQUE')) {
                ++$this->position;
                $this->uniques[] = ['name' => $constraintName, 'columns' => [$column->getName()]];
            } elseif ($token->is('REFERENCES')) {
                $this->readReferences([$column->getName()], $constraintName);
            } elseif ($token->is('PRIMARY')) {
                $this->expect('PRIMARY');
                $this->expect('KEY');
                $column->setPrimaryKey();
                $descending = false;
                if ($this->current()->is('ASC', 'DESC')) {
                    $descending = $this->current()->is('DESC');
                    ++$this->position;
                }
                $integerPrimaryKey = 'INTEGER' === strtoupper($declared) && !$descending;
            } elseif ($token->is('NOT')) {
                $this->expect('NOT');
                $this->expect('NULL');
                $column->setNullable(false);
            } elseif ($token->is('NULL')) {
                ++$this->position;
            } elseif ($token->is('AUTOINCREMENT', 'AUTO_INCREMENT', 'IDENTITY')) {
                ++$this->position;
                $column->setAutoIncrement();
                if ($this->current()->is('(')) {
                    $this->skipParentheses();
                }
            } elseif ($token->is('GENERATED')) {
                // HSQLDB: GENERATED BY DEFAULT AS IDENTITY(START WITH 0)
                while (!$this->isEnd() && !$this->current()->is('IDENTITY', ',', ')')) {
                    ++$this->position;
                }
                if ($this->current()->is('IDENTITY')) {
                    ++$this->position;
                    $column->setAutoIncrement();
                    if ($this->current()->is('(')) {
                        $this->skipParentheses();
                    }
                }
            } elseif ($token->is('DEFAULT')) {
                ++$this->position;
                $column->setDefaultValue($this->readDefault($column));
            } elseif ($token->is('CHECK')) {
                $this->readCheck($constraintName);
            } elseif ($token->is('COLLATE')) {
                $this->position += 2;
            } elseif ($token->is('(')) {
                $this->skipParentheses();
            } else {
                // CHECK (...), ON CONFLICT ...
                ++$this->position;
            }
            $constraintName = '';
        }
        if ($integerPrimaryKey) {
            $column->setAutoIncrement();
        }

        return [$column, $declared, $integerPrimaryKey];
    }

    private function safeLiteral(Column $column): mixed
    {
        return $this->checkedLiteral($column, $this->readLiteral());
    }

    private function readLiteral(): mixed
    {
        $token = $this->current();
        ++$this->position;
        if ($token->is('-', '+')) {
            $number = $this->current();
            ++$this->position;
            $value = $this->numberValue($number->value);

            return $token->is('-') ? (is_string($value) ? '-' . $value : -$value) : $value;
        }
        switch ($token->type) {
            case Token::STRING:
                return $token->value;
            case Token::NUMBER:
                return $this->numberValue($token->value);
            case Token::BLOB:
                return (string) hex2bin($token->value);
            case Token::WORD:
                if ($token->is('NULL')) {
                    return null;
                }
                if ($token->is('TRUE')) {
                    return true;
                }
                if ($token->is('FALSE')) {
                    return false;
                }
                if ($this->current()->is('(')) {
                    $this->skipParentheses();
                }
                if (Token::STRING === $this->current()->type && $token->is('DATE', 'TIME', 'TIMESTAMP')) {
                    $value = $this->current()->value;
                    ++$this->position;

                    return $value;
                }

                return null;
            default:
                if ($token->is('(')) {
                    --$this->position;
                    $this->skipParentheses();
                }

                return null;
        }
    }

    /**
     * @return float|int|string
     */
    private function numberValue(string $value)
    {
        if (1 === preg_match('/^0x[0-9a-f]+$/i', $value)) {
            return (int) hexdec(substr($value, 2));
        }
        if (1 === preg_match('/^\d+$/', $value)) {
            $int = (int) $value;

            return (string) $int === ltrim($value, '0') || '0' === trim($value, '0') ? $int : $value;
        }
        if (1 === preg_match('/[eE]/', $value)) {
            return (float) $value;
        }

        // Decimal literal: kept as string to not lose precision
        return $value;
    }

    private function start(string $sql): void
    {
        $this->sql = $sql;
        $this->tokens = $this->tokenizer->tokenize($sql);
        $this->position = 0;
    }

    private function isEnd(): bool
    {
        return $this->position >= count($this->tokens) || $this->tokens[$this->position]->is(';');
    }

    private function current(): Token
    {
        return $this->tokens[$this->position] ?? new Token(Token::SYMBOL, '');
    }

    private function expect(string $value): void
    {
        if (!$this->current()->is($value)) {
            throw new ReaderException(sprintf('SQL parse error: "%s" expected, "%s" found', $value, $this->current()->value));
        }
        ++$this->position;
    }

    private function readName(): string
    {
        $token = $this->current();
        if (!$token->isName() && Token::STRING !== $token->type) {
            throw new ReaderException(sprintf('SQL parse error: name expected, "%s" found', $token->value));
        }
        ++$this->position;

        return $token->value;
    }

    /**
     * @return array<int, string>
     */
    private function readNameList(): array
    {
        $names = [];
        $this->expect('(');
        while (!$this->isEnd()) {
            $names[] = $this->readName();
            // ASC/DESC/COLLATE x
            while (!$this->isEnd() && !$this->current()->is(',', ')')) {
                ++$this->position;
            }
            if ($this->current()->is(',')) {
                ++$this->position;

                continue;
            }

            break;
        }
        $this->expect(')');

        return $names;
    }

    private function skipParentheses(): void
    {
        $depth = 0;
        while (!$this->isEnd() || $depth > 0) {
            if ($this->position >= count($this->tokens)) {
                return;
            }
            $token = $this->current();
            ++$this->position;
            if ($token->is('(')) {
                ++$depth;
            } elseif ($token->is(')')) {
                --$depth;
                if (0 === $depth) {
                    return;
                }
            }
        }
    }

    private function skipUntilSeparator(): void
    {
        while (!$this->isEnd() && !$this->current()->is(',', ')')) {
            if ($this->current()->is('(')) {
                $this->skipParentheses();
            } else {
                ++$this->position;
            }
        }
    }
}
