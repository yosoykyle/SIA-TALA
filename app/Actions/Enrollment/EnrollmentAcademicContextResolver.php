<?php

namespace App\Actions\Enrollment;

use App\Models\CourseEnrollment;
use App\Models\Enrollment;
use App\Models\EnrollmentSeatReservation;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermOffering;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class EnrollmentAcademicContextResolver
{
    public function __construct(private EnrollmentGateReviewSummary $gateReviewSummary) {}

    /**
     * @return array<string, mixed>|null
     */
    public function currentForProfile(StudentProfile $profile): ?array
    {
        $enrollment = $this->currentEnrollmentForProfile($profile);

        return $enrollment instanceof Enrollment ? $this->forEnrollment($enrollment) : null;
    }

    public function currentEnrollmentForProfile(StudentProfile $profile): ?Enrollment
    {
        if ($profile->relationLoaded('enrollments')) {
            /** @var EloquentCollection<int, Enrollment> $enrollments */
            $enrollments = $profile->enrollments;

            return $enrollments
                ->filter(fn (Enrollment $enrollment): bool => $enrollment->term?->state === Term::StateActive)
                ->sortByDesc(fn (Enrollment $enrollment): string => sprintf(
                    '%s-%010d-%010d',
                    $enrollment->term?->starts_on?->format('Y-m-d') ?? '',
                    (int) $enrollment->term_id,
                    (int) $enrollment->id,
                ))
                ->first();
        }

        return Enrollment::query()
            ->select('enrollments.*')
            ->join('terms', 'terms.id', '=', 'enrollments.term_id')
            ->where('enrollments.student_profile_id', $profile->getKey())
            ->where('terms.state', Term::StateActive)
            ->orderByDesc('terms.starts_on')
            ->orderByDesc('terms.id')
            ->orderByDesc('enrollments.id')
            ->first();
    }

    public function applyCurrentEnrollmentStatusFilter(Builder $profiles, string $status): Builder
    {
        return $profiles->whereExists(function (QueryBuilder $currentEnrollment) use ($status): void {
            $currentEnrollment
                ->selectRaw('1')
                ->from('enrollments as current_enrollments')
                ->join('terms as current_terms', 'current_terms.id', '=', 'current_enrollments.term_id')
                ->whereColumn('current_enrollments.student_profile_id', 'student_profiles.id')
                ->where('current_terms.state', Term::StateActive)
                ->where('current_enrollments.status', $status)
                ->whereNotExists(function (QueryBuilder $newerEnrollment): void {
                    $newerEnrollment
                        ->selectRaw('1')
                        ->from('enrollments as newer_enrollments')
                        ->join('terms as newer_terms', 'newer_terms.id', '=', 'newer_enrollments.term_id')
                        ->whereColumn('newer_enrollments.student_profile_id', 'current_enrollments.student_profile_id')
                        ->where('newer_terms.state', Term::StateActive)
                        ->where(function (QueryBuilder $newerAcademicContext): void {
                            $newerAcademicContext
                                ->whereColumn('newer_terms.starts_on', '>', 'current_terms.starts_on')
                                ->orWhere(function (QueryBuilder $sameStartDate): void {
                                    $sameStartDate
                                        ->whereColumn('newer_terms.starts_on', '=', 'current_terms.starts_on')
                                        ->where(function (QueryBuilder $newerKey): void {
                                            $newerKey
                                                ->whereColumn('newer_terms.id', '>', 'current_terms.id')
                                                ->orWhere(function (QueryBuilder $sameTerm): void {
                                                    $sameTerm
                                                        ->whereColumn('newer_terms.id', '=', 'current_terms.id')
                                                        ->whereColumn('newer_enrollments.id', '>', 'current_enrollments.id');
                                                });
                                        });
                                });
                        });
                });
        });
    }

    /**
     * @return array{
     *     enrollment_id:int,
     *     term_id:int,
     *     term_label:?string,
     *     enrollment_status:string,
     *     enrollment_status_label:string,
     *     enrollment_type:?string,
     *     enrollment_type_label:string,
     *     program_id:?int,
     *     program_code:?string,
     *     program_name:?string,
     *     curriculum_version_id:?int,
     *     curriculum_name:?string,
     *     curriculum_levels:list<string>,
     *     curriculum_level_label:string,
     *     section_ids:list<int>,
     *     section_labels:list<string>,
     *     cohort_labels:list<string>,
     *     course_delivery_mix:string,
     *     responsible_office:string,
     *     next_action:string
     * }
     */
    public function forEnrollment(Enrollment $enrollment): array
    {
        $enrollment->loadMissing([
            'term',
            'studentProfile.program',
            'studentProfile.curriculumVersion',
            'courseEnrollments.termOffering.curriculumEntry',
            'courseEnrollments.proposedSection.deliveryGroups',
            'courseEnrollments.seatReservations.section.deliveryGroups',
            'gateResults',
        ]);

        $activeCourses = $enrollment->courseEnrollments
            ->filter(fn (CourseEnrollment $courseEnrollment): bool => $courseEnrollment->status === CourseEnrollment::StatusActive)
            ->values();
        $curriculumLevels = $activeCourses
            ->map(fn (CourseEnrollment $courseEnrollment): ?string => $courseEnrollment->termOffering?->curriculumEntry?->year_level)
            ->filter(fn (?string $level): bool => filled($level))
            ->map(fn (string $level): string => trim($level))
            ->unique()
            ->sortBy(fn (string $level): string => $level, SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
        $modalities = $activeCourses
            ->map(fn (CourseEnrollment $courseEnrollment): ?string => $courseEnrollment->termOffering?->modality)
            ->filter(fn (?string $modality): bool => filled($modality))
            ->unique()
            ->values();
        $sections = $activeCourses
            ->flatMap(fn (CourseEnrollment $courseEnrollment): array => $this->sectionsFor($courseEnrollment))
            ->unique(fn (Section $section): int => (int) $section->getKey())
            ->sortBy('code')
            ->values();
        $profile = $enrollment->studentProfile;
        $program = $profile->program;
        $curriculum = $profile->curriculumVersion;

        return [
            'enrollment_id' => (int) $enrollment->getKey(),
            'term_id' => (int) $enrollment->term_id,
            'term_label' => $enrollment->term?->label,
            'enrollment_status' => (string) $enrollment->status,
            'enrollment_status_label' => Str::headline((string) $enrollment->status),
            'enrollment_type' => $enrollment->student_type,
            'enrollment_type_label' => filled($enrollment->student_type)
                ? Str::headline((string) $enrollment->student_type)
                : 'Not recorded',
            'program_id' => $program?->getKey() !== null ? (int) $program->getKey() : null,
            'program_code' => $program?->code,
            'program_name' => $program?->name,
            'curriculum_version_id' => $curriculum?->getKey() !== null ? (int) $curriculum->getKey() : null,
            'curriculum_name' => $curriculum?->name,
            'curriculum_levels' => $curriculumLevels->all(),
            'curriculum_level_label' => $this->curriculumLevelLabel($curriculumLevels),
            'section_ids' => $sections->map(fn (Section $section): int => (int) $section->getKey())->all(),
            'section_labels' => $sections->pluck('code')->filter()->values()->all(),
            'cohort_labels' => $sections
                ->flatMap(fn (Section $section): Collection => $section->deliveryGroups->pluck('name'))
                ->filter()
                ->unique()
                ->sortBy(fn (string $cohort): string => $cohort, SORT_NATURAL | SORT_FLAG_CASE)
                ->values()
                ->all(),
            'course_delivery_mix' => $this->courseDeliveryMix($modalities),
            'responsible_office' => $this->gateReviewSummary->responsibleOffice($enrollment),
            'next_action' => $this->gateReviewSummary->nextStep($enrollment),
        ];
    }

    /**
     * @return list<Section>
     */
    private function sectionsFor(CourseEnrollment $courseEnrollment): array
    {
        $confirmed = $courseEnrollment->seatReservations
            ->filter(fn (EnrollmentSeatReservation $reservation): bool => in_array($reservation->status, [
                EnrollmentSeatReservation::StatusActive,
                EnrollmentSeatReservation::StatusPending,
                EnrollmentSeatReservation::StatusConverted,
            ], true))
            ->map(fn (EnrollmentSeatReservation $reservation): ?Section => $reservation->section)
            ->filter()
            ->values()
            ->all();

        if ($confirmed !== []) {
            return $confirmed;
        }

        return $courseEnrollment->proposedSection instanceof Section
            ? [$courseEnrollment->proposedSection]
            : [];
    }

    /**
     * @param  Collection<int, string>  $levels
     */
    private function curriculumLevelLabel(Collection $levels): string
    {
        if ($levels->isEmpty()) {
            return 'Not recorded';
        }

        if ($levels->count() > 1) {
            return 'Mixed Levels ('.$levels->implode(', ').')';
        }

        $level = (string) $levels->first();

        return Str::contains(Str::lower($level), ['level', 'year']) ? $level : 'Level '.$level;
    }

    /**
     * @param  Collection<int, string>  $modalities
     */
    private function courseDeliveryMix(Collection $modalities): string
    {
        if ($modalities->isEmpty()) {
            return 'Not recorded';
        }

        if ($modalities->count() > 1) {
            return 'Mixed';
        }

        $modality = (string) $modalities->first();

        return TermOffering::modalityOptions()[$modality] ?? Str::headline($modality);
    }
}
