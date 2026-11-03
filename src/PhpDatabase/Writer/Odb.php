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

use PhpOffice\Common\Adapter\Zip\PclZipAdapter;
use PhpOffice\PhpDatabase\Exception\WriterException;
use PhpOffice\PhpDatabase\Reader\Odb as OdbReader;
use PhpOffice\PhpDatabase\Writer\Odb\ScriptWriter;

/**
 * Writer for OpenDocument Database files (.odb, LibreOffice Base) with an embedded HSQLDB 1.8 database.
 */
class Odb extends AbstractWriter
{
    public function save(string $filename): void
    {
        $this->writeFile($filename, $this->getContent());
    }

    public function getContent(): string
    {
        $database = $this->getDatabaseOrFail();
        $scriptWriter = new ScriptWriter();
        // PclZip (the ZIP adapter of phpoffice/common) writes to a file
        $filename = tempnam(sys_get_temp_dir(), 'PhpDatabase');
        if (false === $filename) {
            throw new WriterException('Unable to create a temporary file');
        }

        try {
            $zip = (new PclZipAdapter())->open($filename);
            // "mimetype" is the first entry, stored
            $zip->addFromString('mimetype', OdbReader::MIMETYPE, false);
            $zip->addFromString('database/script', $scriptWriter->getScript($database));
            $zip->addFromString('database/properties', $scriptWriter->getProperties());
            $zip->addFromString('content.xml', $this->getContentXml());
            $zip->addFromString('settings.xml', $this->getSettingsXml());
            $zip->addFromString('meta.xml', $this->getMetaXml());
            $zip->addFromString('META-INF/manifest.xml', $this->getManifestXml());
            $zip->close();
            $this->warnings = $scriptWriter->getWarnings();

            return (string) file_get_contents($filename);
        } finally {
            @unlink($filename);
        }
    }

    private function getContentXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
            . ' xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0"'
            . ' xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"'
            . ' xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0"'
            . ' xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0"'
            . ' xmlns:xlink="http://www.w3.org/1999/xlink"'
            . ' xmlns:dc="http://purl.org/dc/elements/1.1/"'
            . ' xmlns:number="urn:oasis:names:tc:opendocument:xmlns:datastyle:1.0"'
            . ' xmlns:svg="urn:oasis:names:tc:opendocument:xmlns:svg-compatible:1.0"'
            . ' xmlns:form="urn:oasis:names:tc:opendocument:xmlns:form:1.0"'
            . ' xmlns:config="urn:oasis:names:tc:opendocument:xmlns:config:1.0"'
            . ' xmlns:db="urn:oasis:names:tc:opendocument:xmlns:database:1.0"'
            . ' office:version="1.2">'
            . '<office:scripts/><office:font-face-decls/><office:automatic-styles/>'
            . '<office:body><office:database><db:data-source>'
            . '<db:connection-data>'
            . '<db:connection-resource xlink:href="sdbc:embedded:hsqldb" xlink:type="simple"/>'
            . '<db:login db:is-password-required="false"/>'
            . '</db:connection-data>'
            . '<db:driver-settings db:system-driver-settings="" db:base-dn="" db:parameter-name-substitution="false"/>'
            . '<db:application-connection-settings db:is-table-name-length-limited="false" db:append-table-alias-name="false" db:max-row-count="100">'
            . '<db:table-filter><db:table-include-filter><db:table-filter-pattern>%</db:table-filter-pattern></db:table-include-filter></db:table-filter>'
            . '</db:application-connection-settings>'
            . '</db:data-source></office:database></office:body>'
            . '</office:document-content>';
    }

    private function getSettingsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<office:document-settings xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
            . ' xmlns:config="urn:oasis:names:tc:opendocument:xmlns:config:1.0" office:version="1.2">'
            . '<office:settings/></office:document-settings>';
    }

    private function getMetaXml(): string
    {
        $properties = $this->getDatabaseOrFail()->getDocumentProperties();
        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<office:document-meta xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
            . ' xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:meta="urn:oasis:names:tc:opendocument:xmlns:meta:1.0"'
            . ' office:version="1.2"><office:meta>'
            . '<meta:generator>PhpOffice/PhpDatabase</meta:generator>'
            . ('' !== $properties->getTitle() ? '<dc:title>' . $escape($properties->getTitle()) . '</dc:title>' : '')
            . ('' !== $properties->getDescription() ? '<dc:description>' . $escape($properties->getDescription()) . '</dc:description>' : '')
            . '<meta:initial-creator>' . $escape($properties->getCreator()) . '</meta:initial-creator>'
            . '<meta:creation-date>' . $properties->getCreated()->format('Y-m-d\TH:i:s') . '</meta:creation-date>'
            . '<dc:date>' . $properties->getModified()->format('Y-m-d\TH:i:s') . '</dc:date>'
            . '</office:meta></office:document-meta>';
    }

    private function getManifestXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0" manifest:version="1.2">'
            . '<manifest:file-entry manifest:full-path="/" manifest:version="1.2" manifest:media-type="' . OdbReader::MIMETYPE . '"/>'
            . '<manifest:file-entry manifest:full-path="database/script" manifest:media-type=""/>'
            . '<manifest:file-entry manifest:full-path="database/properties" manifest:media-type=""/>'
            . '<manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml"/>'
            . '<manifest:file-entry manifest:full-path="settings.xml" manifest:media-type="text/xml"/>'
            . '<manifest:file-entry manifest:full-path="meta.xml" manifest:media-type="text/xml"/>'
            . '</manifest:manifest>';
    }
}
