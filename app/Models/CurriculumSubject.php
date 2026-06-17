<?php

namespace App\Models;

use Database\Factories\CurriculumSubjectFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CurriculumSubject extends Model
{
    /** @use HasFactory<CurriculumSubjectFactory> */
    use HasFactory;

    public const AcademicSubjectTypeMajor = 'major';

    public const AcademicSubjectTypeMinor = 'minor';

    public const AcademicSubjectTypeGeneralEducation = 'general_education';

    public const AcademicSubjectTypeCore = 'core';

    public const AcademicSubjectTypeApplied = 'applied';

    public const AcademicSubjectTypeSpecialized = 'specialized';

    public const SchedulingGroupLecture = 'lecture';

    public const SchedulingGroupLaboratory = 'laboratory';

    public const SchedulingGroupLectureLaboratory = 'lecture_laboratory';

    public const SchedulingGroupModular = 'modular';

    public const SchedulingGroupInternship = 'internship';

    public const SchedulingGroupPracticum = 'practicum';

    public const DeliveryOverrideOnline = 'online';

    public const DeliveryOverrideOnSite = 'on_site';

    public const DeliveryOverrideBlended = 'blended';

    public const DeliveryOverrideExcludeFromAutoSchedule = 'exclude_from_auto_schedule';

    /**
     * @return array<string, string>
     */
    public static function academicSubjectTypeOptions(): array
    {
        return [
            self::AcademicSubjectTypeMajor => 'Major / Professional / TESDA NC',
            self::AcademicSubjectTypeMinor => 'Minor / General Education',
            self::AcademicSubjectTypeGeneralEducation => 'General Education',
            self::AcademicSubjectTypeCore => 'SHS Core',
            self::AcademicSubjectTypeApplied => 'SHS Applied',
            self::AcademicSubjectTypeSpecialized => 'SHS Specialized',
        ];
    }

    /**
     * @return list<string>
     */
    public static function academicSubjectTypeValues(): array
    {
        return array_keys(self::academicSubjectTypeOptions());
    }

    /**
     * @return array<string, string>
     */
    public static function schedulingGroupOptions(): array
    {
        return [
            self::SchedulingGroupLecture => 'Lecture',
            self::SchedulingGroupLaboratory => 'Laboratory',
            self::SchedulingGroupLectureLaboratory => 'Lecture and Laboratory',
            self::SchedulingGroupModular => 'Modular / asynchronous',
            self::SchedulingGroupInternship => 'Internship / practicum placement',
            self::SchedulingGroupPracticum => 'Practicum',
        ];
    }

    /**
     * @return list<string>
     */
    public static function schedulingGroupValues(): array
    {
        return array_keys(self::schedulingGroupOptions());
    }

    /**
     * @return array<string, string>
     */
    public static function deliveryRuleOverrideOptions(): array
    {
        return [
            self::DeliveryOverrideOnline => 'Force online',
            self::DeliveryOverrideOnSite => 'Force on-site',
            self::DeliveryOverrideBlended => 'Force blended',
            self::DeliveryOverrideExcludeFromAutoSchedule => 'Exclude from automatic scheduling',
        ];
    }

    /**
     * @return list<string>
     */
    public static function deliveryRuleOverrideValues(): array
    {
        return array_keys(self::deliveryRuleOverrideOptions());
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'curriculum_id',
        'subject_id',
        'year_level',
        'semester',
        'weekly_contact_hours',
        'academic_subject_type',
        'scheduling_group',
        'delivery_rule_override',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'weekly_contact_hours' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    public function curriculum(): BelongsTo
    {
        return $this->belongsTo(Curriculum::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }
}
