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

use DateTimeInterface;
use PhpOffice\PhpDatabase\Attachment;
use PhpOffice\PhpDatabase\Column;
use PhpOffice\PhpDatabase\ColumnType;

/**
 * Map the SQL types declared in the files (SQLite, HSQLDB) to the logical column types, and back.
 */
class TypeMapper
{
    /**
     * Apply a declared SQL type (ex: "VARCHAR", [100]) to a column.
     *
     * @param array<int, int> $arguments
     */
    public static function applySqlType(Column $column, string $declared, array $arguments = []): void
    {
        $type = strtoupper(trim((string) preg_replace('/\s+/', ' ', $declared)));
        $length = $arguments[0] ?? null;
        switch ($type) {
            case 'BOOL':
            case 'BOOLEAN':
            case 'BIT':
                $column->setType(ColumnType::BOOLEAN);

                return;
            case 'TINYINT':
                $column->setType(ColumnType::TINYINT);

                return;
            case 'SMALLINT':
            case 'INT2':
                $column->setType(ColumnType::SMALLINT);

                return;
            case 'BIGINT':
            case 'INT8':
            case 'UNSIGNED BIG INT':
                $column->setType(ColumnType::BIGINT);

                return;
            case 'SINGLE':
                $column->setType(ColumnType::FLOAT);

                return;
            case 'DECIMAL':
            case 'NUMERIC':
            case 'NUMBER':
            case 'MONEY':
            case 'CURRENCY':
                $column->setType(ColumnType::DECIMAL);
                if ('MONEY' === $type || 'CURRENCY' === $type) {
                    $column->setPrecision(19, 4);
                } elseif (null !== $length) {
                    $column->setPrecision($length, $arguments[1] ?? 0);
                }

                return;
            case 'LONGVARCHAR':
            case 'CLOB':
            case 'MEMO':
            case 'LONGTEXT':
            case 'MEDIUMTEXT':
            case 'NTEXT':
                $column->setType(ColumnType::LONGTEXT);

                return;
            case 'GUID':
            case 'UUID':
            case 'UNIQUEIDENTIFIER':
                $column->setType(ColumnType::GUID);

                return;
            case 'HYPERLINK':
            case 'HYPERLINK TEXT':
                $column->setType(ColumnType::HYPERLINK);

                return;
            case 'DATE':
                $column->setType(ColumnType::DATE);

                return;
            case 'TIME':
                $column->setType(ColumnType::TIME);

                return;
            case 'DATETIME':
            case 'TIMESTAMP':
                $column->setType(ColumnType::DATETIME);

                return;
            case 'BLOB':
            case 'BINARY':
            case 'VARBINARY':
            case 'LONGVARBINARY':
            case 'BYTEA':
            case 'IMAGE':
            case 'OTHER':
            case 'OBJECT':
                $column->setType(ColumnType::BLOB);
                $column->setLength($length);

                return;
        }
        // Affinity rules of SQLite (https://www.sqlite.org/datatype3.html#determination_of_column_affinity)
        if (str_contains($type, 'INT')) {
            $column->setType(ColumnType::INTEGER);
        } elseif (str_contains($type, 'CHAR') || str_contains($type, 'CLOB') || str_contains($type, 'TEXT')) {
            $column->setType(ColumnType::TEXT);
            $column->setLength($length);
        } elseif (str_contains($type, 'BLOB')) {
            $column->setType(ColumnType::BLOB);
        } elseif (str_contains($type, 'REAL') || str_contains($type, 'FLOA') || str_contains($type, 'DOUB')) {
            $column->setType(ColumnType::DOUBLE);
        } else {
            $column->setType(ColumnType::TEXT);
            $column->setLength($length);
        }
    }

    /**
     * SQL type of a column (SQL standard names, understood by SQLite and HSQLDB).
     */
    public static function toSqlType(Column $column, bool $hsqldb = false): string
    {
        if (self::isComplex($column)) {
            return $hsqldb ? 'LONGVARCHAR' : 'TEXT';
        }
        switch ($column->getType()) {
            case ColumnType::BOOLEAN:
                return 'BOOLEAN';
            case ColumnType::TINYINT:
                return 'TINYINT';
            case ColumnType::SMALLINT:
                return 'SMALLINT';
            case ColumnType::INTEGER:
                return 'INTEGER';
            case ColumnType::BIGINT:
                return 'BIGINT';
            case ColumnType::FLOAT:
                return 'REAL';
            case ColumnType::DOUBLE:
                return 'DOUBLE';
            case ColumnType::DECIMAL:
                return sprintf('DECIMAL(%d,%d)', $column->getPrecision(), $column->getScale());
            case ColumnType::TEXT:
                if (null === $column->getLength()) {
                    return $hsqldb ? 'VARCHAR' : 'TEXT';
                }

                return sprintf('VARCHAR(%d)', $column->getLength());
            case ColumnType::LONGTEXT:
                return $hsqldb ? 'LONGVARCHAR' : 'TEXT';
            case ColumnType::DATE:
                return 'DATE';
            case ColumnType::TIME:
                return 'TIME';
            case ColumnType::DATETIME:
                return $hsqldb ? 'TIMESTAMP' : 'DATETIME';
            case ColumnType::GUID:
                return $hsqldb ? 'CHAR(38)' : 'GUID';
            case ColumnType::HYPERLINK:
                return $hsqldb ? 'LONGVARCHAR' : 'HYPERLINK TEXT';
            default:
                return $hsqldb ? 'LONGVARBINARY' : 'BLOB';
        }
    }

    /**
     * Attachment and multi-valued columns: written as text by the SQLite and ODB writers.
     */
    public static function isComplex(Column $column): bool
    {
        return ColumnType::ATTACHMENT === $column->getType() || $column->isMultiValued();
    }

    /**
     * Text of the value of an attachment (file names) or multi-valued column (values), separated by "; ".
     */
    public static function flatten(Column $column, mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }
        $items = [];
        foreach (is_array($value) ? $value : [$value] as $item) {
            if ($item instanceof Attachment) {
                $items[] = $item->getName();
            } elseif ($item instanceof DateTimeInterface) {
                $items[] = $item->format(ColumnType::DATE === $column->getType() ? 'Y-m-d' : 'Y-m-d H:i:s');
            } elseif (is_bool($item)) {
                $items[] = $item ? '1' : '0';
            } elseif (is_scalar($item)) {
                $items[] = (string) $item;
            }
        }

        return implode('; ', $items);
    }
}
