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

namespace PhpOffice\PhpDatabase\Tests\Shared;

use DateTimeImmutable;
use PhpOffice\PhpDatabase\Reader\Sqlite\Wal;
use PhpOffice\PhpDatabase\Shared\Access\AccessFile;
use PhpOffice\PhpDatabase\Shared\Access\ColumnDefinition;
use PhpOffice\PhpDatabase\Shared\Access\JetFormat;
use PhpOffice\PhpDatabase\Shared\Access\PropertyMap;
use PhpOffice\PhpDatabase\Shared\Access\ValueCodec;
use PhpOffice\PhpDatabase\Shared\Sql\Parser;
use PhpOffice\PhpDatabase\Tests\AbstractTestCase;
use PhpOffice\PhpDatabase\Writer\Accdb\IndexKey;
use PhpOffice\PhpDatabase\Writer\Accdb\TextIndexCodes;

class AccessTest extends AbstractTestCase
{
    public function testTextIndexCodes(): void
    {
        $legacy = TextIndexCodes::getInstance(TextIndexCodes::GENERAL_LEGACY);
        self::assertSame('4a4c4d0100', bin2hex($legacy->encode('abc')));
        // Trailing spaces are ignored
        self::assertSame($legacy->encode('abc'), $legacy->encode('abc  '));
        // Descending: inverted bytes
        self::assertGreaterThan(0, strcmp($legacy->encode('abc', false), $legacy->encode('abd', false)));
        $general = TextIndexCodes::getInstance(TextIndexCodes::GENERAL);
        self::assertNotSame('', $general->encode('École 日本'));
        self::assertLessThan(0, strcmp($general->encode('abc'), $general->encode('abd')));
        self::assertLessThan(0, strcmp($general->encode('ab'), $general->encode('abc')));
    }

    /**
     * The keys of the index "ParentIdName" of MSysObjects, written by Access, are computed again.
     */
    public function testIndexKeysOfAccessFile(): void
    {
        $file = new AccessFile((string) file_get_contents($this->getResourceFile('jackcess-2007.accdb')));
        $catalog = $file->readTableDefinition(JetFormat::TDEF_PAGE_MSYS_OBJECTS);
        $index = $catalog->realIndexes[0];
        $columns = [];
        foreach ($catalog->columns as $column) {
            $columns[$column->number] = $column;
        }
        self::assertSame(['ParentId', 'Name'], array_map(static fn (int $number): string => $columns[$number]->name, array_keys($index['columns'])));
        $keys = [];
        foreach ($file->readRows($catalog) as $pointer => $row) {
            $key = '';
            foreach ($index['columns'] as $number => $ascending) {
                $key .= IndexKey::encode($columns[$number], $ascending, $row[$columns[$number]->name], TextIndexCodes::GENERAL_LEGACY, false);
            }
            $keys[] = $key . substr(pack('N', $pointer >> 8), 1) . chr($pointer & 0xFF);
        }
        $page = $file->readPage($index['root']);
        self::assertSame(JetFormat::PAGE_INDEX_LEAF, ord($page[0]));
        // First entry of the leaf (sorted keys)
        sort($keys, SORT_STRING);
        self::assertSame(bin2hex($keys[0]), bin2hex(substr($page, 480, strlen($keys[0]))));
    }

    public function testIndexKey(): void
    {
        $long = new ColumnDefinition();
        $long->type = JetFormat::TYPE_LONG;
        self::assertSame('7f80000001', bin2hex(IndexKey::encode($long, true, 1, 0, false)));
        self::assertSame('7f7fffffff', bin2hex(IndexKey::encode($long, true, -1, 0, false)));
        self::assertSame('00', bin2hex(IndexKey::encode($long, true, null, 0, false)));
        self::assertSame('ff', bin2hex(IndexKey::encode($long, false, null, 0, false)));
        $binary = new ColumnDefinition();
        $binary->type = JetFormat::TYPE_BINARY;
        self::assertSame('7f' . '0102030405060708' . '09' . '0900000000000000' . '01', bin2hex(IndexKey::encode($binary, true, "\x01\x02\x03\x04\x05\x06\x07\x08\x09", 0, false)));
    }

