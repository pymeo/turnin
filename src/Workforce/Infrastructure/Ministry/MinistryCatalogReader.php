<?php

declare(strict_types=1);

namespace App\Workforce\Infrastructure\Ministry;

use App\Workforce\Domain\WorkplaceCatalogUnavailable;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class MinistryCatalogReader
{
    private const TIMEOUT_SECONDS = 45.0;
    private const MAX_DURATION_SECONDS = 180.0;

    public function __construct(
        private HttpClientInterface $httpClient,
        private CsvTableReader $csv,
        private OfficeOpenXmlTableReader $spreadsheet,
    ) {
    }

    /** @return list<array<string, string>> */
    public function read(string $csvUrl, string $spreadsheetUrl, string $worksheet): array
    {
        try {
            return $this->csv->read($this->downloadCsv($csvUrl));
        } catch (WorkplaceCatalogUnavailable $csvFailure) {
            try {
                return $this->spreadsheet->read($this->downloadSpreadsheet($spreadsheetUrl), $worksheet);
            } catch (WorkplaceCatalogUnavailable $spreadsheetFailure) {
                throw new WorkplaceCatalogUnavailable('Official CSV failed ('.$csvFailure->getMessage().'); spreadsheet fallback failed ('.$spreadsheetFailure->getMessage().').', previous: $spreadsheetFailure);
            }
        }
    }

    private function downloadCsv(string $url): string
    {
        [$contents, $contentType] = $this->download($url, 'text/csv');
        if (!str_contains($contentType, 'csv') && !str_contains($contentType, 'text/plain')) {
            throw new WorkplaceCatalogUnavailable('unexpected CSV Content-Type '.$contentType);
        }
        if ('' === trim($contents) || str_starts_with(ltrim($contents), '<')) {
            throw new WorkplaceCatalogUnavailable('the CSV response is empty or HTML');
        }

        return $contents;
    }

    private function downloadSpreadsheet(string $url): string
    {
        [$contents, $contentType] = $this->download($url, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        if (!str_contains($contentType, 'spreadsheet') && !str_contains($contentType, 'octet-stream')) {
            throw new WorkplaceCatalogUnavailable('unexpected spreadsheet Content-Type '.$contentType);
        }
        if (!str_starts_with($contents, 'PK')) {
            throw new WorkplaceCatalogUnavailable('the spreadsheet response is not an XLSX archive');
        }

        return $contents;
    }

    /** @return array{string, string} */
    private function download(string $url, string $accept): array
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'Accept' => $accept,
                    'User-Agent' => 'Turnin Workplace Catalog Importer/1.0',
                ],
                'timeout' => self::TIMEOUT_SECONDS,
                'max_duration' => self::MAX_DURATION_SECONDS,
            ]);
            $status = $response->getStatusCode();
            if (200 !== $status) {
                throw new WorkplaceCatalogUnavailable('HTTP status '.$status.' from the official source');
            }

            $headers = $response->getHeaders(false);
            $contentType = strtolower($headers['content-type'][0] ?? 'missing');

            return [$response->getContent(false), $contentType];
        } catch (TransportExceptionInterface $exception) {
            throw new WorkplaceCatalogUnavailable('network error while downloading the official source', previous: $exception);
        }
    }
}
