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
use PhpOffice\PhpDatabase\Attachment;
use PhpOffice\PhpDatabase\CheckConstraint;
use PhpOffice\PhpDatabase\Column;
use PhpOffice\PhpDatabase\ColumnType;
use PhpOffice\PhpDatabase\Database;
use PhpOffice\PhpDatabase\Exception\ReaderException;
use PhpOffice\PhpDatabase\Expression;
use PhpOffice\PhpDatabase\ForeignKey;
use PhpOffice\PhpDatabase\Index;
use PhpOffice\PhpDatabase\Shared\Access\AccessFile;
use PhpOffice\PhpDatabase\Shared\Access\ColumnDefinition;
use PhpOffice\PhpDatabase\Shared\Access\JetFormat;
use PhpOffice\PhpDatabase\Shared\Access\QueryFormat;
use PhpOffice\PhpDatabase\Shared\Access\ValueCodec;
use PhpOffice\PhpDatabase\Shared\Sql\Dialect;
use PhpOffice\PhpDatabase\Table;
use PhpOffice\PhpDatabase\View;
use Throwable;

/**
 * Reader for Microsoft Access databases: .accdb (Access 2007+) and .mdb (Access 2000-2003).
 */
class Accdb extends AbstractReader
{
    public function canRead(string $filename): bool
    {
        $signature = $this->readSignature($filename, 20);

        return AccessFile::SIGNATURE_ACE === $signature || AccessFile::SIGNATURE_JET === $signature;
    }

