<?php

namespace App\Actions\Imports;

class AcademicImportCsv
{
    /**
     * @param  list<list<string>>  $rows
     */
    public static function toCsv(array $rows): string
    {
        return collect($rows)
            ->map(fn (array $row): string => self::csvLine($row))
            ->implode("\n")."\n";
    }

    /**
     * @param  list<string>  $row
     */
    private static function csvLine(array $row): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return implode(',', $row);
        }

        fputcsv($handle, $row);
        rewind($handle);
        $line = stream_get_contents($handle);
        fclose($handle);

        return rtrim((string) $line, "\r\n");
    }
}
