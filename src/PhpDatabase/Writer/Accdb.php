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

namespace PhpOffice\PhpDatabase\Writer;

use DateTimeImmutable;
use PhpOffice\PhpDatabase\Column;
use PhpOffice\PhpDatabase\ColumnType;
use PhpOffice\PhpDatabase\Exception\WriterException;
use PhpOffice\PhpDatabase\Expression;
use PhpOffice\PhpDatabase\ForeignKey;
use PhpOffice\PhpDatabase\Shared\Access\ColumnDefinition;
use PhpOffice\PhpDatabase\Shared\Access\JetFormat;
use PhpOffice\PhpDatabase\Shared\Access\PropertyMap;
use PhpOffice\PhpDatabase\Shared\Access\QueryFormat;
use PhpOffice\PhpDatabase\Shared\Sql\Dialect;
use PhpOffice\PhpDatabase\Shared\Sql\TypeMapper;
use PhpOffice\PhpDatabase\Table;
use PhpOffice\PhpDatabase\Writer\Accdb\AccessBuilder;

/**
 * Writer for Microsoft Access databases: .accdb (Access 2007, default) or .mdb (Access 2000).
 *
 * The database starts from an empty database created by Access. Primary keys, indexes and foreign keys
 * (relationships) are written, with the properties of the tables and columns (description, required,
 * default value, validation rule) and the views (select and union queries).
 *
 * Not written (see getWarnings()): triggers, expression and partial indexes, views which can't be stored
 * as Access queries; attachments and multi-valued fields are written as text.
 */
class Accdb extends AbstractWriter
{
    public const FORMAT_MDB_2000 = JetFormat::VERSION_JET4;
    public const FORMAT_ACCDB_2007 = JetFormat::VERSION_ACE12;
    public const FORMAT_ACCDB_2010 = JetFormat::VERSION_ACE14;
    public const FORMAT_ACCDB_2016 = JetFormat::VERSION_ACE16;
    /** Access 2019: "Date/Time Extended" columns (Column::setFractionalSeconds() > 3) */
    public const FORMAT_ACCDB_2019 = JetFormat::VERSION_ACE17;

    /**
     * Format of the file (null: chosen from the extension, .mdb or .accdb).
     *
     * @var null|int
     */
    protected $format;

    public function getFormat(): ?int
    {
        return $this->format;
    }

    public function setFormat(?int $format): self
    {
        $this->format = $format;

        return $this;
    }

    public function save(string $filename): void
    {
        $format = $this->format ?? ('mdb' === strtolower(pathinfo($filename, PATHINFO_EXTENSION)) ? self::FORMAT_MDB_2000 : self::FORMAT_ACCDB_2007);
        $this->writeFile($filename, $this->getContent($format));
    }

    public function getContent(?int $format = null): string
    {
        $this->warnings = [];
        $database = $this->getDatabaseOrFail();
        $builder = new AccessBuilder($format ?? $this->format ?? self::FORMAT_ACCDB_2007);
        foreach ($database->getTables() as $table) {
            $this->addTable($builder, $table);
            foreach ($table->getForeignKeys() as $foreignKey) {
                $flags = 0;
                if (ForeignKey::CASCADE === $foreignKey->getOnUpdate()) {
                    $flags |= JetFormat::RELATIONSHIP_CASCADE_UPDATES;
                }
                if (ForeignKey::CASCADE === $foreignKey->getOnDelete()) {
                    $flags |= JetFormat::RELATIONSHIP_CASCADE_DELETES;
                } elseif (ForeignKey::SET_NULL === $foreignKey->getOnDelete()) {
                    $flags |= JetFormat::RELATIONSHIP_CASCADE_NULL;
                }
                $builder->addRelationship($foreignKey->getName(), $table->getName(), $foreignKey->getColumns(), $foreignKey->getReferencedTable(), $foreignKey->getReferencedColumns(), $flags);
            }
        }
        foreach ($database->getViews() as $view) {
            $query = QueryFormat::fromSql($view->getSql());
            if (null === $query) {
                $this->addWarning(sprintf('View "%s" not written: unsupported SQL', $view->getName()));

                continue;
            }
            $viewProperties = null;
            if ('' !== $view->getDescription()) {
                $viewProperties = new PropertyMap();
                $viewProperties->set('', 'Description', JetFormat::TYPE_MEMO, $view->getDescription());
            }
            $builder->addQuery($view->getName(), $query['flags'], $query['rows'], $viewProperties);
        }
        foreach ($database->getTriggers() as $trigger) {
            $this->addWarning(sprintf('Trigger "%s" not written: Access has no triggers', $trigger->getName()));
        }
        $properties = $database->getDocumentProperties();
        foreach (['Title' => $properties->getTitle(), 'Author' => $properties->getCreator(), 'Comments' => $properties->getDescription()] as $name => $value) {
            if ('' !== $value) {
                $builder->setSummaryProperty($name, $value);
            }
        }

        return $builder->build($database->getDocumentProperties()->getCreated());
    }