    public function load(string $filename, int $flags = 0): Database
    {
        $this->flags = $flags;
        $this->assertReadable($filename);
        $data = file_get_contents($filename);
        if (false === $data) {
            throw new ReaderException(sprintf('Unable to read the file "%s"', $filename));
        }
        $file = new AccessFile($data);
        $database = new Database();
        $summary = $file->getSummaryProperties();
        $title = trim((string) $summary->get('', 'Title'));
        $database->getDocumentProperties()
            ->setTitle('' !== $title ? $title : pathinfo($filename, PATHINFO_FILENAME))
            ->setCreated($file->getCreationDate());
        if ('' !== trim((string) $summary->get('', 'Author'))) {
            $database->getDocumentProperties()->setCreator(trim((string) $summary->get('', 'Author')));
        }
        if ('' !== trim((string) $summary->get('', 'Comments'))) {
            $database->getDocumentProperties()->setDescription(trim((string) $summary->get('', 'Comments')));
        }
        $complexColumns = $file->getComplexColumns();
        foreach ($file->getUserTables() as $name => $page) {
            $definition = $file->readTableDefinition($page, $name);
            $properties = $file->getTableProperties($name);
            $table = new Table($name);
            $table->setDescription((string) $properties->get('', 'Description'));
            $columns = [];
            $byNumber = [];
            // Values of the complex columns: column name => [complex value id => values]
            $complexValues = [];
            foreach ($definition->columns as $columnDefinition) {
                // Calculated column: the type of the values is the "ResultType" property
                $resultType = $columnDefinition->calculated ? $properties->get($columnDefinition->name, 'ResultType') : null;
                if (is_int($resultType) && $resultType > 0) {
                    $columnDefinition->type = $resultType;
                }
                $complex = JetFormat::TYPE_COMPLEX === $columnDefinition->type ? ($complexColumns[$columnDefinition->complexId] ?? null) : null;
                $column = $this->createColumn($columnDefinition, null === $complex ? null : $complex['type']);
                if (null === $column) {
                    continue;
                }
                if (null !== $complex && !$this->skipRows()) {
                    $complexValues[$columnDefinition->name] = $this->readComplexValues($file, $complex['flatTable'], $columnDefinition->name, $column);
                }
                if (in_array($columnDefinition->number, $definition->primaryKey, true)) {
                    $column->setPrimaryKey();
                }
                if (true === $properties->get($column->getName(), 'Required')) {
                    $column->setNullable(false);
                }
                $default = $properties->get($column->getName(), 'DefaultValue');
                if (is_string($default) && '' !== trim($default) && !$column->isAutoIncrement()) {
                    try {
                        $column->setDefaultValue(self::parseDefaultExpression($default) ?? self::defaultExpression($default));
                    } catch (Throwable $exception) {
                        // Expression not convertible to a value of the column
                    }
                }
                // Validation rule of the column: expression without its operand ("> 0", "Is Not Null"...)
                $rule = $properties->get($column->getName(), 'ValidationRule');
                if (is_string($rule) && '' !== trim($rule)) {
                    $table->addCheck(new CheckConstraint(self::convertExpression('[' . $column->getName() . '] ' . self::columnRule($rule))));
                }
                $column->setDescription((string) $properties->get($column->getName(), 'Description'));
                $table->addColumn($column);
                $columns[$columnDefinition->name] = $column;
                $byNumber[$columnDefinition->number] = $column->getName();
            }
            $rule = $properties->get('', 'ValidationRule');
            if (is_string($rule) && '' !== trim($rule)) {
                $table->createCheck(self::convertExpression($rule));
            }
            // Indexes (the primary key is defined on the columns, the foreign key indexes by the relationships)
            foreach ($definition->logicalIndexes as $logical) {
                $real = $definition->realIndexes[$logical['realIndex']] ?? null;
                if (null === $real || 0 !== $logical['type'] || null !== $table->getIndex($logical['name'])) {
                    continue;
                }
                $names = [];
                $descending = [];
                foreach ($real['columns'] as $number => $ascending) {
                    $names[] = $byNumber[$number] ?? null;
                    $descending[] = !$ascending;
                }
                if (in_array(null, $names, true) || empty($names)) {
                    continue;
                }
                $index = new Index($logical['name'], $names, 0 !== ($real['flags'] & 0x01));
                foreach ($descending as $position => $isDescending) {
                    if ($isDescending) {
                        $index->setDescending($position);
                    }
                }
                $table->addIndex($index);
            }
            $database->addTable($table);
            if ($this->skipRows()) {
                continue;
            }
            foreach ($file->readRows($definition, array_keys($columns)) as $values) {
                $row = [];
                foreach ($columns as $name => $column) {
                    if (isset($complexValues[$name])) {
                        $row[$name] = null === ($values[$name] ?? null) ? [] : ($complexValues[$name][(int) $values[$name]] ?? []);

                        continue;
                    }

                    try {
                        $row[$name] = $column->normalizeValue($values[$name] ?? null);
                    } catch (Throwable $exception) {
                        $row[$name] = null;
                    }
                }
                $table->addRow($row);
            }
        }

        foreach ($file->getRelationships() as $name => $relationship) {
            $table = $database->getTable($relationship['table']);
            $referenced = $database->getTable($relationship['referencedTable']);
            if (null === $table || null === $referenced) {
                continue;
            }

            try {
                $foreignKey = new ForeignKey($name, $relationship['columns'], $referenced->getName(), $relationship['referencedColumns']);
                if (0 !== ($relationship['flags'] & JetFormat::RELATIONSHIP_CASCADE_UPDATES)) {
                    $foreignKey->setOnUpdate(ForeignKey::CASCADE);
                }
                if (0 !== ($relationship['flags'] & JetFormat::RELATIONSHIP_CASCADE_DELETES)) {
                    $foreignKey->setOnDelete(ForeignKey::CASCADE);
                } elseif (0 !== ($relationship['flags'] & JetFormat::RELATIONSHIP_CASCADE_NULL)) {
                    $foreignKey->setOnDelete(ForeignKey::SET_NULL);
                }
                $table->addForeignKey($foreignKey);
            } catch (InvalidArgumentException $exception) {
                // Columns not found
            }
        }

        // Select and union queries (the action queries, crosstab, data definition and pass-through queries are skipped)
        foreach ($file->getQueries() as $name => $query) {
            $sql = QueryFormat::toSql($query['flags'], $query['rows']);
            if (null === $sql || str_starts_with($sql, 'PARAMETERS ') || $database->hasTable($name) || null !== $database->getView($name)) {
                continue;
            }
            $view = new View($name, Dialect::convert($sql, Dialect::ACCESS, Dialect::STANDARD));
            $view->setDescription((string) $query['properties']->get('', 'Description'));
            $database->addView($view);
        }

        return $database;
    }

