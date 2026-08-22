<?php

namespace App\Actions\Imports;

class CourseSpecificationImportTemplate
{
    public const Version = 'course-specification-v5';

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
            'academic_classification',
            'scheduling_treatment',
            'allowed_modalities',
            'same_faculty_default',
            'effective_term_label',
            'state',
            'component_type',
            'weekly_contact_hours',
            'meeting_pattern',
            'room_type_default',
            'required_room_feature_keys',
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
                'ORDINARY',
                'Recurring',
                'FACE_TO_FACE|ONLINE',
                'yes',
                '',
                'DRAFT',
                'LECTURE',
                '3.00',
                '1x180',
                'LECTURE_ROOM',
                '',
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
