<?php

namespace App\Support\Export;

use Closure;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a CSV straight to the client.
 *
 * Built row by row rather than assembled in memory: Hostinger Premium is shared
 * hosting with a modest memory limit, and a year of bookings held as one string
 * is how an export becomes a 500 for the person who needed it most.
 *
 * Every cell goes through CsvCell, so no exporter can emit a formula.
 */
final class StreamedCsv
{
    /**
     * @param  list<string>  $headings
     * @param  Closure(callable(array<int|string, mixed>): void): void  $rows
     *                                                                         Receives a writer to call once per row.
     */
    public static function download(string $filename, array $headings, Closure $rows): StreamedResponse
    {
        $filename = self::safeFilename($filename);

        return new StreamedResponse(function () use ($headings, $rows): void {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                return;
            }

            // A byte-order mark, so Excel on Windows reads UTF-8 rather than
            // mangling every non-ASCII name in the file.
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, CsvCell::safeRow($headings));

            $rows(function (array $row) use ($handle): void {
                fputcsv($handle, CsvCell::safeRow($row));
            });

            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            // An export is a point-in-time answer; a cached copy would be a
            // different one.
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Strips anything that could break out of the Content-Disposition header
     * or write outside a download folder.
     */
    private static function safeFilename(string $filename): string
    {
        $filename = preg_replace('/[^A-Za-z0-9._-]/', '-', $filename) ?? 'export';
        $filename = trim($filename, '-.');

        return $filename === '' ? 'export.csv' : mb_substr($filename, 0, 120);
    }
}
