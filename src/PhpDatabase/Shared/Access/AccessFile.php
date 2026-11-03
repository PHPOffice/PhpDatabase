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

namespace PhpOffice\PhpDatabase\Shared\Access;

use DateTimeImmutable;
use Generator;
use PhpOffice\PhpDatabase\Exception\FeatureNotImplementedException;
use PhpOffice\PhpDatabase\Exception\ReaderException;

/**
 * Reader of the Jet 4 / ACE databases (.mdb from Access 2000, .accdb).
 */
class AccessFile
{
    public const SIGNATURE_JET = "\x00\x01\x00\x00Standard Jet DB\x00";
    public const SIGNATURE_ACE = "\x00\x01\x00\x00Standard ACE DB\x00";

    /**
     * @var string
     */
    private $data;

    /**
     * @var int
     */
    private $version;

    /**
     * Decoded header (page 0).
     *
     * @var string
     */
    private $header;

    /**
     * RC4 key of the pages of an encoded Jet 4 database (0: not encoded).
     *
     * @var int
     */
    private $encodingKey = 0;

    /**
     * @var array<int, string>
     */
    private $decodedPages = [];

    public function __construct(string $data)
    {
        $signature = substr($data, 0, 20);
        if (self::SIGNATURE_JET !== $signature && self::SIGNATURE_ACE !== $signature) {
            throw new ReaderException('Not an Access database');
        }
        $this->data = $data;
        $this->version = ord($data[0x14]);
        if (JetFormat::VERSION_JET3 === $this->version) {
            throw new FeatureNotImplementedException('Access 97 (Jet 3) databases are not supported');
        }
        if (strlen($data) < JetFormat::PAGE_SIZE * 3) {
            throw new ReaderException('Access: truncated file');
        }
        $size = $this->version >= JetFormat::VERSION_ACE12 ? JetFormat::SIZE_MASKED_HEADER_ACE : JetFormat::SIZE_MASKED_HEADER_JET4;
        $this->header = substr($data, 0, JetFormat::OFFSET_MASKED_HEADER)
            . JetFormat::rc4(pack('V', JetFormat::HEADER_MASK_KEY), substr($data, JetFormat::OFFSET_MASKED_HEADER, $size))
            . substr($data, JetFormat::OFFSET_MASKED_HEADER + $size, JetFormat::PAGE_SIZE - JetFormat::OFFSET_MASKED_HEADER - $size);
        if ($this->version < JetFormat::VERSION_ACE12) {
            $this->encodingKey = $this->uint32($this->header, JetFormat::OFFSET_ENCODING_KEY);
        }
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getCodePage(): int
    {
        return $this->uint16($this->header, JetFormat::OFFSET_CODE_PAGE);
    }

    public function getCreationDate(): DateTimeImmutable
    {
        $unpacked = unpack('e', $this->header, JetFormat::OFFSET_HEADER_DATE);

        return ValueCodec::decodeDate(false === $unpacked ? 0.0 : (float) $unpacked[1]);
    }

    /**
     * Rows of the catalog (MSysObjects): Id, ParentId, Name, Type, Flags, LvProp.
     *
     * @var null|array<int, array<string, mixed>>
     */
    private $catalog;

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getCatalog(): array
    {
        if (null === $this->catalog) {
            $definition = $this->readTableDefinition(JetFormat::TDEF_PAGE_MSYS_OBJECTS, 'MSysObjects');
            $this->catalog = iterator_to_array($this->readRows($definition, ['Id', 'ParentId', 'Name', 'Type', 'Flags', 'LvProp']), false);
        }

        return $this->catalog;
    }

    private function getContainerId(string $name, int $default): int
    {
        foreach ($this->getCatalog() as $row) {
            if ($name === $row['Name'] && 3 === $row['Type']) {
                return (int) $row['Id'];
            }
        }

        return $default;
    }

    /**
     * Properties of a table (LvProp column of MSysObjects).
     */
    public function getTableProperties(string $name): PropertyMap
    {
        $tablesContainer = $this->getContainerId('Tables', 0x0F000001);
        foreach ($this->getCatalog() as $row) {
            if ($row['Name'] === $name && $row['ParentId'] === $tablesContainer && null !== $row['LvProp']) {
                return PropertyMap::parse((string) $row['LvProp']);
            }
        }

        return new PropertyMap();
    }

    /**
     * Properties of the database (SummaryInfo): Title, Author, Comments...
     */
    public function getSummaryProperties(): PropertyMap
    {
        $databases = $this->getContainerId('Databases', 0x0F000002);
        foreach ($this->getCatalog() as $row) {
            if ('SummaryInfo' === $row['Name'] && $row['ParentId'] === $databases && null !== $row['LvProp']) {
                return PropertyMap::parse((string) $row['LvProp']);
            }
        }

        return new PropertyMap();
    }

    /**
     * Relationships (MSysRelationships): name => table, columns, referenced table, referenced columns, flags.
     *
     * @return array<string, array{table: string, columns: array<int, string>, referencedTable: string, referencedColumns: array<int, string>, flags: int}>
     */
    public function getRelationships(): array
    {
        $tablesContainer = $this->getContainerId('Tables', 0x0F000001);
        $page = null;
        foreach ($this->getCatalog() as $row) {
            if ('MSysRelationships' === $row['Name'] && $row['ParentId'] === $tablesContainer) {
                $page = ((int) $row['Id']) & 0x00FFFFFF;
            }
        }
        if (null === $page) {
            return [];
        }
        $rows = iterator_to_array($this->readRows($this->readTableDefinition($page, 'MSysRelationships')), false);
        usort($rows, static fn (array $a, array $b): int => (int) $a['icolumn'] <=> (int) $b['icolumn']);
        $relationships = [];
        foreach ($rows as $row) {
            $name = (string) $row['szRelationship'];
            $relationships[$name] ??= [
                'table' => (string) $row['szObject'],
                'columns' => [],
                'referencedTable' => (string) $row['szReferencedObject'],
                'referencedColumns' => [],
                'flags' => (int) $row['grbit'],
            ];
            $relationships[$name]['columns'][] = (string) $row['szColumn'];
            $relationships[$name]['referencedColumns'][] = (string) $row['szReferencedColumn'];
        }

        return $relationships;
    }

    /**
     * TDEF page of a system table (MSysQueries, MSysComplexColumns...).
     */
    public function getSystemTablePage(string $name): ?int
    {
        $tablesContainer = $this->getContainerId('Tables', 0x0F000001);
        foreach ($this->getCatalog() as $row) {
            if ($name === $row['Name'] && $row['ParentId'] === $tablesContainer && JetFormat::OBJECT_TYPE_TABLE === $row['Type']) {
                return ((int) $row['Id']) & 0x00FFFFFF;
            }
        }

        return null;
    }

    /**
     * Name of an object of the catalog by its id.
     */
    public function getObjectName(int $id): ?string
    {
        foreach ($this->getCatalog() as $row) {
            if ($id === $row['Id']) {
                return (string) $row['Name'];
            }
        }

        return null;
    }

    /**
     * Queries (MSysObjects rows of type 5 and their MSysQueries rows): name => object flags, rows.
     *
     * @return array<string, array{flags: int, properties: PropertyMap, rows: array<int, array<string, mixed>>}>
     */
    public function getQueries(): array
    {
        $page = $this->getSystemTablePage('MSysQueries');
        if (null === $page) {
            return [];
        }
        $queries = [];
        $names = [];
        foreach ($this->getCatalog() as $row) {
            if (JetFormat::OBJECT_TYPE_QUERY === $row['Type'] && null !== $row['Name']) {
                $flags = (int) ($row['Flags'] ?? 0);
                if (0 !== ($flags & JetFormat::OBJECT_FLAG_SYSTEM) || str_starts_with((string) $row['Name'], '~')) {
                    continue;
                }
                $names[(int) $row['Id']] = (string) $row['Name'];
                $queries[(string) $row['Name']] = ['flags' => $flags, 'properties' => PropertyMap::parse((string) $row['LvProp']), 'rows' => []];
            }
        }
        foreach ($this->readRows($this->readTableDefinition($page, 'MSysQueries')) as $row) {
            $name = $names[(int) $row['ObjectId']] ?? null;
            if (null !== $name) {
                $queries[$name]['rows'][] = $row;
            }
        }
        foreach ($queries as $name => $query) {
            // Rows by attribute, then by order
            usort($query['rows'], static fn (array $a, array $b): int => [((int) $a['Attribute']) & 0xFF, (string) $a['Order']] <=> [((int) $b['Attribute']) & 0xFF, (string) $b['Order']]);
            $queries[$name]['rows'] = $query['rows'];
        }

        return $queries;
    }

    /**
     * Complex columns (MSysComplexColumns): ComplexID => column name, type (name of the MSysComplexType_* table),
     * TDEF page of the flat table holding the values, TDEF page of the table of the column.
     *
     * @return array<int, array{column: string, type: string, flatTable: int, table: int}>
     */
    public function getComplexColumns(): array
    {
        $page = $this->getSystemTablePage('MSysComplexColumns');
        if (null === $page) {
            return [];
        }
        $columns = [];
        foreach ($this->readRows($this->readTableDefinition($page, 'MSysComplexColumns')) as $row) {
            $columns[(int) $row['ComplexID']] = [
                'column' => (string) $row['ColumnName'],
                'type' => (string) $this->getObjectName((int) $row['ComplexTypeObjectID']),
                'flatTable' => ((int) $row['FlatTableID']) & 0x00FFFFFF,
                'table' => ((int) $row['ConceptualTableID']) & 0x00FFFFFF,
            ];
        }

        return $columns;
    }

    /**
     * User tables (name => TDEF page), from MSysObjects.
     *
     * @return array<string, int>
     */
    public function getUserTables(): array
    {
        $rows = $this->getCatalog();
        $tablesContainer = $this->getContainerId('Tables', 0x0F000001);
        $tables = [];
        foreach ($rows as $row) {
            if (JetFormat::OBJECT_TYPE_TABLE !== ($row['Type'] ?? null) || $row['ParentId'] !== $tablesContainer) {
                continue;
            }
            $flags = (int) ($row['Flags'] ?? 0);
            $name = (string) $row['Name'];
            if (0 !== ($flags & JetFormat::OBJECT_FLAG_SYSTEM) || str_starts_with($name, 'MSys') || str_starts_with($name, '~')) {
                continue;
            }
            $tables[$name] = ((int) $row['Id']) & 0x00FFFFFF;
        }

        return $tables;
    }

    public function readTableDefinition(int $page, string $name = ''): TableDefinition
    {
        $data = $this->readTableDefinitionData($page);
        $definition = new TableDefinition();
        $definition->name = $name;
        $definition->page = $page;
        $definition->rowCount = $this->uint32($data, JetFormat::OFFSET_NUM_ROWS);
        $definition->nextAutoNumber = $this->uint32($data, JetFormat::OFFSET_NEXT_AUTO_NUMBER);
        $columnCount = $this->uint16($data, JetFormat::OFFSET_NUM_COLS);
        $logicalIndexCount = $this->uint32($data, JetFormat::OFFSET_NUM_INDEX_SLOTS);
        $realIndexCount = $this->uint32($data, JetFormat::OFFSET_NUM_INDEXES);
        $definition->ownedPages = $this->uint32($data, JetFormat::OFFSET_OWNED_PAGES);
        $definition->freeSpacePages = $this->uint32($data, JetFormat::OFFSET_FREE_SPACE_PAGES);
        $definition->data = $data;
        if ($columnCount > 255 || $logicalIndexCount > 1000 || $realIndexCount > 1000) {
            throw new ReaderException(sprintf('Access: invalid table definition on page %d', $page));
        }

        $offset = JetFormat::SIZE_TDEF_HEADER + $realIndexCount * JetFormat::SIZE_INDEX_DEFINITION;
        $columns = [];
        for ($i = 0; $i < $columnCount; ++$i) {
            $column = new ColumnDefinition();
            $column->type = ord($data[$offset + JetFormat::OFFSET_COLUMN_TYPE]);
            $column->number = $this->uint16($data, $offset + JetFormat::OFFSET_COLUMN_NUMBER);
            $column->variableIndex = $this->uint16($data, $offset + JetFormat::OFFSET_COLUMN_VARIABLE_INDEX);
            $column->precision = ord($data[$offset + JetFormat::OFFSET_COLUMN_PRECISION]);
            $column->scale = ord($data[$offset + JetFormat::OFFSET_COLUMN_SCALE]);
            $column->flags = ord($data[$offset + JetFormat::OFFSET_COLUMN_FLAGS]);
            $column->fixedOffset = $this->uint16($data, $offset + JetFormat::OFFSET_COLUMN_FIXED_OFFSET);
            $column->length = $this->uint16($data, $offset + JetFormat::OFFSET_COLUMN_LENGTH);
            $column->calculated = 0 !== (ord($data[$offset + JetFormat::OFFSET_COLUMN_FLAGS + 1]) & 0xC0);
            if (in_array($column->type, [JetFormat::TYPE_TEXT, JetFormat::TYPE_MEMO], true)) {
                $column->sortOrder = ord($data[$offset + JetFormat::OFFSET_COLUMN_PRECISION + 3]);
            }
            if (JetFormat::TYPE_COMPLEX === $column->type) {
                $column->complexId = $this->uint32($data, $offset + JetFormat::OFFSET_COLUMN_PRECISION);
            }
            $columns[] = $column;
            $offset += JetFormat::SIZE_COLUMN_DEFINITION;
        }
        foreach ($columns as $column) {
            [$column->name, $offset] = $this->readName($data, $offset);
        }
        usort($columns, static fn (ColumnDefinition $a, ColumnDefinition $b): int => $a->number <=> $b->number);
        $definition->columns = $columns;

        // Real indexes: columns (number + ascending flag), usage map, root page, flags
        for ($i = 0; $i < $realIndexCount; ++$i) {
            $columns = [];
            for ($j = 0; $j < 10; ++$j) {
                $number = $this->uint16($data, $offset + 4 + $j * 3);
                if (0xFFFF !== $number) {
                    $columns[$number] = 0 !== (ord($data[$offset + 6 + $j * 3] ?? "\0") & 0x01);
                }
            }
            $definition->realIndexes[] = [
                'columns' => $columns,
                'flags' => ord($data[$offset + 46] ?? "\0"),
                'root' => $this->uint32($data, $offset + 38),
                'usageMap' => $this->uint32($data, $offset + 34),
                'offset' => $offset,
            ];
            $offset += JetFormat::SIZE_INDEX_COLUMN_BLOCK;
        }
        // Logical indexes, then their names
        for ($i = 0; $i < $logicalIndexCount; ++$i) {
            $definition->logicalIndexes[] = [
                'name' => '',
                'number' => $this->uint32($data, $offset + 4),
                'realIndex' => $this->uint32($data, $offset + 8),
                'relatedTableType' => ord($data[$offset + 12] ?? "\0"),
                'relatedIndex' => $this->uint32($data, $offset + 13),
                'relatedTable' => $this->uint32($data, $offset + 17),
                'cascadeUpdates' => ord($data[$offset + 21] ?? "\0"),
                'cascadeDeletes' => ord($data[$offset + 22] ?? "\0"),
                'type' => ord($data[$offset + 23] ?? "\0"),
            ];
            $offset += JetFormat::SIZE_INDEX_INFO_BLOCK;
        }
        foreach ($definition->logicalIndexes as $i => $logical) {
            [$definition->logicalIndexes[$i]['name'], $offset] = $this->readName($data, $offset);
            if (1 === $logical['type'] && isset($definition->realIndexes[$logical['realIndex']])) {
                $definition->primaryKey = array_keys($definition->realIndexes[$logical['realIndex']]['columns']);
            }
        }
        // Usage maps of the long value columns (Jackcess, Access 2007+)
        while ($offset + 10 <= strlen($data) && 0xFFFF !== $this->uint16($data, $offset)) {
            $definition->columnUsageMaps[$this->uint16($data, $offset)] = [
                'owned' => $this->uint32($data, $offset + 2),
                'free' => $this->uint32($data, $offset + 6),
                'offset' => $offset,
            ];
            $offset += 10;
        }

        return $definition;
    }

    /**
     * Rows of a table, indexed by row pointer (data page << 8 | row number).
     *
     * @param null|array<int, string> $only names of the columns to decode (all if null)
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function readRows(TableDefinition $definition, ?array $only = null): Generator
    {
        $columns = $definition->columns;
        if (null !== $only) {
            $columns = array_values(array_filter($columns, static fn (ColumnDefinition $column): bool => in_array($column->name, $only, true)));
        }
        foreach ($this->getOwnedDataPages($definition) as $pageNumber) {
            $page = $this->readPage($pageNumber);
            if (JetFormat::PAGE_DATA !== ord($page[0]) || $this->uint32($page, JetFormat::OFFSET_DATA_PAGE_TDEF) !== $definition->page) {
                continue;
            }
            $rowCount = $this->uint16($page, JetFormat::OFFSET_DATA_NUM_ROWS);
            for ($row = 0; $row < $rowCount; ++$row) {
                $location = $this->uint16($page, JetFormat::OFFSET_DATA_ROW_START + $row * 2);
                if (0 !== ($location & JetFormat::ROW_DELETED)) {
                    continue;
                }
                $data = $this->readRowData($page, $row);
                if (0 !== ($location & JetFormat::ROW_LOOKUP)) {
                    // Overflow row: pointer to the real row
                    $pointer = $this->uint32($data, 0);
                    $data = $this->readRowData($this->readPage($pointer >> 8), $pointer & 0xff);
                }
                yield ($pageNumber << 8) | $row => $this->decodeRow($data, $columns);
            }
        }
    }

    /**
     * @param array<int, ColumnDefinition> $columns
     *
     * @return array<string, mixed>
     */
    private function decodeRow(string $data, array $columns): array
    {
        $length = strlen($data);
        $columnCount = $this->uint16($data, 0);
        $nullMaskSize = intdiv($columnCount + 7, 8);
        $nullMask = substr($data, $length - $nullMaskSize, $nullMaskSize);
        $variableCount = $this->uint16($data, $length - $nullMaskSize - 2);
        $variableOffsets = [];
        for ($i = 0; $i < $variableCount; ++$i) {
            $variableOffsets[] = $this->uint16($data, $length - $nullMaskSize - 4 - $i * 2);
        }
        $endOfData = $this->uint16($data, $length - $nullMaskSize - 4 - $variableCount * 2);
        $values = [];
        foreach ($columns as $column) {
            $present = $column->number < $columnCount
                && ((ord($nullMask[$column->number >> 3] ?? "\0") >> ($column->number & 7)) & 1) === 1;
            if (JetFormat::TYPE_BOOLEAN === $column->type && !$column->calculated) {
                $values[$column->name] = $present;

                continue;
            }
            if (!$present) {
                $values[$column->name] = null;

                continue;
            }
            if ($column->isFixed()) {
                $bytes = substr($data, 2 + $column->fixedOffset, JetFormat::fixedSize($column->type) ?: $column->length);
            } else {
                if ($column->variableIndex >= $variableCount) {
                    $values[$column->name] = null;

                    continue;
                }
                $start = $variableOffsets[$column->variableIndex];
                $end = $column->variableIndex + 1 < $variableCount ? $variableOffsets[$column->variableIndex + 1] : $endOfData;
                $bytes = substr($data, $start, max(0, $end - $start));
            }
            $values[$column->name] = $column->calculated ? $this->decodeCalculatedValue($column, $bytes) : $this->decodeValue($column, $bytes);
        }

        return $values;
    }

    /**
     * Value of a calculated column: header of 16 bytes, length of the data, data (rules of Jackcess,
     * CalculatedColumnUtil).
     */
    private function decodeCalculatedValue(ColumnDefinition $column, string $bytes): mixed
    {
        $unwrap = fn (string $data): string => strlen($data) < 20 ? $data : substr($data, 20, $this->uint32($data, 16));
        if (JetFormat::TYPE_MEMO === $column->type) {
            return ValueCodec::decodeText($unwrap($this->readLongValue($bytes)));
        }
        $data = $unwrap($bytes);
        if ('' === $data) {
            return null;
        }
        switch ($column->type) {
            case JetFormat::TYPE_BOOLEAN:
                return "\0" !== $data[0];
            case JetFormat::TYPE_NUMERIC:
                // Total length (0: the rest), scale, sign, then the value without its leading zero words:
                // a first word of 4 bytes when needed, then words of 8 bytes, each little-endian
                $length = unpack('v', $data)[1] ?? 0;
                $length = min(16, intdiv((0 < $length ? $length : strlen($data) - 2) - 2, 4) * 4);
                $value = substr($data, 4, $length);
                $bigEndian = '';
                $position = 0;
                if (0 !== strlen($value) % 8) {
                    $bigEndian .= strrev(substr($value, 0, 4));
                    $position = 4;
                }
                for (; $position < strlen($value); $position += 8) {
                    $bigEndian .= strrev(substr($value, $position, 8));
                }
                // Regular NUMERIC layout: sign byte, then 4 little-endian words, the most significant first
                $bigEndian = str_pad($bigEndian, 16, "\0", STR_PAD_LEFT);
                $regular = ("\0" !== ($data[3] ?? "\0") ? "\x80" : "\0");
                foreach (str_split($bigEndian, 4) as $word) {
                    $regular .= strrev($word);
                }

                return ValueCodec::decodeNumeric($regular, ord($data[2] ?? "\0"));
            default:
                return $this->decodeValue($column, $data);
        }
    }

    private function decodeValue(ColumnDefinition $column, string $bytes): mixed
    {
        switch ($column->type) {
            case JetFormat::TYPE_BYTE:
                return ord($bytes[0] ?? "\0");
            case JetFormat::TYPE_INT:
                $unpacked = unpack('v', $bytes);
                $value = false === $unpacked ? 0 : (int) $unpacked[1];

                return $value >= 0x8000 ? $value - 0x10000 : $value;
            case JetFormat::TYPE_LONG:
            case JetFormat::TYPE_COMPLEX:
                $unpacked = unpack('V', $bytes);
                $value = false === $unpacked ? 0 : (int) $unpacked[1];

                return $value >= 0x80000000 ? $value - 0x100000000 : $value;
            case JetFormat::TYPE_BIG_INT:
                $unpacked = unpack('P', $bytes);

                return false === $unpacked ? 0 : (int) $unpacked[1];
            case JetFormat::TYPE_MONEY:
                return ValueCodec::decodeMoney($bytes);
            case JetFormat::TYPE_FLOAT:
                $unpacked = unpack('g', $bytes);

                return false === $unpacked ? 0.0 : (float) $unpacked[1];
            case JetFormat::TYPE_DOUBLE:
                $unpacked = unpack('e', $bytes);

                return false === $unpacked ? 0.0 : (float) $unpacked[1];
            case JetFormat::TYPE_DATETIME:
                $unpacked = unpack('e', $bytes);

                return ValueCodec::decodeDate(false === $unpacked ? 0.0 : (float) $unpacked[1]);
            case JetFormat::TYPE_EXT_DATETIME:
                return ValueCodec::decodeExtendedDate($bytes);
            case JetFormat::TYPE_GUID:
                return ValueCodec::decodeGuid($bytes);
            case JetFormat::TYPE_NUMERIC:
                return ValueCodec::decodeNumeric($bytes, $column->scale);
            case JetFormat::TYPE_TEXT:
                return ValueCodec::decodeText($bytes);
            case JetFormat::TYPE_MEMO:
                return ValueCodec::decodeText($this->readLongValue($bytes));
            case JetFormat::TYPE_OLE:
                return $this->readLongValue($bytes);
            default:
                return $bytes;
        }
    }

    /**
     * Memo/OLE value: 12 bytes header (length + flags, row pointer, unused) then inline data,
     * or a single LVAL row, or a chain of LVAL rows.
     */
    private function readLongValue(string $bytes): string
    {
        if (strlen($bytes) < 12) {
            return '';
        }
        $lengthAndFlags = $this->uint32($bytes, 0);
        $length = $lengthAndFlags & 0x00FFFFFF;
        $flags = $lengthAndFlags >> 24;
        if (0x80 === $flags) {
            return substr($bytes, 12, $length);
        }
        $pointer = $this->uint32($bytes, 4);
        if (0x40 === $flags) {
            return substr($this->readRowData($this->readPage($pointer >> 8), $pointer & 0xff), 0, $length);
        }
        $result = '';
        $guard = 0;
        while (0 !== $pointer && strlen($result) < $length) {
            if (++$guard > 100000) {
                throw new ReaderException('Access: loop in a long value');
            }
            $row = $this->readRowData($this->readPage($pointer >> 8), $pointer & 0xff);
            $pointer = $this->uint32($row, 0);
            $result .= substr($row, 4);
        }

        return substr($result, 0, $length);
    }

    /**
     * Content of the row of a usage map.
     */
    public function readUsageMap(int $pointer): string
    {
        return $this->readRowData($this->readPage($pointer >> 8), $pointer & 0xff);
    }

    private function readRowData(string $page, int $row): string
    {
        $rowCount = $this->uint16($page, JetFormat::OFFSET_DATA_NUM_ROWS);
        if ($row >= $rowCount) {
            throw new ReaderException('Access: invalid row pointer');
        }
        $start = $this->uint16($page, JetFormat::OFFSET_DATA_ROW_START + $row * 2) & JetFormat::ROW_OFFSET_MASK;
        $end = 0 === $row ? JetFormat::PAGE_SIZE : $this->uint16($page, JetFormat::OFFSET_DATA_ROW_START + ($row - 1) * 2) & JetFormat::ROW_OFFSET_MASK;

        return substr($page, $start, max(0, $end - $start));
    }

    /**
     * Pages owned by a table, from its usage map (inline bitmap or reference map).
     *
     * @return array<int, int>
     */
    private function getOwnedDataPages(TableDefinition $definition): array
    {
        return $this->getUsageMapPages($definition->ownedPages);
    }

    /**
     * Pages of a usage map (inline bitmap or reference map).
     *
     * @return array<int, int>
     */
    public function getUsageMapPages(int $pointer): array
    {
        $map = $this->readUsageMap($pointer);
        $pages = [];
        $type = ord($map[0] ?? "\0");
        if (0 === $type) {
            $start = $this->uint32($map, 1);
            $this->addBitmapPages($pages, substr($map, 5), $start);
        } elseif (1 === $type) {
            $bitsPerPage = (JetFormat::PAGE_SIZE - 4) * 8;
            for ($i = 0, $count = intdiv(strlen($map) - 1, 4); $i < $count; ++$i) {
                $mapPage = $this->uint32($map, 1 + $i * 4);
                if (0 === $mapPage) {
                    continue;
                }
                $this->addBitmapPages($pages, substr($this->readPage($mapPage), 4), $i * $bitsPerPage);
            }
        } else {
            throw new ReaderException(sprintf('Access: unknown usage map type %d', $type));
        }

        return $pages;
    }

    /**
     * @param array<int, int> $pages
     */
    private function addBitmapPages(array &$pages, string $bitmap, int $start): void
    {
        $pageCount = intdiv(strlen($this->data), JetFormat::PAGE_SIZE);
        for ($i = 0, $length = strlen($bitmap); $i < $length; ++$i) {
            $byte = ord($bitmap[$i]);
            if (0 === $byte) {
                continue;
            }
            for ($bit = 0; $bit < 8; ++$bit) {
                if ($byte & (1 << $bit)) {
                    $page = $start + $i * 8 + $bit;
                    if ($page < $pageCount) {
                        $pages[] = $page;
                    }
                }
            }
        }
    }

    private function readTableDefinitionData(int $page): string
    {
        $content = $this->readPage($page);
        if (JetFormat::PAGE_TABLE_DEFINITION !== ord($content[0])) {
            throw new ReaderException(sprintf('Access: page %d is not a table definition', $page));
        }
        $data = $content;
        $next = $this->uint32($content, JetFormat::OFFSET_NEXT_TDEF_PAGE);
        $guard = 0;
        while (0 !== $next) {
            if (++$guard > 1000) {
                throw new ReaderException('Access: loop in a table definition');
            }
            $content = $this->readPage($next);
            $data .= substr($content, 8);
            $next = $this->uint32($content, JetFormat::OFFSET_NEXT_TDEF_PAGE);
        }

        return $data;
    }

    public function readPage(int $page): string
    {
        if (isset($this->decodedPages[$page])) {
            return $this->decodedPages[$page];
        }
        $offset = $page * JetFormat::PAGE_SIZE;
        if ($page <= 0 || $offset + JetFormat::PAGE_SIZE > strlen($this->data)) {
            throw new ReaderException(sprintf('Access: invalid page %d', $page));
        }
        $content = substr($this->data, $offset, JetFormat::PAGE_SIZE);
        if (0 !== $this->encodingKey) {
            $content = JetFormat::rc4(pack('V', $this->encodingKey ^ $page), $content);
            if (count($this->decodedPages) > 256) {
                $this->decodedPages = [];
            }
            $this->decodedPages[$page] = $content;
        }

        return $content;
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function readName(string $data, int $offset): array
    {
        $length = $this->uint16($data, $offset);

        return [ValueCodec::decodeText(substr($data, $offset + 2, $length)), $offset + 2 + $length];
    }

    private function uint16(string $data, int $offset): int
    {
        return isset($data[$offset + 1]) ? ord($data[$offset]) | (ord($data[$offset + 1]) << 8) : 0;
    }

    private function uint32(string $data, int $offset): int
    {
        if (!isset($data[$offset + 3])) {
            return 0;
        }
        $unpacked = unpack('V', $data, $offset);

        return false === $unpacked ? 0 : (int) $unpacked[1];
    }
}
