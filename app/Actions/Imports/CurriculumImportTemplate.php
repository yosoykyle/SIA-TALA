<?php

namespace App\Actions\Imports;

class CurriculumImportTemplate
{
    public const Version = 'curriculum-v2';

    /**
     * @return list<string>
     */
    public static function headers(): array
    {
        return [
            'template_version',
            'template_type',
            'program_code',
            'curriculum_version_code',
            'curriculum_name',
            'effective_entry_term_label',
            'state',
            'course_code',
            'course_revision_code',
            'course_title',
            'course_units',
            'prerequisite_course_codes',
            'year_level',
            'term_label',
            'term_type',
            'sequence',
            'requirement_group',
            'client_total_units',
        ];
    }

    public static function csv(): string
    {
        return AcademicImportCsv::toCsv([
            self::headers(),
            [
                self::Version,
                'CURRICULUM',
                'BSIT',
                'BSIT-2026-DRAFT',
                'BSIT Curriculum 2026',
                '',
                'DRAFT',
                'IT101',
                '2026-DRAFT',
                'Introduction to Computing',
                '3.00',
                '',
                '1',
                'First Semester',
                'FIRST_SEMESTER',
                '1',
                'required',
                '3.00',
            ],
        ]);
    }
}