    /**
     * Expression of Access (validation rule, default value) in standard SQL.
     */
    public static function convertExpression(string $expression): string
    {
        $expression = trim($expression);
        if (str_starts_with($expression, '=')) {
            $expression = trim(substr($expression, 1));
        }

        return trim(Dialect::convert($expression, Dialect::ACCESS, Dialect::STANDARD));
    }

    /**
     * Default value which is not a literal (=Date(), =Now()...).
     */
    private static function defaultExpression(string $expression): ?Expression
    {
        $sql = self::convertExpression($expression);
        if ('' === $sql || 0 === strcasecmp($sql, 'NULL')) {
            return null;
        }

        return new Expression($sql);
    }

    /**
     * Validation rule of a column: the column is the implicit left operand, unless the rule starts with
     * an operator ("> 0", "Like ...", "In (...)", "Between ...", "Is ...", "Not ..."); else "= rule".
     */
    private static function columnRule(string $rule): string
    {
        $rule = trim($rule);
        if (1 === preg_match('/^(<>|<=|>=|<|>|=|(Not\s+)?(Like|In|Between)\b|Is\b)/i', $rule)) {
            return $rule;
        }
        if (1 === preg_match('/^Not\b/i', $rule)) {
            // "Not 0": not equal
            return '<> ' . trim(substr($rule, 3));
        }

        return '= ' . $rule;
    }

    /**
     * Values of a complex column, from its flat table: complex value id => values (attachments, or the
     * values of a multi-valued field).
     *
     * @return array<int, array<int, mixed>>
     */
    private function readComplexValues(AccessFile $file, int $flatTable, string $columnName, Column $column): array
    {
        $definition = $file->readTableDefinition($flatTable);
        $values = [];
        foreach ($file->readRows($definition) as $row) {
            $id = $row['_' . $columnName] ?? null;
            if (null === $id) {
                continue;
            }
            if (ColumnType::ATTACHMENT === $column->getType()) {
                $content = ValueCodec::decodeAttachment((string) ($row['FileData'] ?? ''));
                $modified = $row['FileTimeStamp'] ?? null;
                $values[(int) $id][] = new Attachment(
                    (string) ($row['FileName'] ?? ''),
                    $content['data'],
                    (string) ($row['FileType'] ?? $content['type']),
                    $modified instanceof DateTimeImmutable ? $modified : null
                );

                continue;
            }

            try {
                $values[(int) $id][] = $column->normalizeValue([$row['Value'] ?? null])[0] ?? null;
            } catch (Throwable $exception) {
                $values[(int) $id][] = null;
            }
        }

        return $values;
    }

    /**
     * Value of a default value expression of Access: "text", 'text', numbers, Yes/No/True/False, #date#.
     * Other expressions (functions...) are returned as null.
     */
    public static function parseDefaultExpression(string $expression): mixed
    {
        $expression = trim($expression);
        if (str_starts_with($expression, '=')) {
            $expression = trim(substr($expression, 1));
        }
        if (1 === preg_match('/^"((?:[^"]|"")*)"$/s', $expression, $match)) {
            return str_replace('""', '"', $match[1]);
        }
        if (1 === preg_match("/^'((?:[^']|'')*)'$/s", $expression, $match)) {
            return str_replace("''", "'", $match[1]);
        }
        if (1 === preg_match('/^[-+]?\d+$/', $expression)) {
            return (int) $expression;
        }
        if (1 === preg_match('/^[-+]?(\d+\.\d*|\.\d+)$/', $expression)) {
            return $expression;
        }
        if (1 === preg_match('/^#([^#]+)#$/', $expression, $match)) {
            return new DateTimeImmutable($match[1]);
        }
        switch (strtolower($expression)) {
            case 'yes':
            case 'true':
            case 'on':
                return true;
            case 'no':
            case 'false':
            case 'off':
                return false;
            default:
                return null;
        }
    }