    private function addTable(AccessBuilder $builder, Table $table): void
    {
        if (0 === $table->getColumnCount()) {
            throw new WriterException(sprintf('The table "%s" has no column', $table->getName()));
        }
        if ($table->getColumnCount() > 255) {
            throw new WriterException(sprintf('The table "%s" has more than 255 columns', $table->getName()));
        }
        $definitions = [];
        $primaryKey = [];
        $lastAutoNumber = 0;
        $properties = new PropertyMap();
        if ('' !== $table->getDescription()) {
            $properties->set('', 'Description', JetFormat::TYPE_MEMO, $table->getDescription());
        }
        foreach ($table->getColumns() as $position => $column) {
            $definition = $this->createDefinition($builder, $table, $column);
            $definitions[] = $definition;
            if ($column->isPrimaryKey()) {
                $primaryKey[$position] = true;
            }
            if ($definition->isAutoNumber()) {
                $lastAutoNumber = $table->getNextAutoIncrement($column) - 1;
            }
            if (!$column->isNullable() && !$column->isPrimaryKey() && JetFormat::TYPE_BOOLEAN !== $definition->type) {
                $properties->set($column->getName(), 'Required', JetFormat::TYPE_BOOLEAN, true, true);
            }
            if (null !== $column->getDefaultValue() && !$definition->isAutoNumber()) {
                $default = $column->getDefaultValue();
                if ($default instanceof Expression) {
                    $default = Dialect::convert($default->getSql(), Dialect::STANDARD, Dialect::ACCESS);
                } else {
                    $default = self::defaultExpression(TypeMapper::isComplex($column) ? TypeMapper::flatten($column, $default) : $default);
                }
                $properties->set($column->getName(), 'DefaultValue', JetFormat::TYPE_MEMO, $default, true);
            }
            if ('' !== $column->getDescription()) {
                $properties->set($column->getName(), 'Description', JetFormat::TYPE_MEMO, $column->getDescription());
            }
        }
        // Check constraints: validation rule of the table
        $rules = array_map(static fn ($check): string => trim(Dialect::convert($check->getExpression(), Dialect::STANDARD, Dialect::ACCESS)), $table->getChecks());
        if (!empty($rules)) {
            $properties->set('', 'ValidationRule', JetFormat::TYPE_MEMO, 1 === count($rules) ? $rules[0] : '(' . implode(') And (', $rules) . ')');
        }
        $indexes = [];
        if (!empty($primaryKey)) {
            $indexes[] = ['name' => 'PrimaryKey', 'columns' => $primaryKey, 'primaryKey' => true];
        }
        foreach ($table->getIndexes() as $index) {
            if ($index->hasExpressions() || null !== $index->getWhere()) {
                $this->addWarning(sprintf('Index "%s" not written: Access has no expression or partial indexes', $index->getName()));

                continue;
            }
            $columns = [];
            foreach ($index->getColumns() as $position => $name) {
                $column = $table->getColumn($name);
                if (null === $column) {
                    throw new WriterException(sprintf('Unknown column "%s" in the index "%s"', $name, $index->getName()));
                }
                if (TypeMapper::isComplex($column)) {
                    continue 2;
                }
                $columns[array_search($column, $table->getColumns(), true)] = !$index->isDescending($position);
            }
            $indexes[] = ['name' => $index->getName(), 'columns' => $columns, 'unique' => $index->isUnique()];
        }
        $rows = [];
        foreach ($table->getRows() as $row) {
            $values = [];
            foreach ($table->getColumns() as $position => $column) {
                $values[] = $this->convertValue($column, $definitions[$position], $row[$column->getName()]);
            }
            $rows[] = $values;
        }
        $builder->addTable($table->getName(), $definitions, $rows, $indexes, $lastAutoNumber, $properties);
    }

    /**
     * Default value as an Access expression.
     */
    public static function defaultExpression(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if ($value instanceof DateTimeImmutable) {
            return '#' . $value->format('00:00:00' === $value->format('H:i:s') ? 'Y-m-d' : 'Y-m-d H:i:s') . '#';
        }
        $string = (string) $value;
        if (1 === preg_match('/^-?\d+(\.\d+)?$/', $string)) {
            return $string;
        }

        return '"' . str_replace('"', '""', $string) . '"';
    }

