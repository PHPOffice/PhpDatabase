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
use PhpOffice\PhpDatabase\Reader\Odb\DataFile;
use PhpOffice\PhpDatabase\Shared\Access\ValueCodec;
use PhpOffice\PhpDatabase\Shared\Sql\Parser;
use PhpOffice\PhpDatabase\Shared\Sql\Tokenizer;
use PhpOffice\PhpDatabase\Shared\Sqlite\Blob;
use PhpOffice\PhpDatabase\Shared\Sqlite\Record;
use PhpOffice\PhpDatabase\Tests\AbstractTestCase;
use PhpOffice\PhpDatabase\Writer\Odb\ScriptWriter;

class SharedTest extends AbstractTestCase
{
    public function testVarint(): void
    {
        foreach ([0, 1, 127, 128, 240, 2287, 16383, 16384, 0xFFFFFF, PHP_INT_MAX, -1, PHP_INT_MIN] as $value) {
            self::assertSame([$value, strlen(Record::writeVarint($value))], Record::readVarint(Record::writeVarint($value), 0), (string) $value);
        }
        self::assertSame(9, strlen(Record::writeVarint(-1)));
    }

    public function testRecord(): void
    {
        $values = [null, 0, 1, -1, 300, -70000, 0x7FFFFFFF, 0x7FFFFFFFFF, PHP_INT_MIN, 1.5, 'texte é', new Blob("\x00\xff")];
        self::assertEquals($values, Record::decode(Record::encode($values)));
        self::assertLessThan(0, Record::compareRecords([null], [0]));
        self::assertLessThan(0, Record::compareRecords([10], ['1']));
        self::assertLessThan(0, Record::compareRecords(['b'], [new Blob('a')]));
        self::assertLessThan(0, Record::compareRecords(['B', 1], ['a', 0]));
    }

    public function testSqlParser(): void
    {
        $parser = new Parser();
        $result = $parser->parseCreateTable('CREATE TABLE IF NOT EXISTS main."My table" (id INTEGER PRIMARY KEY, [name] VARCHAR(20) NOT NULL DEFAULT \'x\', '
            . 'price NUMERIC(8, 3) CHECK (price > 0), ref INT REFERENCES other(id) ON DELETE CASCADE, CONSTRAINT u UNIQUE (name)) -- comment');
        $table = $result['table'];
        self::assertSame('My table', $table->getName());
        self::assertSame(['id', 'name', 'price', 'ref'], $table->getColumnNames());
        self::assertSame('id', $result['rowidColumn']);
        self::assertSame(20, $table->getColumn('name')?->getLength());
        self::assertSame('x', $table->getColumn('name')->getDefaultValue());
        self::assertSame(3, $table->getColumn('price')?->getScale());
        self::assertFalse($result['withoutRowid']);

        $insert = (new Parser(true))->parseInsert("INSERT INTO \"T\" VALUES(1,'l\\u00e9''s',NULL,TRUE,-2.50,1.5E0,X'0aff')");
        self::assertSame([1, "lé's", null, true, '-2.50', 1.5, "\x0a\xff"], $insert['values']);
    }

    public function testHsqldbEscape(): void
    {
        $value = "Né\\u0041 😀\n";
        $escaped = ScriptWriter::escape($value);
        self::assertMatchesRegularExpression('/^[\x20-\x7e]+$/', $escaped);
        self::assertSame($value, Tokenizer::decodeUnicodeEscapes($escaped));
    }

    public function testBigDecimal(): void
    {
        self::assertSame('-0.25', DataFile::bigDecimal("\xe7", 2));
        self::assertSame('12345678901234567890.123', DataFile::bigDecimal((string) hex2bin('029d42b64e76714244cb'), 3));
        self::assertSame('256', DataFile::bigDecimal("\x01\x00", 0));
        self::assertSame('-129', DataFile::bigDecimal("\xff\x7f", 0));
    }

    public function testAccessValues(): void
    {
        self::assertSame("\xff\xfeabc", ValueCodec::encodeText('abc'));
        self::assertSame('abc€é', ValueCodec::decodeText(ValueCodec::encodeText('abc€é')));
        self::assertSame('abcé', ValueCodec::decodeText(ValueCodec::encodeText('abcé')));
        self::assertSame('-1234.5600', ValueCodec::decodeMoney(ValueCodec::encodeMoney('-1234.56')));
        self::assertSame('-79228162514264337593543950.335', ValueCodec::decodeNumeric(ValueCodec::encodeNumeric('-79228162514264337593543950.335', 3), 3));
        $guid = '{4DBF0C8A-1D4E-4B39-9D5B-0123456789AB}';
        self::assertSame($guid, ValueCodec::decodeGuid((string) ValueCodec::encodeGuid($guid)));
        $date = new DateTimeImmutable('1899-12-29 06:00:00');
        self::assertSame(-1.25, ValueCodec::encodeDate($date));
        self::assertSame('1899-12-29 06:00:00', ValueCodec::decodeDate(-1.25, $date->getTimezone())->format('Y-m-d H:i:s'));
    }
}
