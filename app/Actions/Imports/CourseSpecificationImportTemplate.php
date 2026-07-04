<?php

namespace App\Actions\Imports;

class CourseSpecificationImportTemplate
{
    public const Version = 'course-specification-v1';

    /**
     * @return list<string>
     */
    public static function headers(): array
    {
        return [
            'template_version',
            'template_type',
            'course_code',
            'course_state',
            'revision_code',
            'title',
            'description',
            'credit_units',
            'grading_profile_key',
            'grading_profile_version',
            'allowed_modalities',
            'same_faculty_default',
            'effective_term_label',
            'state',
            'component_type',
            'weekly_contact_hours',
            'room_type_default',
            'modality_restriction',
            'requires_consecutive_block',
            'same_faculty',
            'component_sequence',
            'prerequisite_course_codes',
            'corequisite_course_codes',
        ];
    }

    public static function csv(): string
    {
        return AcademicImportCsv::toCsv([
            self::headers(),
            [
                self::Version,
                'COURSE_SPECIFICATION',
                'IT101',
                'ACTIVE',
                '2026-DRAFT',
                'Introduction to Computing',
                'Foundational computing concepts for first-year students.',
                '3.00',
                'college_standard',
                '1',
                'FACE_TO_FACE|BLENDED',
                'yes',
                '',
                'DRAFT',
                'LECTURE',
                '3.00',
                'LECTURE_ROOM',
                '',
                'no',
                'yes',
                '1',
                '',
                '',
            ],
        ]);
    }
}
