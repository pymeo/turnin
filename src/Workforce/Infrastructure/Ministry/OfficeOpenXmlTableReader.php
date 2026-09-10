<?php

declare(strict_types=1);

namespace App\Workforce\Infrastructure\Ministry;

use App\Workforce\Domain\WorkplaceCatalogUnavailable;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use XMLReader;
use ZipArchive;

final class OfficeOpenXmlTableReader
{
    /** @return list<array<string, string>> */
    public function read(string $contents, string $worksheet): array
    {
        $path = tempnam(sys_get_temp_dir(), 'turnin-workplaces-');
        if (false === $path || false === file_put_contents($path, $contents)) {
            throw new WorkplaceCatalogUnavailable('Could not stage the official spreadsheet.');
        }

        try {
            return $this->readFile($path, $worksheet);
        } finally {
            @unlink($path);
        }
    }

    /** @return list<array<string, string>> */
    private function readFile(string $path, string $worksheet): array
    {
        $zip = new ZipArchive();
        if (true !== $zip->open($path)) {
            throw new WorkplaceCatalogUnavailable('The official spreadsheet is not a valid XLSX file.');
        }

        try {
            $sharedStrings = $this->sharedStrings($zip);
            $worksheetXml = $zip->getFromName($worksheet);
            if (false === $worksheetXml) {
                throw new WorkplaceCatalogUnavailable('The expected worksheet is missing from the official spreadsheet.');
            }

            $reader = new XMLReader();
            if (!$reader->XML($worksheetXml)) {
                throw new WorkplaceCatalogUnavailable('The official worksheet is not valid XML.');
            }

            $header = null;
            $rows = [];
            while ($reader->read()) {
                if (XMLReader::ELEMENT !== $reader->nodeType || 'row' !== $reader->localName) {
                    continue;
                }

                $values = $this->row($reader->readOuterXml(), $sharedStrings);
                if (null === $header) {
                    $header = $values;
                    continue;
                }

                $row = [];
                foreach ($header as $column => $name) {
                    $name = trim($name);
                    if ('' !== $name) {
                        $row[$name] = trim($values[$column] ?? '');
                    }
                }
                if ([] !== array_filter($row, static fn (string $value): bool => '' !== $value)) {
                    $rows[] = $row;
                }
            }
            $reader->close();

            return $rows;
        } finally {
            $zip->close();
        }
    }

    /** @return list<string> */
    private function sharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if (false === $xml) {
            return [];
        }

        $document = new DOMDocument();
        if (!$document->loadXML($xml)) {
            throw new WorkplaceCatalogUnavailable('The spreadsheet shared strings are invalid.');
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $strings = [];
        $items = $xpath->query('//x:si');
        if (false === $items) {
            throw new WorkplaceCatalogUnavailable('The spreadsheet shared strings cannot be read.');
        }
        foreach ($items as $item) {
            if (!$item instanceof DOMElement) {
                continue;
            }
            $value = '';
            $texts = $xpath->query('.//x:t', $item);
            if (false === $texts) {
                throw new WorkplaceCatalogUnavailable('A spreadsheet shared string cannot be read.');
            }
            foreach ($texts as $text) {
                if (!$text instanceof DOMElement) {
                    continue;
                }
                $value .= $text->textContent;
            }
            $strings[] = $value;
        }

        return $strings;
    }

    /**
     * @param list<string> $sharedStrings
     *
     * @return array<string, string>
     */
    private function row(string $xml, array $sharedStrings): array
    {
        $document = new DOMDocument();
        if (!$document->loadXML($xml)) {
            throw new WorkplaceCatalogUnavailable('A spreadsheet row is invalid XML.');
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $values = [];
        $cells = $xpath->query('//x:c');
        if (false === $cells) {
            throw new WorkplaceCatalogUnavailable('A spreadsheet row cannot be read.');
        }
        foreach ($cells as $cell) {
            if (!$cell instanceof DOMElement) {
                continue;
            }
            $reference = $cell->getAttribute('r');
            $column = preg_replace('/\d+/', '', $reference) ?? '';
            $type = $cell->getAttribute('t');
            $valueNodes = $xpath->query('./x:v', $cell);
            if (false === $valueNodes) {
                throw new WorkplaceCatalogUnavailable('A spreadsheet cell cannot be read.');
            }
            $valueNode = $valueNodes->item(0);
            $raw = $valueNode instanceof DOMNode ? $valueNode->textContent : '';
            $values[$column] = 's' === $type ? ($sharedStrings[(int) $raw] ?? '') : $raw;
        }

        return $values;
    }
}
