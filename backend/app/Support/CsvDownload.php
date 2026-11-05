<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A CSV file streamed row by row, so a big export never sits in memory.
 * Opens cleanly in Excel (UTF-8 byte order mark) and cannot run formulas.
 */
final class CsvDownload
{
    /**
     * @param  list<string>  $header
     * @param  iterable<array<int, scalar|null>>  $rows
     */
    public static function make(string $filename, array $header, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $header, escape: '');
            foreach ($rows as $row) {
                fputcsv($out, array_map(self::cell(...), $row), escape: '');
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Text that starts like a formula (=, +, -, @, tab, return) is prefixed with a
     * quote, so a title such as "=HYPERLINK(...)" is shown, never run, by a spreadsheet.
     * Numbers are left alone: a negative amount is never user input here.
     */
    public static function cell(mixed $value): string|int|float|null
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        return in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
