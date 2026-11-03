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

namespace PhpOffice\PhpDatabase\Writer\Accdb;

use DateTimeImmutable;
use PhpOffice\PhpDatabase\Exception\WriterException;
use PhpOffice\PhpDatabase\Shared\Access\AccessFile;
use PhpOffice\PhpDatabase\Shared\Access\ColumnDefinition;
use PhpOffice\PhpDatabase\Shared\Access\JetFormat;
use PhpOffice\PhpDatabase\Shared\Access\PropertyMap;
use PhpOffice\PhpDatabase\Shared\Access\TableDefinition;
use PhpOffice\PhpDatabase\Shared\Access\ValueCodec;

/**
 * Builder of Jet 4 (.mdb, Access 2000) and ACE (.accdb, Access 2007+) databases.
 *
 * Like Jackcess, the database starts from an empty database created by Access (resources/Access/empty*.gz):
 * its pages are kept, the user tables are appended (definitions, data pages, long value pages, usage maps,
 * B-trees of the indexes) and the system tables are updated: new rows in MSysObjects (tables, relationships,
 * properties), MSysACEs (permissions) and MSysRelationships, all their indexes being rebuilt.
 */
class AccessBuilder
{
    private const TEMPLATES = [
        JetFormat::VERSION_JET4 => 'empty2000.mdb.gz',
        JetFormat::VERSION_ACE12 => 'empty2007.accdb.gz',
        JetFormat::VERSION_ACE14 => 'empty2010.accdb.gz',
        JetFormat::VERSION_ACE16 => 'empty2016.accdb.gz',
        JetFormat::VERSION_ACE17 => 'empty2019.accdb.gz',
    ];

    private const TDEF_MAGIC = 0x0659;
    private const INDEX_MAGIC = 0x0783;
    private const TABLE_TYPE_USER = 0x4E;
    private const OBJECT_TYPE_RELATIONSHIP = 8;
    private const FULL_ACCESS_ACM = 1048575;
    private const DEFAULT_SID = "\xA6\x33";

    private const INDEX_FLAG_UNIQUE = 0x01;
    private const INDEX_FLAG_IGNORE_NULLS = 0x02;
    private const INDEX_FLAG_REQUIRED = 0x08;
    private const INDEX_FLAG_UNKNOWN = 0x80;
    private const INDEX_TYPE_PRIMARY_KEY = 1;
    private const INDEX_TYPE_FOREIGN_KEY = 2;
    private const FK_PRIMARY_TABLE = 1;
    private const FK_SECONDARY_TABLE = 2;

    private const MAX_ROW_SIZE = 4060;
    private const MAX_INLINE_LONG_VALUE = 64;
    private const MAX_LONG_VALUE_ROW = 4076;
    private const USAGE_MAP_ROW_SIZE = 69;
    private const USAGE_MAP_ROWS_PER_PAGE = 57;
    private const INDEX_ENTRIES_SIZE = 3616;
    private const BITS_PER_USAGE_MAP_PAGE = 32736;

    /**
     * @var int
     */
    private $version;

    /**
     * Content of the pages.
     *
     * @var array<int, string>
     */
    private $pages = [];

    /**
     * Number of pages of the template.
     *
     * @var int
     */
    private $templatePageCount = 0;

    /**
     * Pages of the template no longer used (old index pages of the system tables).
     *
     * @var array<int, bool>
     */
    private $freedPages = [];

    /**
     * @var int TextIndexCodes::GENERAL_LEGACY or TextIndexCodes::GENERAL
     */
    private $sortOrder = TextIndexCodes::GENERAL_LEGACY;

    /**
     * @var array<int, array{name: string, columns: array<int, ColumnDefinition>, rows: array<int, array<int, mixed>>, indexes: array<int, array{name: string, columns: array<int, bool>, primaryKey: bool, unique: bool}>, lastAutoNumber: int, properties: null|PropertyMap}>
     */
    private $tables = [];

    /**
     * @var array<int, array{name: string, table: string, columns: array<int, string>, referencedTable: string, referencedColumns: array<int, string>, flags: int}>
     */
    private $relationships = [];

    /**
     * Select and union queries: rows of MSysQueries (attribute, expression, flag, name1, name2).
     *
     * @var array<int, array{name: string, flags: int, rows: array<int, array{0: int, 1: null|string, 2: null|int, 3: null|string, 4: null|string}>, properties: null|PropertyMap}>
     */
    private $queries = [];

    /**
     * Properties of the database (SummaryInfo): Title, Author, Comments...
     *
     * @var array<string, string>
     */
    private $summary = [];

    /**
     * Usage map rows, by row pointer.
     *
     * @var array<int, string>
     */
    private $usageMaps = [];

    /**
     * @var int
     */
    private $usageMapPage = 0;

    /**
     * @var int
     */
    private $usageMapRow = self::USAGE_MAP_ROWS_PER_PAGE;

