<?php

declare(strict_types=1);

namespace App\Tests\Integration\Workforce\Ministry;

use App\Workforce\Infrastructure\Ministry\OfficeOpenXmlTableReader;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class OfficeOpenXmlTableReaderTest extends TestCase
{
    public function test_it_reads_the_small_official_workbook_shape_without_external_services(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'turnin-xlsx-test-');
        self::assertIsString($path);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::OVERWRITE));
        $zip->addFromString('xl/sharedStrings.xml', <<<'XML'
            <sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
              <si><t>Nombre</t></si><si><t>Municipio</t></si>
              <si><t>Hospital La Paz</t></si><si><t>Madrid</t></si>
            </sst>
            XML);
        $zip->addFromString('xl/worksheets/sheet1.xml', <<<'XML'
            <worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>
              <row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row>
              <row r="2"><c r="A2" t="s"><v>2</v></c><c r="B2" t="s"><v>3</v></c></row>
            </sheetData></worksheet>
            XML);
        self::assertTrue($zip->close());

        try {
            $contents = file_get_contents($path);
            self::assertIsString($contents);
            self::assertSame(
                [['Nombre' => 'Hospital La Paz', 'Municipio' => 'Madrid']],
                (new OfficeOpenXmlTableReader())->read($contents, 'xl/worksheets/sheet1.xml'),
            );
        } finally {
            unlink($path);
        }
    }
}
