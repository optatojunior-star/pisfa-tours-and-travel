<?php

namespace App\Support\Export;

/**
 * Makes a value safe to put in a spreadsheet cell.
 *
 * A cell whose text begins with `=`, `+`, `-`, `@`, a tab, or a carriage return
 * is interpreted as a **formula** by Excel, LibreOffice, and Google Sheets. A
 * customer whose name we stored as `=HYPERLINK("http://evil","Click")` would
 * therefore execute on the machine of whoever opened the export. Escaping is
 * done here, once, so no exporter can forget it.
 *
 * The value is still quoted normally by fputcsv; this only neutralises the
 * formula trigger by prefixing a single quote, which spreadsheets read as
 * "treat the rest as text".
 */
final class CsvCell
{
    /** Characters that begin a formula in every major spreadsheet program. */
    private const TRIGGERS = ['=', '+', '-', '@', "\t", "\r"];

    public static function safe(mixed $value): string
    {
        if ($value === null || $value === false) {
            return '';
        }

        if ($value === true) {
            return 'yes';
        }

        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        }

        if ($value instanceof \DateTimeInterface) {
            $value = $value->format('Y-m-d H:i');
        }

        $string = (string) $value;

        if ($string === '') {
            return '';
        }

        // Control characters can break a row apart or hide content from a
        // reviewer, so they go before the formula check.
        $string = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $string) ?? '';

        if ($string === '') {
            return '';
        }

        if (in_array(mb_substr($string, 0, 1), self::TRIGGERS, true)) {
            return "'".$string;
        }

        return $string;
    }

    /**
     * @param  array<int|string, mixed>  $row
     * @return list<string>
     */
    public static function safeRow(array $row): array
    {
        return array_values(array_map(
            static fn (mixed $value): string => self::safe($value),
            $row,
        ));
    }
}
