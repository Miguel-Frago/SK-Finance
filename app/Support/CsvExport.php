<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

/** Streams a CSV file that opens correctly in Excel (UTF-8) and cannot run a formula typed into a name. */
class CsvExport
{
    /**
     * @param  array<int, string>  $header
     * @param  iterable<array<int, mixed>>  $rows
     */
    public static function download(string $filename, array $header, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");   // byte-order mark: Excel reads the accents and the ñ correctly

            fputcsv($out, $header);

            foreach ($rows as $row) {
                fputcsv($out, array_map([self::class, 'safe'], $row));
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** A cell that starts with = + - or @ would be read as a formula by Excel; a leading apostrophe stops that. */
    public static function safe(mixed $value): string
    {
        $text = (string) $value;

        return $text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $text : $text;
    }
}
