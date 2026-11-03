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

namespace PhpOffice\PhpDatabase;

use PhpOffice\PhpDatabase\Exception\InvalidClassException;
use PhpOffice\PhpDatabase\Exception\InvalidFileFormatException;
use PhpOffice\PhpDatabase\Reader\ReaderInterface;
use PhpOffice\PhpDatabase\Writer\WriterInterface;
use ReflectionClass;

/**
 * IOFactory.
 */
class IOFactory
{
    /**
     * Autoresolve classes.
     *
     * @var array<int, string>
     */
    private static $autoResolveClasses = ['Sqlite', 'Accdb', 'Odb'];

    /**
     * Writer/reader name by file extension.
     *
     * @var array<string, string>
     */
    private static $extensions = [
        'accdb' => 'Accdb',
        'mdb' => 'Accdb',
        'odb' => 'Odb',
        'sqlite' => 'Sqlite',
        'sqlite3' => 'Sqlite',
        'db' => 'Sqlite',
        'db3' => 'Sqlite',
    ];

    /**
     * Create writer.
     */
    public static function createWriter(Database $database, string $name = 'Sqlite'): WriterInterface
    {
        /** @var WriterInterface $writer */
        $writer = self::loadClass('PhpOffice\\PhpDatabase\\Writer\\' . $name, 'Writer', $database);

        return $writer;
    }

    /**
     * Create reader.
     */
    public static function createReader(string $name): ReaderInterface
    {
        /** @var ReaderInterface $reader */
        $reader = self::loadClass('PhpOffice\\PhpDatabase\\Reader\\' . $name, 'Reader');

        return $reader;
    }

    /**
     * Name of the reader/writer for a filename, based on its extension.
     */
    public static function getFormatFromFilename(string $filename): ?string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return self::$extensions[$extension] ?? null;
    }

    /**
     * Loads Database from file using automatic ReaderInterface resolution.
     */
    public static function load(string $filename, int $flags = 0): Database
    {
        $candidates = self::$autoResolveClasses;
        $format = self::getFormatFromFilename($filename);
        if (null !== $format) {
            array_unshift($candidates, $format);
            $candidates = array_values(array_unique($candidates));
        }
        foreach ($candidates as $autoResolveClass) {
            $reader = self::createReader($autoResolveClass);
            if ($reader->canRead($filename)) {
                return $reader->load($filename, $flags);
            }
        }

        throw new InvalidFileFormatException($filename, self::class, 'Could not automatically determine the good ' . ReaderInterface::class);
    }

    /**
     * Save a database, the writer being chosen from the extension of the filename.
     */
    public static function save(Database $database, string $filename, ?string $name = null): void
    {
        $name ??= self::getFormatFromFilename($filename);
        if (null === $name) {
            throw new InvalidFileFormatException($filename, self::class, 'Unknown extension');
        }
        self::createWriter($database, $name)->save($filename);
    }

    private static function loadClass(string $class, string $type, ?Database $database = null): object
    {
        if (!class_exists($class)) {
            throw new InvalidClassException($class, $type . ': The class doesn\'t exist');
        }
        $reflection = new ReflectionClass($class);
        if ($reflection->isAbstract() || $reflection->isInterface()) {
            throw new InvalidClassException($class, $type . ': The class is an abstract class or an interface');
        }
        if (null === $database) {
            return new $class();
        }

        return new $class($database);
    }
}
