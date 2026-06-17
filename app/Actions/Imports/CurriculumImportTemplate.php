<?php

namespace App\Actions\Imports;

class CurriculumImportTemplate
{
    /**
     * @return list<string>
     */
    public static function headers(): array
    {
        return [
            'Education Level',
            'Program Code',
            'Program Name',
            'Curriculum Version',
            'Effective Year',
            'Is Active',
            'Year/Grade',
            'Curriculum Period',
            'Subject Code',
            'Subject Title',
            'Units',
            'Weekly Contact Hours',
            'Academic Subject Type',
            'Scheduling Group',
            'Delivery Rule Override',
            'Category',
            'Sort Order',
        ];
    }

    public static function csv(): string
    {
        $rows = [
            self::headers(),
            [
                'college',
                'BSIT',
                'Bachelor of Science in Information Technology',
                'BSIT 2026',
                '2026',
                'yes',
                '1st Year',
                '1st Semester',
                'IT101',
                'Introduction to Computing',
                '3.00',
                '3.00',
                'major',
                'lecture',
                '',
                'lecture',
                '1',
            ],
        ];

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
