<?php

declare(strict_types=1);

namespace App\Tests\Integration\Workforce\Ministry;

use App\Workforce\Domain\WorkplaceSource;
use App\Workforce\Domain\WorkplaceType;
use App\Workforce\Infrastructure\Ministry\CsvTableReader;
use App\Workforce\Infrastructure\Ministry\MinistryCatalogReader;
use App\Workforce\Infrastructure\Ministry\MinistryWorkplaceCatalog;
use App\Workforce\Infrastructure\Ministry\OfficeOpenXmlTableReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class MinistryWorkplaceCatalogTest extends TestCase
{
    public function test_it_imports_public_primary_care_types_discards_private_rows_and_controls_invalid_rows(): void
    {
        $catalog = $this->catalog(WorkplaceSource::MINISTRY_PRIMARY_CARE, 'primary-care.csv')->fetch();

        self::assertCount(2, $catalog->workplaces);
        self::assertSame(1, $catalog->rejectedRows);
        self::assertSame(['siap:123456', 'siap:123457'], array_column($catalog->workplaces, 'externalId'));
        self::assertSame([WorkplaceType::HEALTH_CENTER, WorkplaceType::LOCAL_CLINIC], array_column($catalog->workplaces, 'type'));
        self::assertSame('DISTRITO GRANADA', $catalog->workplaces[0]->healthArea);
        self::assertSame('GRANADA CENTRO', $catalog->workplaces[0]->basicHealthZone);
    }

    public function test_it_imports_an_out_of_hospital_urgent_care_device(): void
    {
        $catalog = $this->catalog(WorkplaceSource::MINISTRY_URGENT_CARE, 'urgent-care.csv')->fetch();

        self::assertCount(1, $catalog->workplaces);
        self::assertSame(WorkplaceType::OUT_OF_HOSPITAL_URGENT_CARE, $catalog->workplaces[0]->type);
        self::assertSame('CENTRO SALUD GRANADA CENTRO', $catalog->workplaces[0]->name);
    }

    public function test_it_imports_a_public_hospital_and_discards_a_private_one(): void
    {
        $catalog = $this->catalog(WorkplaceSource::MINISTRY_HOSPITALS, 'hospitals.csv')->fetch();

        self::assertCount(1, $catalog->workplaces);
        self::assertSame('0118000234', $catalog->workplaces[0]->externalId);
        self::assertSame('HOSPITAL UNIVERSITARIO VIRGEN DE LAS NIEVES', $catalog->workplaces[0]->name);
    }

    /** @return iterable<string, array{string}> */
    public static function unacceptableContentTypes(): iterable
    {
        yield 'HTML masquerading as a successful download' => ['text/html'];
        yield 'missing content type' => [''];
    }

    #[DataProvider('unacceptableContentTypes')]
    public function test_it_rejects_a_non_csv_response_before_parsing(string $contentType): void
    {
        $responses = [
            new MockResponse('<html>temporarily unavailable</html>', ['http_code' => 200, 'response_headers' => ['content-type: '.$contentType]]),
            new MockResponse('not a workbook', ['http_code' => 200, 'response_headers' => ['content-type: text/html']]),
        ];
        $reader = new MinistryCatalogReader(new MockHttpClient($responses), new CsvTableReader(), new OfficeOpenXmlTableReader());
        $catalog = new MinistryWorkplaceCatalog($reader, WorkplaceSource::MINISTRY_HOSPITALS, 'https://official.invalid/catalog.csv', 'https://official.invalid/catalog.xlsx', 'xl/worksheets/sheet1.xml');

        $this->expectException(\App\Workforce\Domain\WorkplaceCatalogUnavailable::class);
        $catalog->fetch();
    }

    private function catalog(WorkplaceSource $source, string $fixture): MinistryWorkplaceCatalog
    {
        $contents = file_get_contents(\dirname(__DIR__, 3).'/Fixtures/Workforce/'.$fixture);
        self::assertIsString($contents);
        $response = new MockResponse($contents, ['response_headers' => ['content-type: text/csv; charset=UTF-8']]);
        $reader = new MinistryCatalogReader(new MockHttpClient($response), new CsvTableReader(), new OfficeOpenXmlTableReader());

        return new MinistryWorkplaceCatalog(
            $reader,
            $source,
            'https://official.invalid/catalog.csv',
            'https://official.invalid/catalog.xlsx',
            'xl/worksheets/sheet1.xml',
        );
    }
}