    /**
     * @param null|string $complexType name of the MSysComplexType_* table of a complex column
     */
    private function createColumn(ColumnDefinition $definition, ?string $complexType = null): ?Column
    {
        $column = new Column($definition->name);
        if (JetFormat::TYPE_COMPLEX === $definition->type) {
            return null === $complexType ? null : self::createComplexColumn($column, $complexType);
        }
        switch ($definition->type) {
            case JetFormat::TYPE_BOOLEAN:
                $column->setType(ColumnType::BOOLEAN)->setNullable(false);

                break;
            case JetFormat::TYPE_BYTE:
                $column->setType(ColumnType::TINYINT);

                break;
            case JetFormat::TYPE_INT:
                $column->setType(ColumnType::SMALLINT);

                break;
            case JetFormat::TYPE_LONG:
                $column->setType(ColumnType::INTEGER);

                break;
            case JetFormat::TYPE_BIG_INT:
                $column->setType(ColumnType::BIGINT);

                break;
            case JetFormat::TYPE_MONEY:
                $column->setType(ColumnType::DECIMAL)->setPrecision(19, 4);

                break;
            case JetFormat::TYPE_FLOAT:
                $column->setType(ColumnType::FLOAT);

                break;
            case JetFormat::TYPE_DOUBLE:
                $column->setType(ColumnType::DOUBLE);

                break;
            case JetFormat::TYPE_DATETIME:
                $column->setType(ColumnType::DATETIME);

                break;
            case JetFormat::TYPE_EXT_DATETIME:
                $column->setType(ColumnType::DATETIME)->setFractionalSeconds(7);

                break;
            case JetFormat::TYPE_NUMERIC:
                // Calculated column: the scale is given by each value (up to 28 digits)
                $column->setType(ColumnType::DECIMAL)->setPrecision($definition->calculated ? 28 : $definition->precision, $definition->calculated ? 18 : $definition->scale);

                break;
            case JetFormat::TYPE_TEXT:
                $column->setType(ColumnType::TEXT)->setLength(intdiv($definition->length, 2));

                break;
            case JetFormat::TYPE_GUID:
                $column->setType(ColumnType::GUID);

                break;
            case JetFormat::TYPE_MEMO:
                $column->setType(0 !== ($definition->flags & JetFormat::COLUMN_FLAG_HYPERLINK) ? ColumnType::HYPERLINK : ColumnType::LONGTEXT);

                break;
            case JetFormat::TYPE_BINARY:
            case JetFormat::TYPE_OLE:
                $column->setType(ColumnType::BLOB);

                break;
            default:
                // Unknown types
                return null;
        }
        if ($definition->isAutoNumber() && JetFormat::TYPE_GUID !== $definition->type) {
            $column->setAutoIncrement();
        }

        return $column;
    }

    /**
     * Attachments and multi-valued fields; the version history of the memo columns ("append only") is skipped.
     */
    private static function createComplexColumn(Column $column, string $complexType): ?Column
    {
        $types = [
            'MSysComplexType_Attachment' => ColumnType::ATTACHMENT,
            'MSysComplexType_UnsignedByte' => ColumnType::TINYINT,
            'MSysComplexType_Short' => ColumnType::SMALLINT,
            'MSysComplexType_Long' => ColumnType::INTEGER,
            'MSysComplexType_IEEESingle' => ColumnType::FLOAT,
            'MSysComplexType_IEEEDouble' => ColumnType::DOUBLE,
            'MSysComplexType_GUID' => ColumnType::GUID,
            'MSysComplexType_Decimal' => ColumnType::DECIMAL,
            'MSysComplexType_Text' => ColumnType::TEXT,
        ];
        if (!isset($types[$complexType])) {
            return null;
        }
        $column->setType($types[$complexType]);
        if (ColumnType::TEXT === $types[$complexType]) {
            $column->setLength(255);
        }
        if (ColumnType::ATTACHMENT !== $types[$complexType]) {
            $column->setMultiValued();
        }

        return $column;
    }
}