    public function __construct(int $version = JetFormat::VERSION_ACE12)
    {
        if (!isset(self::TEMPLATES[$version])) {
            throw new WriterException(sprintf('Access: version %d not supported', $version));
        }
        $this->version = $version;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    /**
     * Add a user table.
     *
     * @param array<int, ColumnDefinition> $columns name, type, length (bytes), flags (autonumber), precision, scale
     * @param array<int, array<int, mixed>> $rows values in the order of the columns
     * @param array<int, array{name: string, columns: array<int, bool>, primaryKey?: bool, unique?: bool}> $indexes
     *                                                                                                          columns: position => ascending
     * @param int $lastAutoNumber last value of the autonumber column
     * @param null|PropertyMap $properties properties of the table and of its columns
     */
    public function addTable(string $name, array $columns, array $rows, array $indexes = [], int $lastAutoNumber = 0, ?PropertyMap $properties = null): void
    {
        foreach ($this->tables as $table) {
            if (0 === strcasecmp($table['name'], $name)) {
                throw new WriterException(sprintf('Access: duplicate table "%s"', $name));
            }
        }
        $normalized = [];
        foreach ($indexes as $index) {
            foreach (array_keys($index['columns']) as $position) {
                if (!isset($columns[$position]) || !IndexKey::isIndexable($columns[$position]->type)) {
                    throw new WriterException(sprintf('Access: the column %d of the table "%s" can\'t be indexed', $position, $name));
                }
            }
            if (count($index['columns']) > 10) {
                throw new WriterException(sprintf('Access: the index "%s" has more than 10 columns', $index['name']));
            }
            $primaryKey = $index['primaryKey'] ?? false;
            $normalized[] = ['name' => $index['name'], 'columns' => $index['columns'], 'primaryKey' => $primaryKey, 'unique' => $primaryKey || ($index['unique'] ?? false)];
        }
        $this->tables[] = ['name' => $name, 'columns' => array_values($columns), 'rows' => $rows, 'indexes' => $normalized, 'lastAutoNumber' => $lastAutoNumber, 'properties' => $properties];
    }

    /**
     * Add a relationship (foreign key) from columns of a table to the columns of a referenced table.
     * Referential integrity is enforced when the referenced columns have a unique index.
     *
     * @param array<int, string> $columns
     * @param array<int, string> $referencedColumns
     * @param int $flags JetFormat::RELATIONSHIP_CASCADE_* flags
     */
    public function addRelationship(string $name, string $table, array $columns, string $referencedTable, array $referencedColumns, int $flags = 0): void
    {
        $this->relationships[] = [
            'name' => $name,
            'table' => $table,
            'columns' => array_values($columns),
            'referencedTable' => $referencedTable,
            'referencedColumns' => array_values($referencedColumns),
            'flags' => $flags,
        ];
    }

    /**
     * Add a query (QueryFormat::fromSql()); START and END rows are added.
     *
     * @param int $flags object flags of the query (QueryFormat::OBJECT_FLAG_*)
     * @param array<int, array{0: int, 1: null|string, 2: null|int, 3: null|string, 4: null|string}> $rows attribute, expression, flag, name1, name2
     */
    public function addQuery(string $name, int $flags, array $rows, ?PropertyMap $properties = null): void
    {
        foreach ($this->queries as $query) {
            if (0 === strcasecmp($query['name'], $name)) {
                throw new WriterException(sprintf('Access: duplicate query "%s"', $name));
            }
        }
        $this->queries[] = ['name' => $name, 'flags' => $flags, 'rows' => $rows, 'properties' => $properties];
    }

    /**
     * Property of the database (SummaryInfo): Title, Author, Company, Subject, Comments...
     */
    public function setSummaryProperty(string $name, string $value): void
    {
        $this->summary[$name] = $value;
    }

    public function build(?DateTimeImmutable $created = null): string
    {
        $created ??= new DateTimeImmutable();
        $data = self::loadTemplate(self::TEMPLATES[$this->version]);
        $file = new AccessFile($data);
        $this->pages = str_split($data, JetFormat::PAGE_SIZE);
        $this->templatePageCount = count($this->pages);
        $this->freedPages = [];
        $this->usageMaps = [];
        $this->usageMapPage = 0;
        $this->usageMapRow = self::USAGE_MAP_ROWS_PER_PAGE;
        // System tables of the template; the sort order of the text columns is the one of the template
        // (Access 2010+ uses "General", but the Access 2019 template of Jackcess uses "General Legacy")
        $catalog = $file->readTableDefinition(JetFormat::TDEF_PAGE_MSYS_OBJECTS, 'MSysObjects');
        $this->sortOrder = $this->version >= JetFormat::VERSION_ACE14 ? TextIndexCodes::GENERAL : TextIndexCodes::GENERAL_LEGACY;
        foreach ($catalog->columns as $column) {
            if ('Name' === $column->name) {
                $this->sortOrder = TextIndexCodes::GENERAL === $column->sortOrder ? TextIndexCodes::GENERAL : TextIndexCodes::GENERAL_LEGACY;
            }
        }
        $catalogRows = iterator_to_array($file->readRows($catalog));
        $containers = [];
        $owner = self::DEFAULT_SID;
        $systemTables = [];
        $lastSyntheticId = -0x80000000 - 1;
        $summaryPointer = null;
        foreach ($catalogRows as $pointer => $row) {
            if (3 === $row['Type'] && 0x0F000000 === $row['ParentId']) {
                $containers[(string) $row['Name']] = (int) $row['Id'];
            }
            if ($row['Id'] < 0) {
                $lastSyntheticId = max($lastSyntheticId, (int) $row['Id']);
            }
        }
        foreach ($catalogRows as $pointer => $row) {
            if ('MSysDb' === $row['Name'] && $row['ParentId'] === ($containers['Databases'] ?? null) && '' !== (string) $row['Owner']) {
                $owner = (string) $row['Owner'];
            }
            if ('SummaryInfo' === $row['Name'] && $row['ParentId'] === ($containers['Databases'] ?? null)) {
                $summaryPointer = $pointer;
            }
            if (JetFormat::OBJECT_TYPE_TABLE === $row['Type'] && $row['ParentId'] === ($containers['Tables'] ?? null)) {
                $systemTables[strtolower((string) $row['Name'])] = ((int) $row['Id']) & 0x00FFFFFF;
            }
        }
        if (!isset($containers['Tables'], $containers['Relationships'], $systemTables['msysaces'], $systemTables['msysrelationships'])) {
            throw new WriterException('Access: invalid template');
        }
        $names = [];
        foreach (array_merge(array_column($this->tables, 'name'), array_column($this->queries, 'name')) as $name) {
            if (isset($systemTables[strtolower($name)])) {
                throw new WriterException(sprintf('Access: the name "%s" is reserved', $name));
            }
            if (isset($names[strtolower($name)])) {
                throw new WriterException(sprintf('Access: the name "%s" is used by a table and a query', $name));
            }
            $names[strtolower($name)] = true;
        }
        if (!empty($this->queries) && !isset($systemTables['msysqueries'])) {
            throw new WriterException('Access: invalid template (no MSysQueries table)');
        }
        $aces = $file->readTableDefinition($systemTables['msysaces'], 'MSysACEs');
        $aceRows = iterator_to_array($file->readRows($aces));
        $relationshipsTable = $file->readTableDefinition($systemTables['msysrelationships'], 'MSysRelationships');
        $relationshipRows = iterator_to_array($file->readRows($relationshipsTable));
        $sids = static function (int $objectId) use ($aceRows): array {
            $result = [];
            foreach ($aceRows as $row) {
                if ($row['ObjectId'] === $objectId) {
                    $result[] = (string) $row['SID'];
                }
            }

            return empty($result) ? [self::DEFAULT_SID] : $result;
        };

        // User tables
        $tdefPages = [];
        foreach ($this->tables as $i => $table) {
            $tdefPages[$i] = $this->allocate();
        }
        [$logicalIndexes, $relationships] = $this->prepareIndexes($tdefPages);
        foreach ($this->tables as $i => $table) {
            $this->writeTable($table, $tdefPages[$i], $logicalIndexes[$i]);
        }

        // Rows of the system tables
        $newObjects = [];
        $newAces = [];
        $object = static function (int $id, int $parent, string $name, int $type, ?string $properties) use ($created, $owner, $catalog): array {
            $values = array_fill_keys(array_map(static fn (ColumnDefinition $column): string => $column->name, $catalog->columns), null);

            return array_merge($values, [
                'Id' => $id, 'ParentId' => $parent, 'Name' => $name, 'Type' => $type, 'DateCreate' => $created, 'DateUpdate' => $created,
                'Owner' => $owner, 'Flags' => 0, 'LvProp' => $properties,
            ]);
        };
        foreach ($this->tables as $i => $table) {
            $properties = null !== $table['properties'] && !$table['properties']->isEmpty() ? $table['properties']->write() : null;
            $newObjects[] = $object($tdefPages[$i], $containers['Tables'], $table['name'], JetFormat::OBJECT_TYPE_TABLE, $properties);
            foreach ($sids($containers['Tables']) as $sid) {
                $newAces[] = ['ObjectId' => $tdefPages[$i], 'SID' => $sid, 'ACM' => self::FULL_ACCESS_ACM, 'FInheritable' => false];
            }
        }
        $newRelationships = [];
        foreach ($relationships as $relationship) {
            $id = ++$lastSyntheticId;
            $newObjects[] = $object($id, $containers['Relationships'], $relationship['name'], self::OBJECT_TYPE_RELATIONSHIP, null);
            foreach ($sids($containers['Relationships']) as $sid) {
                $newAces[] = ['ObjectId' => $id, 'SID' => $sid, 'ACM' => self::FULL_ACCESS_ACM, 'FInheritable' => false];
            }
            foreach ($relationship['columns'] as $c => $column) {
                $newRelationships[] = [
                    'szRelationship' => $relationship['name'], 'grbit' => $relationship['flags'], 'ccolumn' => count($relationship['columns']), 'icolumn' => $c,
                    'szObject' => $relationship['table'], 'szColumn' => $column,
                    'szReferencedObject' => $relationship['referencedTable'], 'szReferencedColumn' => $relationship['referencedColumns'][$c],
                ];
            }
        }
        $newQueryRows = [];
        foreach ($this->queries as $query) {
            $id = ++$lastSyntheticId;
            $properties = null !== $query['properties'] && !$query['properties']->isEmpty() ? $query['properties']->write() : null;
            $newObjects[] = array_merge($object($id, $containers['Tables'], $query['name'], JetFormat::OBJECT_TYPE_QUERY, $properties), ['Flags' => $query['flags']]);
            foreach ($sids($containers['Tables']) as $sid) {
                $newAces[] = ['ObjectId' => $id, 'SID' => $sid, 'ACM' => self::FULL_ACCESS_ACM, 'FInheritable' => false];
            }
            $orders = [];
            foreach (array_merge([[0, null, 0, null, null]], $query['rows'], [[255, null, null, null, null]]) as [$attribute, $expression, $flag, $name1, $name2]) {
                $orders[$attribute] = ($orders[$attribute] ?? 0) + 1;
                $newQueryRows[] = [
                    'ObjectId' => $id, 'Attribute' => $attribute, 'Order' => pack('N', $orders[$attribute]), 'Name1' => $name1, 'Name2' => $name2,
                    'Expression' => $expression, 'Flag' => $flag, 'LvExtra' => null,
                ];
            }
        }
        $deletedObjects = [];
        if (!empty($this->summary) && null !== $summaryPointer) {
            $row = $catalogRows[$summaryPointer];
            $properties = PropertyMap::parse((string) $row['LvProp']);
            foreach ($this->summary as $name => $value) {
                $properties->set('', $name, JetFormat::TYPE_TEXT, $value);
            }
            $row['LvProp'] = $properties->write();
            $row['DateUpdate'] = $created;
            $deletedObjects[] = $summaryPointer;
            $newObjects[] = $row;
        }
        $this->updateSystemTable($file, $catalog, $catalogRows, $newObjects, $deletedObjects);
        $this->updateSystemTable($file, $aces, $aceRows, $newAces, []);
        $this->updateSystemTable($file, $relationshipsTable, $relationshipRows, $newRelationships, []);
        if (!empty($newQueryRows)) {
            $queriesTable = $file->readTableDefinition($systemTables['msysqueries'], 'MSysQueries');
            $this->updateSystemTable($file, $queriesTable, iterator_to_array($file->readRows($queriesTable)), $newQueryRows, []);
        }

        $this->writeUsageMapPages();
        $this->updateGlobalUsageMap();
        foreach ($this->pages as $number => $content) {
            if (JetFormat::PAGE_SIZE !== strlen($content)) {
                $this->pages[$number] = str_pad($content, JetFormat::PAGE_SIZE, "\0");
            }
        }
        ksort($this->pages);

        return implode('', $this->pages);
    }

    /**
     * Logical indexes of each table (primary key, indexes, foreign keys) and the relationships to write.
     *
     * @param array<int, int> $tdefPages
     *
     * @return array{0: array<int, array<int, array{name: string, columns: array<int, bool>, flags: int, ignored: int, type: int, fk: null|array{0: int, 1: int, 2: int, 3: int, 4: int}}>>, 1: array<int, array{name: string, table: string, columns: array<int, string>, referencedTable: string, referencedColumns: array<int, string>, flags: int}>}
     */
    private function prepareIndexes(array $tdefPages): array
    {
        $logical = [];
        foreach ($this->tables as $i => $table) {
            $logical[$i] = [];
            // The primary key first
            usort($table['indexes'], static fn (array $a, array $b): int => (int) $b['primaryKey'] <=> (int) $a['primaryKey']);
            foreach ($table['indexes'] as $index) {
                $flags = self::INDEX_FLAG_UNKNOWN | ($index['unique'] ? self::INDEX_FLAG_UNIQUE : 0) | ($index['primaryKey'] ? self::INDEX_FLAG_REQUIRED : 0);
                $logical[$i][] = ['name' => $index['name'], 'columns' => $index['columns'], 'flags' => $flags, 'ignored' => 0, 'type' => $index['primaryKey'] ? self::INDEX_TYPE_PRIMARY_KEY : 0, 'fk' => null];
            }
        }
        $find = function (string $name): ?int {
            foreach ($this->tables as $i => $table) {
                if (0 === strcasecmp($table['name'], $name)) {
                    return $i;
                }
            }

            return null;
        };
        $positions = function (int $table, array $names): ?array {
            $result = [];
            foreach ($names as $name) {
                $found = null;
                foreach ($this->tables[$table]['columns'] as $position => $column) {
                    if (0 === strcasecmp($column->name, $name)) {
                        $found = $position;
                    }
                }
                if (null === $found || in_array($found, $result, true)) {
                    return null;
                }
                $result[] = $found;
            }

            return $result;
        };
        $isUnique = static function (array $indexes, array $columns): bool {
            sort($columns);
            foreach ($indexes as $index) {
                $indexColumns = array_keys($index['columns']);
                sort($indexColumns);
                if (0 !== ($index['flags'] & self::INDEX_FLAG_UNIQUE) && $indexColumns === $columns) {
                    return true;
                }
            }

            return false;
        };
        $relationships = [];
        $names = [];
        foreach ($this->relationships as $relationship) {
            $secondary = $find($relationship['table']);
            $primary = $find($relationship['referencedTable']);
            if (null === $secondary || null === $primary) {
                continue;
            }
            $secondaryColumns = $positions($secondary, $relationship['columns']);
            $primaryColumns = $positions($primary, $relationship['referencedColumns']);
            if (null === $secondaryColumns || null === $primaryColumns || count($secondaryColumns) !== count($primaryColumns) || count($primaryColumns) > 10) {
                continue;
            }
            $name = '' !== $relationship['name'] ? $relationship['name'] : $this->tables[$primary]['name'] . $this->tables[$secondary]['name'];
            $name = self::uniqueName(mb_substr($name, 0, 64), $names);
            $names[] = $name;
            $flags = $relationship['flags'] & (JetFormat::RELATIONSHIP_CASCADE_UPDATES | JetFormat::RELATIONSHIP_CASCADE_DELETES | JetFormat::RELATIONSHIP_CASCADE_NULL);
            $enforced = $isUnique($logical[$primary], $primaryColumns);
            foreach ($primaryColumns as $c => $position) {
                if ($this->tables[$primary]['columns'][$position]->type !== $this->tables[$secondary]['columns'][$secondaryColumns[$c]]->type) {
                    $enforced = false;
                }
            }
            if (!$enforced) {
                $flags = JetFormat::RELATIONSHIP_NO_REFERENTIAL_INTEGRITY;
            } else {
                if ($isUnique($logical[$secondary], $secondaryColumns)) {
                    $flags |= JetFormat::RELATIONSHIP_ONE_TO_ONE;
                }
                // Primary table: unique index ".rB"..., secondary table: index named after the relationship
                $primaryNumber = count($logical[$primary]);
                $secondaryNumber = count($logical[$secondary]) + ($primary === $secondary ? 1 : 0);
                $cascadeUpdates = 0 !== ($flags & JetFormat::RELATIONSHIP_CASCADE_UPDATES) ? 1 : 0;
                $cascadeDeletes = (0 !== ($flags & JetFormat::RELATIONSHIP_CASCADE_DELETES) ? 1 : 0) | (0 !== ($flags & JetFormat::RELATIONSHIP_CASCADE_NULL) ? 2 : 0);
                $existing = array_map(static fn (array $index): string => $index['name'], $logical[$primary]);
                $suffix = 'B';
                while (in_array('.r' . $suffix, $existing, true)) {
                    $suffix = 'Z' === $suffix ? 'a' : chr(ord($suffix) + 1);
                }
                $logical[$primary][] = [
                    'name' => '.r' . $suffix, 'columns' => array_fill_keys($primaryColumns, true), 'flags' => self::INDEX_FLAG_UNKNOWN | self::INDEX_FLAG_UNIQUE,
                    'ignored' => self::INDEX_FLAG_IGNORE_NULLS | self::INDEX_FLAG_REQUIRED, 'type' => self::INDEX_TYPE_FOREIGN_KEY,
                    'fk' => [self::FK_PRIMARY_TABLE, $secondaryNumber, $tdefPages[$secondary], $cascadeUpdates, $cascadeDeletes],
                ];
                $existing = array_map(static fn (array $index): string => strtolower($index['name']), $logical[$secondary]);
                $indexName = $name;
                for ($n = 1; in_array(strtolower($indexName), $existing, true); ++$n) {
                    $indexName = $name . $n;
                }
                $logical[$secondary][] = [
                    'name' => $indexName, 'columns' => array_fill_keys($secondaryColumns, true), 'flags' => self::INDEX_FLAG_UNKNOWN,
                    'ignored' => self::INDEX_FLAG_IGNORE_NULLS | self::INDEX_FLAG_REQUIRED | self::INDEX_FLAG_UNIQUE, 'type' => self::INDEX_TYPE_FOREIGN_KEY,
                    'fk' => [self::FK_SECONDARY_TABLE, $primaryNumber, $tdefPages[$primary], $cascadeUpdates, $cascadeDeletes],
                ];
            }
            $relationships[] = [
                'name' => $name,
                'table' => $this->tables[$secondary]['name'],
                'columns' => array_map(fn (int $position): string => $this->tables[$secondary]['columns'][$position]->name, $secondaryColumns),
                'referencedTable' => $this->tables[$primary]['name'],
                'referencedColumns' => array_map(fn (int $position): string => $this->tables[$primary]['columns'][$position]->name, $primaryColumns),
                'flags' => $flags,
            ];
        }

        return [$logical, $relationships];
    }

    /**
     * @param array<int, string> $names
     */
    private static function uniqueName(string $name, array $names): string
    {
        $lower = array_map('strtolower', $names);
        $candidate = $name;
        for ($n = 1; in_array(strtolower($candidate), $lower, true); ++$n) {
            $candidate = $name . $n;
        }

        return $candidate;
    }

    /**
     * @param array{name: string, columns: array<int, ColumnDefinition>, rows: array<int, array<int, mixed>>, indexes: array<int, array{name: string, columns: array<int, bool>, primaryKey: bool, unique: bool}>, lastAutoNumber: int, properties: null|PropertyMap} $table
     * @param array<int, array{name: string, columns: array<int, bool>, flags: int, ignored: int, type: int, fk: null|array{0: int, 1: int, 2: int, 3: int, 4: int}}> $logicalIndexes
     */
    private function writeTable(array $table, int $tdef, array $logicalIndexes): void
    {
        $columns = $this->layoutColumns($table['columns']);

        // Real indexes shared by the logical indexes with the same columns and flags
        $realIndexes = [];
        foreach ($logicalIndexes as $l => $logical) {
            $found = null;
            foreach ($realIndexes as $r => $real) {
                if ($real['columns'] === $logical['columns'] && ($real['flags'] & ~$logical['ignored']) === ($logical['flags'] & ~$logical['ignored'])) {
                    $found = $r;

                    break;
                }
            }
            if (null === $found) {
                $found = count($realIndexes);
                $realIndexes[] = ['columns' => $logical['columns'], 'flags' => $logical['flags'], 'name' => $logical['name'], 'entries' => [], 'root' => 0, 'pages' => [], 'map' => 0];
            }
            $logicalIndexes[$l]['real'] = $found;
        }

        // Usage maps: owned pages, free space pages, index pages, long value pages of each memo/OLE column
        $ownedMap = $this->reserveUsageMap();
        $freeMap = $this->reserveUsageMap();
        foreach ($realIndexes as $r => $real) {
            $realIndexes[$r]['map'] = $this->reserveUsageMap();
        }
        $longValueColumns = [];
        foreach ($columns as $position => $column) {
            if (in_array($column->type, [JetFormat::TYPE_MEMO, JetFormat::TYPE_OLE], true)) {
                $longValueColumns[$position] = ['owned' => $this->reserveUsageMap(), 'free' => $this->reserveUsageMap(), 'writer' => new PageWriter($this, 0, true)];
            }
        }

        $data = new PageWriter($this, $tdef, false);
        $writers = array_map(static fn (array $entry): PageWriter => $entry['writer'], $longValueColumns);
        foreach ($table['rows'] as $values) {
            $pointer = $data->add($this->encodeRow($columns, $values, $writers));
            foreach ($realIndexes as $r => $real) {
                $realIndexes[$r]['entries'][] = [$this->indexKey($columns, $real['columns'], $values), $pointer, self::hasNull($columns, $real['columns'], $values)];
            }
        }
        $owned = [];
        foreach ($data->finish() as $page => $content) {
            $this->pages[$page] = $content;
            $owned[] = $page;
        }
        $this->usageMaps[$ownedMap] = $this->usageMapContent($owned);
        $this->usageMaps[$freeMap] = $this->usageMapContent([]);
        foreach ($longValueColumns as $entry) {
            $pages = [];
            foreach ($entry['writer']->finish() as $page => $content) {
                $this->pages[$page] = $content;
                $pages[] = $page;
            }
            $this->usageMaps[$entry['owned']] = $this->usageMapContent($pages);
            $this->usageMaps[$entry['free']] = $this->usageMapContent([]);
        }
        foreach ($realIndexes as $r => $real) {
            $entries = $this->prepareEntries($real['entries'], $real['flags'], $table['name'], $real['name']);
            [$realIndexes[$r]['root'], $realIndexes[$r]['pages']] = $this->writeIndex($tdef, $entries);
            $this->usageMaps[$real['map']] = $this->usageMapContent($realIndexes[$r]['pages']);
            $realIndexes[$r]['count'] = count($entries);
        }

        $definition = $this->tableDefinition($table, $columns, $realIndexes, $logicalIndexes, $longValueColumns, $ownedMap, $freeMap);
        $this->writeTableDefinition($tdef, $definition);
    }

    /**
     * Sort the entries of an index and check the unique constraint.
     *
     * Several null values are allowed in a unique index.
     *
     * @param array<int, array{0: string, 1: int, 2: bool}> $entries key, row pointer, has a null value
     *
     * @return array<int, string> entries: key + data page on 3 bytes + row
     */
    private function prepareEntries(array $entries, int $flags, string $table, string $index): array
    {
        usort($entries, static fn (array $a, array $b): int => strcmp($a[0], $b[0]) ?: $a[1] <=> $b[1]);
        $result = [];
        $previous = null;
        foreach ($entries as [$key, $pointer, $hasNull]) {
            if (0 !== ($flags & self::INDEX_FLAG_UNIQUE) && !$hasNull) {
                if ($key === $previous) {
                    throw new WriterException(sprintf('Access: duplicate value in the unique index "%s" of the table "%s"', $index, $table));
                }
                $previous = $key;
            }
            $result[] = $key . chr(($pointer >> 24) & 0xFF) . chr(($pointer >> 16) & 0xFF) . chr(($pointer >> 8) & 0xFF) . chr($pointer & 0xFF);
        }

        return $result;
    }

    /**
     * Key of a row in an index.
     *
     * @param array<int, ColumnDefinition> $columns
     * @param array<int, bool> $indexColumns position => ascending
     * @param array<int|string, mixed> $values by position or by name
     */
    private function indexKey(array $columns, array $indexColumns, array $values, bool $byName = false): string
    {
        $key = '';
        foreach ($indexColumns as $position => $ascending) {
            $column = $columns[$position];
            $value = $byName ? ($values[$column->name] ?? null) : ($values[$position] ?? null);
            $key .= IndexKey::encode($column, $ascending, $value, $this->sortOrder, JetFormat::VERSION_JET4 === $this->version);
        }

        return $key;
    }

    /**
     * @param array<int, ColumnDefinition> $columns
     * @param array<int, bool> $indexColumns
     * @param array<int, mixed> $values
     */
    private static function hasNull(array $columns, array $indexColumns, array $values): bool
    {
        foreach (array_keys($indexColumns) as $position) {
            if (null === ($values[$position] ?? null) && JetFormat::TYPE_BOOLEAN !== $columns[$position]->type) {
                return true;
            }
        }

        return false;
    }

    /**
     * Compute the column numbers, variable indexes (memo/OLE last, like Access) and fixed offsets.
     *
     * @param array<int, ColumnDefinition> $columns
     *
     * @return array<int, ColumnDefinition>
     */
    private function layoutColumns(array $columns): array
    {
        $fixedOffset = 0;
        $variableIndex = 0;
        foreach ($columns as $number => $column) {
            $column->number = $number;
            $size = JetFormat::fixedSize($column->type);
            if ($size > 0 || JetFormat::TYPE_BOOLEAN === $column->type) {
                $column->flags |= JetFormat::COLUMN_FLAG_FIXED | JetFormat::COLUMN_FLAG_UPDATABLE;
                $column->fixedOffset = $fixedOffset;
                $column->variableIndex = 0;
                $fixedOffset += $size;
                if (0 === $column->length) {
                    $column->length = max(1, $size);
                }
            } else {
                $column->flags = ($column->flags & ~JetFormat::COLUMN_FLAG_FIXED) | JetFormat::COLUMN_FLAG_UPDATABLE;
                $column->fixedOffset = 0;
                if (!in_array($column->type, [JetFormat::TYPE_MEMO, JetFormat::TYPE_OLE], true)) {
                    $column->variableIndex = $variableIndex++;
                }
            }
        }
        foreach ($columns as $column) {
            if (in_array($column->type, [JetFormat::TYPE_MEMO, JetFormat::TYPE_OLE], true)) {
                $column->variableIndex = $variableIndex++;
                $column->length = 0;
            }
        }

        return $columns;
    }

    /**
     * Table definition, following the layout written by Jackcess (TableImpl.writeTableDefinition).
     *
     * @param array{name: string, columns: array<int, ColumnDefinition>, rows: array<int, array<int, mixed>>, indexes: array<int, array{name: string, columns: array<int, bool>, primaryKey: bool, unique: bool}>, lastAutoNumber: int, properties: null|PropertyMap} $table
     * @param array<int, ColumnDefinition> $columns
     * @param array<int, array<string, mixed>> $realIndexes
     * @param array<int, array<string, mixed>> $logicalIndexes
     * @param array<int, array{owned: int, free: int, writer: PageWriter}> $longValueColumns
     */
    private function tableDefinition(array $table, array $columns, array $realIndexes, array $logicalIndexes, array $longValueColumns, int $ownedMap, int $freeMap): string
    {
        $variableCount = count(array_filter($columns, static fn (ColumnDefinition $column): bool => !$column->isFixed()));
        $body = '';
        foreach ($realIndexes as $real) {
            $body .= pack('VVV', 0, $real['count'], 0);
        }
        foreach ($columns as $column) {
            if (in_array($column->type, [JetFormat::TYPE_TEXT, JetFormat::TYPE_MEMO], true)) {
                $misc = pack('v', 1033) . "\0" . chr($this->sortOrder);
                $extraFlags = "\x01"; // Unicode compression
            } elseif (JetFormat::TYPE_NUMERIC === $column->type) {
                $misc = chr($column->precision) . chr($column->scale) . "\0\0";
                $extraFlags = "\0";
            } else {
                $misc = "\0\0\0\0";
                $extraFlags = "\0";
            }
            $body .= chr($column->type) . pack('V', self::TDEF_MAGIC) . pack('vvv', $column->number, $column->isFixed() ? 0 : $column->variableIndex, $column->number)
                . $misc . chr($column->flags) . $extraFlags . pack('V', 0) . pack('vv', $column->fixedOffset, $column->length);
        }
        foreach ($columns as $column) {
            $body .= self::name($column->name);
        }
        foreach ($realIndexes as $real) {
            $block = pack('V', self::INDEX_MAGIC);
            $indexColumns = $real['columns'];
            $positions = array_keys($indexColumns);
            for ($i = 0; $i < 10; ++$i) {
                $block .= isset($positions[$i]) ? pack('v', $columns[$positions[$i]]->number) . ($indexColumns[$positions[$i]] ? "\x01" : "\x00") : "\xff\xff\x00";
            }
            $body .= $block . pack('VV', $real['map'], $real['root']) . pack('V', 0) . chr($real['flags']) . str_repeat("\0", 5);
        }
        foreach ($logicalIndexes as $number => $logical) {
            $fk = $logical['fk'] ?? [0, 0xFFFFFFFF, 0, 0, 0];
            $body .= pack('VVV', self::TDEF_MAGIC, $number, $logical['real']) . chr($fk[0]) . pack('VV', $fk[1], $fk[2])
                . chr($fk[3]) . chr($fk[4]) . chr($logical['type']) . pack('V', 0);
        }
        foreach ($logicalIndexes as $logical) {
            $body .= self::name($logical['name']);
        }
        foreach ($longValueColumns as $position => $entry) {
            $body .= pack('v', $columns[$position]->number) . pack('VV', $entry['owned'], $entry['free']);
        }
        $body .= "\xff\xff";
        $header = "\x02\x01\0\0" . pack('V', 0) . pack('V', 0) . pack('V', self::TDEF_MAGIC) . pack('V', count($table['rows']))
            . pack('V', $table['lastAutoNumber']) . "\x01" . str_repeat("\0", 15)
            . chr(self::TABLE_TYPE_USER) . pack('vvv', count($columns), $variableCount, count($columns))
            . pack('VV', count($logicalIndexes), count($realIndexes)) . pack('VV', $ownedMap, $freeMap);
        $definition = $header . $body;

        return substr_replace($definition, pack('V', strlen($definition)), 8, 4);
    }

    /**
     * Split a table definition on its pages: the continuation pages have an 8 bytes header.
     */
    private function writeTableDefinition(int $tdef, string $definition): void
    {
        $chunks = [substr($definition, 0, JetFormat::PAGE_SIZE)];
        $rest = (string) substr($definition, JetFormat::PAGE_SIZE);
        while ('' !== $rest) {
            $chunks[] = substr($rest, 0, JetFormat::PAGE_SIZE - 8);
            $rest = (string) substr($rest, JetFormat::PAGE_SIZE - 8);
        }
        $pages = [$tdef];
        for ($i = 1, $count = count($chunks); $i < $count; ++$i) {
            $pages[] = $this->allocate();
        }
        foreach ($chunks as $i => $chunk) {
            $next = $pages[$i + 1] ?? 0;
            if (0 === $i) {
                $content = substr($chunk, 0, 2) . pack('v', max(0, JetFormat::PAGE_SIZE - strlen($chunk))) . pack('V', $next) . substr($chunk, 8);
            } else {
                $content = "\x02\x01" . pack('v', max(0, JetFormat::PAGE_SIZE - 8 - strlen($chunk))) . pack('V', $next) . $chunk;
            }
            $this->pages[$pages[$i]] = str_pad($content, JetFormat::PAGE_SIZE, "\0");
        }
    }

    /**
     * Add rows to a system table of the template (and delete some), then rebuild its indexes.
     *
     * @param array<int, array<string, mixed>> $existing rows of the template by row pointer
     * @param array<int, array<string, mixed>> $newRows values by column name
     * @param array<int, int> $deleted pointers of the rows to delete
     */
    private function updateSystemTable(AccessFile $file, TableDefinition $definition, array $existing, array $newRows, array $deleted): void
    {
        if (empty($newRows) && empty($deleted)) {
            return;
        }
        $columns = $definition->columns;
        foreach ($deleted as $pointer) {
            $page = $pointer >> 8;
            $offset = JetFormat::OFFSET_DATA_ROW_START + ($pointer & 0xFF) * 2;
            $location = unpack('v', $this->pages[$page], $offset)[1] ?? 0;
            $this->pages[$page] = substr_replace($this->pages[$page], pack('v', $location | JetFormat::ROW_DELETED), $offset, 2);
            unset($existing[$pointer]);
        }

        // New rows: data pages and long value pages
        $writers = [];
        foreach ($columns as $position => $column) {
            if (isset($definition->columnUsageMaps[$column->number]) || in_array($column->type, [JetFormat::TYPE_MEMO, JetFormat::TYPE_OLE], true)) {
                $writers[$position] = new PageWriter($this, 0, true);
            }
        }
        $data = new PageWriter($this, $definition->page, false);
        $rows = $existing;
        foreach ($newRows as $values) {
            $list = [];
            foreach ($columns as $position => $column) {
                $list[$position] = $values[$column->name] ?? null;
            }
            $rows[$data->add($this->encodeRow($columns, $list, $writers))] = $values;
        }
        $owned = $file->getUsageMapPages($definition->ownedPages);
        foreach ($data->finish() as $page => $content) {
            $this->pages[$page] = $content;
            $owned[] = $page;
        }
        $this->replaceRow($definition->ownedPages, $this->usageMapContent($owned));
        foreach ($writers as $position => $writer) {
            $pages = $writer->finish();
            if (empty($pages)) {
                continue;
            }
            foreach ($pages as $page => $content) {
                $this->pages[$page] = $content;
            }
            $map = $definition->columnUsageMaps[$columns[$position]->number] ?? null;
            if (null !== $map) {
                $this->replaceRow($map['owned'], $this->usageMapContent(array_merge($file->getUsageMapPages($map['owned']), array_keys($pages))));
            }
        }

        // Indexes
        $byNumber = [];
        foreach ($columns as $column) {
            $byNumber[$column->number] = $column;
        }
        foreach ($definition->realIndexes as $i => $real) {
            $entries = [];
            foreach ($rows as $pointer => $values) {
                $allNull = true;
                foreach (array_keys($real['columns']) as $number) {
                    $allNull = $allNull && null === ($values[$byNumber[$number]->name] ?? null);
                }
                if ($allNull && 0 !== ($real['flags'] & self::INDEX_FLAG_IGNORE_NULLS)) {
                    continue;
                }
                $entries[] = [$this->indexKey($byNumber, $real['columns'], $values, true), $pointer, !$allNull];
            }
            $entries = $this->prepareEntries($entries, $real['flags'], $definition->name, (string) $i);
            foreach ($file->getUsageMapPages($real['usageMap']) as $page) {
                $this->freePage($page);
            }
            $this->freePage($real['root']);
            [$root, $pages] = $this->writeIndex($definition->page, $entries);
            $this->replaceRow($real['usageMap'], $this->usageMapContent($pages));
            $this->patchTableDefinition($definition->page, $real['offset'] + 38, pack('V', $root));
            $this->patchTableDefinition($definition->page, JetFormat::SIZE_TDEF_HEADER + $i * JetFormat::SIZE_INDEX_DEFINITION + 4, pack('V', count($entries)));
        }
        $this->patchTableDefinition($definition->page, JetFormat::OFFSET_NUM_ROWS, pack('V', count($rows)));
    }

    private function patchTableDefinition(int $page, int $offset, string $bytes): void
    {
        while ($offset >= JetFormat::PAGE_SIZE) {
            $offset -= JetFormat::PAGE_SIZE - 8;
            $page = unpack('V', $this->pages[$page], JetFormat::OFFSET_NEXT_TDEF_PAGE)[1] ?? 0;
            if (0 === $page) {
                throw new WriterException('Access: invalid table definition in the template');
            }
            $offset += 8;
        }
        $this->pages[$page] = substr_replace($this->pages[$page], $bytes, $offset, strlen($bytes));
    }

    /**
     * Replace the content of a row of a data page (same length).
     */
    private function replaceRow(int $pointer, string $content): void
    {
        $page = $pointer >> 8;
        $row = $pointer & 0xFF;
        $start = (unpack('v', $this->pages[$page], JetFormat::OFFSET_DATA_ROW_START + $row * 2)[1] ?? 0) & JetFormat::ROW_OFFSET_MASK;
        $end = 0 === $row ? JetFormat::PAGE_SIZE : (unpack('v', $this->pages[$page], JetFormat::OFFSET_DATA_ROW_START + ($row - 1) * 2)[1] ?? 0) & JetFormat::ROW_OFFSET_MASK;
        if ($end - $start !== strlen($content)) {
            throw new WriterException('Access: unexpected size of a usage map in the template');
        }
        $this->pages[$page] = substr_replace($this->pages[$page], $content, $start, strlen($content));
    }

    /**
     * Page of the template no longer used: its header is cleared and it is marked as free.
     */
    private function freePage(int $page): void
    {
        if ($page > 2 && $page < $this->templatePageCount) {
            $this->pages[$page] = str_repeat("\0", JetFormat::PAGE_SIZE);
            $this->freedPages[$page] = true;
        }
    }

    /**
     * @param array<int, ColumnDefinition> $columns
     * @param array<int, mixed> $values by position
     * @param array<int, PageWriter> $longValues long value pages, by column position
     */
    private function encodeRow(array $columns, array $values, array $longValues): string
    {
        $count = 0;
        $fixedSize = 0;
        foreach ($columns as $column) {
            $count = max($count, $column->number + 1);
            if ($column->isFixed()) {
                $fixedSize = max($fixedSize, $column->fixedOffset + JetFormat::fixedSize($column->type));
            }
        }
        $nullMask = str_repeat("\0", intdiv($count + 7, 8));
        $fixed = str_repeat("\0", $fixedSize);
        $variableColumns = [];
        $variableData = [];
        foreach ($columns as $position => $column) {
            $value = $values[$position] ?? null;
            $setBit = static function () use (&$nullMask, $column): void {
                $nullMask[$column->number >> 3] = chr(ord($nullMask[$column->number >> 3]) | (1 << ($column->number & 7)));
            };
            if (JetFormat::TYPE_BOOLEAN === $column->type) {
                if (true === $value || (!is_bool($value) && null !== $value && (bool) $value)) {
                    $setBit();
                }

                continue;
            }
            if (!$column->isFixed()) {
                $variableColumns[$column->variableIndex] = $position;
            }
            if (null === $value) {
                continue;
            }
            $bytes = $this->encodeValue($column, $value, $longValues[$position] ?? null);
            if (null === $bytes) {
                continue;
            }
            $setBit();
            if ($column->isFixed()) {
                $fixed = substr_replace($fixed, $bytes, $column->fixedOffset, strlen($bytes));
            } else {
                $variableData[$position] = $bytes;
            }
        }
        // Variable data in the order of the variable indexes
        ksort($variableColumns);
        $variable = '';
        $offsets = [];
        $variableStart = 2 + $fixedSize;
        $variableCount = empty($variableColumns) ? 0 : max(array_keys($variableColumns)) + 1;
        for ($index = 0; $index < $variableCount; ++$index) {
            $offsets[] = $variableStart + strlen($variable);
            if (isset($variableColumns[$index])) {
                $variable .= $variableData[$variableColumns[$index]] ?? '';
            }
        }
        $trailer = pack('v', $variableStart + strlen($variable));
        foreach (array_reverse($offsets) as $offset) {
            $trailer .= pack('v', $offset);
        }
        $trailer .= pack('v', count($offsets)) . $nullMask;
        $row = pack('v', $count) . $fixed . $variable . $trailer;
        if (strlen($row) > self::MAX_ROW_SIZE) {
            throw new WriterException(sprintf('Access: row too large (%d bytes, maximum %d)', strlen($row), self::MAX_ROW_SIZE));
        }

        return $row;
    }

    private function encodeValue(ColumnDefinition $column, mixed $value, ?PageWriter $longValues): ?string
    {
        switch ($column->type) {
            case JetFormat::TYPE_BYTE:
                return chr(((int) $value) & 0xff);
            case JetFormat::TYPE_INT:
                return pack('v', ((int) $value) & 0xffff);
            case JetFormat::TYPE_LONG:
                return pack('V', ((int) $value) & 0xffffffff);
            case JetFormat::TYPE_BIG_INT:
                return pack('P', (int) $value);
            case JetFormat::TYPE_MONEY:
                return ValueCodec::encodeMoney((string) $value);
            case JetFormat::TYPE_FLOAT:
                return pack('g', (float) $value);
            case JetFormat::TYPE_DOUBLE:
                return pack('e', (float) $value);
            case JetFormat::TYPE_DATETIME:
                return pack('e', $value instanceof DateTimeImmutable ? ValueCodec::encodeDate($value) : (float) $value);
            case JetFormat::TYPE_EXT_DATETIME:
                return $value instanceof DateTimeImmutable ? ValueCodec::encodeExtendedDate($value) : null;
            case JetFormat::TYPE_GUID:
                return ValueCodec::encodeGuid((string) $value);
            case JetFormat::TYPE_NUMERIC:
                return ValueCodec::encodeNumeric((string) $value, $column->scale);
            case JetFormat::TYPE_TEXT:
                return ValueCodec::encodeText(mb_substr((string) $value, 0, max(1, intdiv($column->length, 2)), 'UTF-8'));
            case JetFormat::TYPE_BINARY:
                return substr((string) $value, 0, $column->length);
            case JetFormat::TYPE_MEMO:
                return $this->longValue(ValueCodec::encodeText((string) $value), $longValues);
            case JetFormat::TYPE_OLE:
                return $this->longValue((string) $value, $longValues);
            default:
                return null;
        }
    }

    /**
     * Memo/OLE: inline, in one LVAL row or in a chain of LVAL rows.
     */
    private function longValue(string $data, ?PageWriter $longValues): string
    {
        $length = strlen($data);
        if ($length > 0xFFFFFF) {
            throw new WriterException('Access: long value larger than 16 MB');
        }
        if ($length <= self::MAX_INLINE_LONG_VALUE) {
            return pack('V', $length | 0x80000000) . pack('VV', 0, 0) . $data;
        }
        if (null === $longValues) {
            throw new WriterException('Access: no long value page for this column');
        }
        if ($length <= self::MAX_LONG_VALUE_ROW) {
            $pointer = $longValues->add($data);

            return pack('V', $length | 0x40000000) . pack('VV', $pointer, 0);
        }
        // Chain written from the end: each row starts with the pointer to the next one
        $chunks = str_split($data, self::MAX_LONG_VALUE_ROW - 4);
        $next = 0;
        foreach (array_reverse($chunks) as $chunk) {
            $next = $longValues->add(pack('V', $next) . $chunk);
        }

        return pack('V', $length) . pack('VV', $next, 0);
    }

    /**
     * Write the B-tree of an index (an empty index has an empty leaf as root).
     *
     * @param array<int, string> $entries sorted entries (key + data page on 3 bytes + row)
     *
     * @return array{0: int, 1: array<int, int>} root page and pages of the index
     */
    private function writeIndex(int $tdef, array $entries): array
    {
        $allPages = [];
        $groups = $this->groupIndexEntries($entries, 0);
        $level = [];
        foreach ($groups as $group) {
            $level[] = ['page' => $this->allocate(), 'entries' => $group, 'tail' => 0];
        }
        $leaf = true;
        while (true) {
            foreach ($level as $i => $node) {
                $this->pages[$node['page']] = $this->indexPage(
                    $leaf ? JetFormat::PAGE_INDEX_LEAF : JetFormat::PAGE_INDEX_NODE,
                    $tdef,
                    $node['entries'],
                    $level[$i - 1]['page'] ?? 0,
                    $level[$i + 1]['page'] ?? 0,
                    $node['tail']
                );
                $allPages[] = $node['page'];
            }
            if (1 === count($level)) {
                return [$level[0]['page'], $allPages];
            }
            // Node entries: last entry of each child + child page, the last child being the tail
            $nodeEntries = [];
            foreach ($level as $node) {
                $nodeEntries[] = (end($node['entries']) ?: '') . pack('N', $node['page']);
            }
            $parents = [];
            foreach ($this->groupIndexEntries($nodeEntries, 1) as $group) {
                $tail = (string) array_pop($group);
                $parents[] = ['page' => $this->allocate(), 'entries' => $group, 'tail' => unpack('N', substr($tail, -4))[1] ?? 0];
            }
            $level = $parents;
            $leaf = false;
        }
    }

    /**
     * @param array<int, string> $entries
     *
     * @return array<int, array<int, string>>
     */
    private function groupIndexEntries(array $entries, int $minimumExtra): array
    {
        $groups = [];
        $current = [];
        $size = 0;
        foreach ($entries as $entry) {
            if (count($current) > $minimumExtra && $size + strlen($entry) > self::INDEX_ENTRIES_SIZE) {
                $groups[] = $current;
                $current = [];
                $size = 0;
            }
            $current[] = $entry;
            $size += strlen($entry);
        }
        if (!empty($current) || empty($groups)) {
            if ($minimumExtra > 0 && count($current) < 2 && !empty($groups)) {
                array_unshift($current, array_pop($groups[count($groups) - 1]));
            }
            $groups[] = $current;
        }

        return $groups;
    }

    /**
     * @param array<int, string> $entries
     */
    private function indexPage(int $type, int $tdef, array $entries, int $previous, int $next, int $tail): string
    {
        $mask = str_repeat("\0", 453);
        $content = '';
        foreach ($entries as $entry) {
            $content .= $entry;
            $end = strlen($content);
            $mask[$end >> 3] = chr(ord($mask[$end >> 3]) | (1 << ($end & 7)));
        }
        $page = chr($type) . "\x01" . pack('v', self::INDEX_ENTRIES_SIZE - strlen($content)) . pack('V', $tdef) . pack('V', 0)
            . pack('VVV', $previous, $next, $tail) . "\0\0\0" . $mask . $content;

        return str_pad($page, JetFormat::PAGE_SIZE, "\0");
    }

    /**
     * Reserve a row for a usage map of a user table (its content is set later).
     */
    private function reserveUsageMap(): int
    {
        if ($this->usageMapRow >= self::USAGE_MAP_ROWS_PER_PAGE) {
            $this->usageMapPage = $this->allocate();
            $this->usageMapRow = 0;
        }
        $pointer = ($this->usageMapPage << 8) | $this->usageMapRow++;
        $this->usageMaps[$pointer] = str_repeat("\0", self::USAGE_MAP_ROW_SIZE);

        return $pointer;
    }

    /**
     * Content of a usage map row: inline bitmap when the pages are close, else reference map
     * (usage map pages covering 32736 pages each).
     *
     * @param array<int, int> $pages
     */
    private function usageMapContent(array $pages): string
    {
        if (empty($pages)) {
            return str_repeat("\0", self::USAGE_MAP_ROW_SIZE);
        }
        $start = min($pages);
        $bits = (self::USAGE_MAP_ROW_SIZE - 5) * 8;
        if (max($pages) - $start < $bits) {
            $bitmap = str_repeat("\0", self::USAGE_MAP_ROW_SIZE - 5);
            foreach ($pages as $page) {
                $bit = $page - $start;
                $bitmap[$bit >> 3] = chr(ord($bitmap[$bit >> 3]) | (1 << ($bit & 7)));
            }

            return "\x00" . pack('V', $start) . $bitmap;
        }
        $mapPages = [];
        foreach ($pages as $page) {
            $mapPages[intdiv($page, self::BITS_PER_USAGE_MAP_PAGE)][] = $page;
        }
        $references = array_fill(0, intdiv(self::USAGE_MAP_ROW_SIZE - 1, 4), 0);
        foreach ($mapPages as $index => $covered) {
            if ($index >= count($references)) {
                throw new WriterException('Access: database too large');
            }
            $bitmap = str_repeat("\0", JetFormat::PAGE_SIZE - 4);
            foreach ($covered as $page) {
                $bit = $page - $index * self::BITS_PER_USAGE_MAP_PAGE;
                $bitmap[$bit >> 3] = chr(ord($bitmap[$bit >> 3]) | (1 << ($bit & 7)));
            }
            $mapPage = $this->allocate();
            $this->pages[$mapPage] = "\x05\x01\0\0" . $bitmap;
            $references[$index] = $mapPage;
        }

        return "\x01" . pack('V*', ...$references);
    }

    private function writeUsageMapPages(): void
    {
        $byPage = [];
        foreach ($this->usageMaps as $pointer => $row) {
            $byPage[$pointer >> 8][$pointer & 0xff] = $row;
        }
        foreach ($byPage as $page => $rows) {
            ksort($rows);
            $this->pages[$page] = PageWriter::dataPage(0, array_values($rows), false);
        }
    }

    /**
     * Global usage map (page 1, row 0): a bit set marks a free page. Like Jackcess, the inline map is moved
     * to cover the end of the file when the database has more than 512 pages.
     */
    private function updateGlobalUsageMap(): void
    {
        $count = count($this->pages);
        $page = $this->pages[1];
        $start = (unpack('v', $page, JetFormat::OFFSET_DATA_ROW_START)[1] ?? 0) & JetFormat::ROW_OFFSET_MASK;
        $end = JetFormat::PAGE_SIZE;
        if (0 !== ord($page[$start]) || $end - $start < 5) {
            throw new WriterException('Access: unsupported global usage map in the template');
        }
        $row = substr($page, $start, $end - $start);
        $oldStart = (int) unpack('V', $row, 1)[1];
        $bits = (strlen($row) - 5) * 8;
        $newStart = $count <= $oldStart + $bits ? $oldStart : (($count - intdiv($bits, 2)) & ~7);
        $bitmap = str_repeat("\0", strlen($row) - 5);
        for ($i = 0; $i < $bits; ++$i) {
            $number = $newStart + $i;
            $free = $number >= $count || isset($this->freedPages[$number]);
            if ($number < $this->templatePageCount && $number >= $oldStart && $number < $oldStart + $bits && !isset($this->freedPages[$number])) {
                // Pages of the template: unchanged
                $old = $number - $oldStart;
                $free = 0 !== (ord($row[5 + ($old >> 3)]) & (1 << ($old & 7)));
            }
            if ($free) {
                $bitmap[$i >> 3] = chr(ord($bitmap[$i >> 3]) | (1 << ($i & 7)));
            }
        }
        $this->pages[1] = substr_replace($page, "\x00" . pack('V', $newStart) . $bitmap, $start, strlen($row));
    }

    private static function loadTemplate(string $resource): string
    {
        $path = dirname(__DIR__, 2) . '/Resources/Access/' . $resource;
        $data = is_file($path) ? @gzdecode((string) file_get_contents($path)) : false;
        if (false === $data || 0 !== strlen($data) % JetFormat::PAGE_SIZE) {
            throw new WriterException(sprintf('Access: missing template "%s"', $resource));
        }

        return $data;
    }

    private static function name(string $name): string
    {
        $utf16 = (string) mb_convert_encoding($name, 'UTF-16LE', 'UTF-8');

        return pack('v', strlen($utf16)) . $utf16;
    }

    /**
     * @internal used by PageWriter
     */
    public function allocate(): int
    {
        $page = count($this->pages);
        $this->pages[$page] = '';

        return $page;
    }
}