    public function testPropertyMap(): void
    {
        $map = new PropertyMap();
        $map->set('', 'Description', JetFormat::TYPE_MEMO, 'Table é');
        $map->set('Name', 'Required', JetFormat::TYPE_BOOLEAN, true, true);
        $map->set('Name', 'DefaultValue', JetFormat::TYPE_MEMO, '"x"', true);
        $map->set('Other', 'Description', JetFormat::TYPE_TEXT, 'Other column');
        $data = $map->write();
        self::assertStringStartsWith(PropertyMap::SIGNATURE, $data);
        $parsed = PropertyMap::parse($data);
        self::assertSame('Table é', $parsed->get('', 'Description'));
        self::assertTrue($parsed->get('name', 'required'));
        self::assertSame('"x"', $parsed->get('Name', 'DefaultValue'));
        self::assertSame('Other column', $parsed->get('Other', 'Description'));
        self::assertNull($parsed->get('Other', 'Required'));
        self::assertSame($data, $parsed->write());
        self::assertTrue(PropertyMap::parse('invalid')->isEmpty());
    }

    public function testAttachment(): void
    {
        foreach (['txt', 'jpg'] as $type) {
            $bytes = ValueCodec::encodeAttachment($type, str_repeat('data ', 100));
            // Compressed unless the format is already compressed
            self::assertSame('jpg' === $type ? 0 : 1, unpack('V', $bytes)[1] ?? null);
            self::assertSame(['type' => $type, 'data' => str_repeat('data ', 100)], ValueCodec::decodeAttachment($bytes));
        }
    }

    public function testExtendedDate(): void
    {
        $date = new DateTimeImmutable('2024-02-29 13:45:07.123456');
        $bytes = ValueCodec::encodeExtendedDate($date);
        self::assertSame(42, strlen($bytes));
        self::assertSame('0000000000000738944:0000000495071234560:7', rtrim($bytes, "\0"));
        self::assertEquals($date, ValueCodec::decodeExtendedDate($bytes));
    }

    public function testWal(): void
    {
        self::assertSame('db', Wal::apply('db', ''));
        self::assertSame('db', Wal::apply('db', str_repeat("\x37", 100)));
    }

    public function testSqlConstraints(): void
    {
        $parser = new Parser();
        $result = $parser->parseCreateTable('CREATE TABLE t (id INTEGER PRIMARY KEY, a TEXT UNIQUE, b INT CONSTRAINT fk_b REFERENCES p (x) ON DELETE SET NULL NOT NULL, '
            . 'c INT, d INT, CONSTRAINT uq_cd UNIQUE (c, d), FOREIGN KEY (c, d) REFERENCES q ON UPDATE CASCADE DEFERRABLE INITIALLY DEFERRED)');
        $table = $result['table'];
        self::assertFalse($table->getColumn('b')?->isNullable());
        self::assertSame(['a'], $table->getIndex('t_a_key')?->getColumns());
        self::assertSame(['c', 'd'], $table->getIndex('uq_cd')?->getColumns());
        self::assertTrue($table->getIndex('uq_cd')->isUnique());
        self::assertCount(2, $result['foreignKeys']);
        self::assertSame(['name' => 'fk_b', 'columns' => ['b'], 'table' => 'p', 'referencedColumns' => ['x'], 'onDelete' => 'SET NULL', 'onUpdate' => 'NO ACTION'], $result['foreignKeys'][0]);
        self::assertSame(['c', 'd'], $result['foreignKeys'][1]['columns']);
        self::assertSame('CASCADE', $result['foreignKeys'][1]['onUpdate']);

        $index = $parser->parseCreateIndex('CREATE UNIQUE INDEX IF NOT EXISTS main.i ON t (a COLLATE NOCASE DESC, b) WHERE a > 1');
        self::assertSame(['name' => 'i', 'table' => 't', 'columns' => ['a', 'b'], 'descending' => [true, false], 'unique' => true, 'where' => 'a > 1', 'partial' => true], $index);
        $alter = $parser->parseAlterTable('ALTER TABLE "B" ADD CONSTRAINT "F" FOREIGN KEY("X") REFERENCES "A"("ID") ON DELETE CASCADE');
        self::assertSame('B', $alter['table']);
        self::assertSame('CASCADE', $alter['foreignKeys'][0]['onDelete']);
    }
}