    private function createDefinition(AccessBuilder $builder, Table $table, Column $column): ColumnDefinition
    {
        $definition = new ColumnDefinition();
        $definition->name = $column->getName();
        if (TypeMapper::isComplex($column)) {
            $this->addWarning(sprintf('Column "%s.%s": %s written as text', $table->getName(), $column->getName(), ColumnType::ATTACHMENT === $column->getType() ? 'attachments' : 'multi-valued field'));
            $definition->type = JetFormat::TYPE_MEMO;
            $definition->flags |= 0x10;

            return $definition;
        }
        switch ($column->getType()) {
            case ColumnType::BOOLEAN:
                $definition->type = JetFormat::TYPE_BOOLEAN;

                break;
            case ColumnType::TINYINT:
                $definition->type = JetFormat::TYPE_BYTE;
                foreach ($table->getRows() as $row) {
                    $value = $row[$column->getName()];
                    if (is_int($value) && ($value < 0 || $value > 255)) {
                        $definition->type = JetFormat::TYPE_INT;

                        break;
                    }
                }

                break;
            case ColumnType::SMALLINT:
                $definition->type = JetFormat::TYPE_INT;

                break;
            case ColumnType::INTEGER:
                $definition->type = JetFormat::TYPE_LONG;

                break;
            case ColumnType::BIGINT:
                if ($builder->getVersion() >= JetFormat::VERSION_ACE16) {
                    $definition->type = JetFormat::TYPE_BIG_INT;
                } else {
                    $definition->type = JetFormat::TYPE_NUMERIC;
                    $definition->precision = 19;
                }

                break;
            case ColumnType::FLOAT:
                $definition->type = JetFormat::TYPE_FLOAT;

                break;
            case ColumnType::DOUBLE:
                $definition->type = JetFormat::TYPE_DOUBLE;

                break;
            case ColumnType::DECIMAL:
                if (19 === $column->getPrecision() && 4 === $column->getScale()) {
                    $definition->type = JetFormat::TYPE_MONEY;
                } else {
                    $definition->type = JetFormat::TYPE_NUMERIC;
                    $definition->precision = max(1, min(28, $column->getPrecision()));
                    $definition->scale = max(0, min($definition->precision, $column->getScale()));
                }

                break;
            case ColumnType::TEXT:
                $length = $column->getLength();
                if (null === $length) {
                    $length = 1;
                    foreach ($table->getRows() as $row) {
                        if (is_string($row[$column->getName()])) {
                            $length = max($length, mb_strlen($row[$column->getName()], 'UTF-8'));
                        }
                    }
                    $length = $length <= 255 ? 255 : 0;
                }
                if ($length > 0 && $length <= 255) {
                    $definition->type = JetFormat::TYPE_TEXT;
                    $definition->length = $length * 2;
                } else {
                    $definition->type = JetFormat::TYPE_MEMO;
                }

                break;
            case ColumnType::LONGTEXT:
                $definition->type = JetFormat::TYPE_MEMO;

                break;
            case ColumnType::HYPERLINK:
                $definition->type = JetFormat::TYPE_MEMO;
                $definition->flags |= JetFormat::COLUMN_FLAG_HYPERLINK;

                break;
            case ColumnType::GUID:
                $definition->type = JetFormat::TYPE_GUID;
                $definition->length = 16;

                break;
            case ColumnType::DATETIME:
                if (($column->getFractionalSeconds() ?? 0) > 3) {
                    if ($builder->getVersion() >= self::FORMAT_ACCDB_2019) {
                        $definition->type = JetFormat::TYPE_EXT_DATETIME;
                        $definition->length = 42;

                        break;
                    }
                    $this->addWarning(sprintf('Column "%s.%s": fractions of seconds lost (extended date/time needs FORMAT_ACCDB_2019)', $table->getName(), $column->getName()));
                }
                $definition->type = JetFormat::TYPE_DATETIME;

                break;
            case ColumnType::DATE:
            case ColumnType::TIME:
                $definition->type = JetFormat::TYPE_DATETIME;

                break;
            default:
                $definition->type = JetFormat::TYPE_OLE;
        }
        if ($column->isAutoIncrement() && JetFormat::TYPE_LONG === $definition->type) {
            $definition->flags |= JetFormat::COLUMN_FLAG_AUTO_NUMBER;
        }
        $definition->flags |= 0x10;

        return $definition;
    }

    private function convertValue(Column $column, ColumnDefinition $definition, mixed $value): mixed
    {
        if (null === $value) {
            return JetFormat::TYPE_BOOLEAN === $definition->type ? false : null;
        }
        if (TypeMapper::isComplex($column)) {
            return TypeMapper::flatten($column, $value);
        }
        if ($value instanceof DateTimeImmutable) {
            if (ColumnType::DATE === $column->getType()) {
                return $value->setTime(0, 0);
            }
            if (ColumnType::TIME === $column->getType()) {
                return $value->setDate(1899, 12, 30);
            }
        }

        return $value;
    }
}
