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

namespace PhpOffice\PhpDatabase\Tests;

use PhpOffice\PhpDatabase\Exception\InvalidClassException;
use PhpOffice\PhpDatabase\Exception\InvalidFileFormatException;
use PhpOffice\PhpDatabase\IOFactory;
use PhpOffice\PhpDatabase\Reader;
use PhpOffice\PhpDatabase\Writer;

class IOFactoryTest extends AbstractTestCase
{
    public function testCreate(): void
    {
        $database = $this->createSampleDatabase(1);
        self::assertInstanceOf(Writer\Sqlite::class, IOFactory::createWriter($database));
        self::assertInstanceOf(Writer\Odb::class, IOFactory::createWriter($database, 'Odb'));
        self::assertInstanceOf(Writer\Accdb::class, IOFactory::createWriter($database, 'Accdb'));
        self::assertInstanceOf(Reader\Accdb::class, IOFactory::createReader('Accdb'));
        self::assertSame('Accdb', IOFactory::getFormatFromFilename('file.MDB'));
        self::assertSame('Sqlite', IOFactory::getFormatFromFilename('file.db'));
        self::assertNull(IOFactory::getFormatFromFilename('file.txt'));
    }

    public function testInvalidClass(): void
    {
        $this->expectException(InvalidClassException::class);
        IOFactory::createReader('Csv');
    }

    public function testLoadDetectsTheFormatFromTheContent(): void
    {
        $database = $this->createSampleDatabase(3);
        foreach (['Sqlite', 'Odb', 'Accdb'] as $format) {
            $file = $this->getTemporaryFile('bin');
            IOFactory::createWriter($database, $format)->save($file);
            $loaded = IOFactory::load($file);
            self::assertSame(3, $loaded->getTable('Clients é')?->getRowCount(), $format);
        }
    }

    public function testLoadUnknownFile(): void
    {
        $file = $this->getTemporaryFile('txt');
        file_put_contents($file, 'Hello');
        $this->expectException(InvalidFileFormatException::class);
        IOFactory::load($file);
    }

    public function testConvert(): void
    {
        $source = $this->createSampleDatabase(10);
        $sqlite = $this->getTemporaryFile('sqlite');
        $odb = $this->getTemporaryFile('odb');
        $accdb = $this->getTemporaryFile('accdb');
        IOFactory::save($source, $sqlite);
        IOFactory::save(IOFactory::load($sqlite), $odb);
        IOFactory::save(IOFactory::load($odb), $accdb);
        $result = IOFactory::load($accdb)->getTable('Clients é');
        self::assertNotNull($result);
        $expected = $source->getTable('Clients é')?->getRows() ?? [];
        foreach ($result->getRows() as $index => $row) {
            self::assertSame($expected[$index]['Name'], $row['Name']);
            self::assertSame($expected[$index]['Price'], $row['Price']);
            self::assertSame($expected[$index]['Notes'], $row['Notes']);
            self::assertSame($expected[$index]['Photo'], $row['Photo']);
            self::assertSame($expected[$index]['Born']->format('Y-m-d'), $row['Born']?->format('Y-m-d'));
        }
    }
}
