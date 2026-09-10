<?php

declare(strict_types=1);

namespace App\Workforce\Infrastructure\Ministry;

use App\Workforce\Domain\WorkplaceCatalogUnavailable;

final class CsvTableReader
{
    /** @return list<array<string, string>> */
    public function read(string $contents): array
    {
        $stream = fopen('php://temp', 'w+');
        if (false === $stream) {
            throw new WorkplaceCatalogUnavailable('Could not allocate a temporary CSV stream.');
        }

        fwrite($stream, $contents);
        rewind($stream);

        $header = fgetcsv($stream, null, ',', '"', '');
        if (false === $header) {
            fclose($stream);
            throw new WorkplaceCatalogUnavailable('The downloaded CSV is empty.');
        }

        $header = array_map(
            static fn (?string $column): string => trim((string) $column, "\xEF\xBB\xBF \t\n\r\0\x0B"),
            $header,
        );

        $rows = [];
        while (false !== ($values = fgetcsv($stream, null, ',', '"', ''))) {
            if ([null] === $values || [] === array_filter($values, static fn (?string $value): bool => null !== $value && '' !== trim($value))) {
                continue;
            }

            $values = array_pad($values, \count($header), '');
            $row = array_combine($header, \array_slice($values, 0, \count($header)));
            $rows[] = array_map(static fn (?string $value): string => trim((string) $value), $row);
        }

        fclose($stream);

        return $rows;
    }
}
