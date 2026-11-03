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
use DOMDocument;
use DOMElement;
use DOMXPath;
use PclZip;
use PhpOffice\PhpDatabase\Database;
use PhpOffice\PhpDatabase\Exception\FeatureNotImplementedException;
use PhpOffice\PhpDatabase\Exception\InvalidFileFormatException;
use PhpOffice\PhpDatabase\Exception\ReaderException;
use PhpOffice\PhpDatabase\Reader\Odb\ScriptReader;
use PhpOffice\PhpDatabase\Shared\Sql\Dialect;
use PhpOffice\PhpDatabase\View;
use Throwable;

/**
 * Reader for OpenDocument Database files (.odb, LibreOffice Base) with an embedded HSQLDB database.
 */
class Odb extends AbstractReader
{
    public const MIMETYPE = 'application/vnd.oasis.opendocument.base';

    private const NS_XLINK = 'http://www.w3.org/1999/xlink';
    private const NS_DB = 'urn:oasis:names:tc:opendocument:xmlns:database:1.0';

    public function canRead(string $filename): bool
    {
        $signature = $this->readSignature($filename, 128);
        if (!str_starts_with($signature, "PK\x03\x04")) {
            return false;
        }
        // "mimetype" is the first entry, stored
        if (str_contains($signature, 'mimetype' . self::MIMETYPE)) {
            return true;
        }

        try {
            return self::MIMETYPE === trim($this->readZip($filename)['mimetype'] ?? '');
        } catch (Throwable $exception) {
            return false;
        }
    }

    public function load(string $filename, int $flags = 0): Database
    {
        $this->flags = $flags;
        $this->assertReadable($filename);
        $zip = $this->readZip($filename);
        $content = $zip['content.xml'] ?? null;
        if (null === $content) {
            throw new InvalidFileFormatException($filename, self::class, 'content.xml is missing');
        }

        $database = new Database();
        $database->getDocumentProperties()->setTitle(pathinfo($filename, PATHINFO_FILENAME));
        $this->loadMeta($database, $zip['meta.xml'] ?? null);

        $href = $this->getConnectionResource($content);
        if ('sdbc:embedded:firebird' === $href || isset($zip['database/firebird.fbk'])) {
            throw new FeatureNotImplementedException('ODB files with an embedded Firebird database are not supported (only HSQLDB)');
        }
        if ('sdbc:embedded:hsqldb' !== $href && !isset($zip['database/script'])) {
            // External data source: nothing is stored in the file
            $database->getDocumentProperties()->setDescription('External data source: ' . $href);

            return $database;
        }
        $script = $zip['database/script'] ?? null;
        if (null === $script) {
            // New database never saved with tables
            return $database;
        }
        $properties = ScriptReader::parseProperties((string) ($zip['database/properties'] ?? ''));
        $hsqldb = (new ScriptReader())->read($script, $zip['database/data'] ?? null, $properties, $this->skipRows(), $zip['database/log'] ?? null);
        foreach ($hsqldb->getTables() as $table) {
            $database->addTable($table);
        }
        foreach ($hsqldb->getViews() as $view) {
            $database->addView($view);
        }
        $this->loadQueries($database, $content);

        return $database;
    }

    /**
     * Entries of the ZIP file by name, read with PclZip (the ZIP library of phpoffice/common).
     *
     * @return array<string, string>
     */
    private function readZip(string $filename): array
    {
        $entries = (new PclZip($filename))->extract(PCLZIP_OPT_EXTRACT_AS_STRING);
        if (!is_array($entries)) {
            throw new ReaderException(sprintf('Unable to read the ZIP file "%s"', $filename));
        }
        $contents = [];
        foreach ($entries as $entry) {
            if (empty($entry['folder'])) {
                $contents[$entry['filename']] = $entry['content'];
            }
        }

        return $contents;
    }

    /**
     * Queries of LibreOffice Base (db:query in content.xml), read as views.
     */
    private function loadQueries(Database $database, string $content): void
    {
        $document = new DOMDocument();
        if (!@$document->loadXML($content, LIBXML_NONET)) {
            return;
        }
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('db', self::NS_DB);
        foreach ($xpath->query('//db:queries//db:query') ?: [] as $query) {
            if (!$query instanceof DOMElement) {
                continue;
            }
            $name = $query->getAttributeNS(self::NS_DB, 'name');
            $command = $query->getAttributeNS(self::NS_DB, 'command');
            if ('' === $name || '' === trim($command) || $database->hasTable($name) || null !== $database->getView($name)) {
                continue;
            }
            $database->addView(new View($name, Dialect::convert($command, Dialect::HSQLDB, Dialect::STANDARD)));
        }
    }

    private function getConnectionResource(string $content): string
    {
        $document = new DOMDocument();
        if (!@$document->loadXML($content, LIBXML_NONET)) {
            throw new ReaderException('ODB: invalid content.xml');
        }
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('db', self::NS_DB);
        $xpath->registerNamespace('xlink', self::NS_XLINK);
        $nodes = $xpath->query('//db:connection-resource/@xlink:href');
        if (false === $nodes || 0 === $nodes->length) {
            // ODF 1.0/1.1: db:connection-resource may be missing for embedded databases
            return '';
        }

        return (string) $nodes->item(0)?->nodeValue;
    }

    private function loadMeta(Database $database, ?string $meta): void
    {
        if (null === $meta) {
            return;
        }
        $document = new DOMDocument();
        if (!@$document->loadXML($meta, LIBXML_NONET)) {
            return;
        }
        $properties = $database->getDocumentProperties();
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('dc', 'http://purl.org/dc/elements/1.1/');
        $xpath->registerNamespace('meta', 'urn:oasis:names:tc:opendocument:xmlns:meta:1.0');
        $value = static function (string $query) use ($xpath): ?string {
            $nodes = $xpath->query($query);

            return false !== $nodes && $nodes->length > 0 ? trim((string) $nodes->item(0)?->textContent) : null;
        };
        if (null !== ($title = $value('//dc:title')) && '' !== $title) {
            $properties->setTitle($title);
        }
        if (null !== ($description = $value('//dc:description'))) {
            $properties->setDescription($description);
        }
        if (null !== ($creator = $value('//meta:initial-creator') ?? $value('//dc:creator'))) {
            $properties->setCreator($creator);
        }

        try {
            if (null !== ($created = $value('//meta:creation-date'))) {
                $properties->setCreated(new DateTimeImmutable($created));
            }
            if (null !== ($modified = $value('//dc:date'))) {
                $properties->setModified(new DateTimeImmutable($modified));
            }
        } catch (Throwable $exception) {
        }
    }
}
