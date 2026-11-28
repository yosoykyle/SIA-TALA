<?php

namespace App\Actions\StudentHub;

use App\Actions\Academics\YearLevelStanding;
use App\Actions\Enrollment\CurrentOfficialEnrollmentResolver;
use App\Actions\Enrollment\EnrollmentAcademicContextResolver;
use App\Actions\Enrollment\RegistrationAcademicEligibilityQuery;
use App\Models\CurriculumEntry;
use App\Models\PriorAcademicCredit;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;

class StudentStudyContext
{
    public function __construct(
        private CurrentOfficialEnrollmentResolver $officialEnrollment,
        private EnrollmentAcademicContextResolver $academicContext,
        private RegistrationAcademicEligibilityQuery $eligibility,
    ) {}

    /**
     * @return array{program_name:?string, curriculum_levels:list<string>, study_level_label:string, semester:?string, academic_year:?string, term_label:?string, enrollment_id:?int, is_official:bool}
     */
    public function forStudent(User $student): array
    {
        $profile = $student->studentProfile()->with('program')->first();
        $enrollment = $profile === null ? null : $this->officialEnrollment->forProfile($profile);
        $context = [
            'program_name' => $profile?->program?->name,
            'curriculum_levels' => [],
            'study_level_label' => 'Unavailable',
            'semester' => null,
            'academic_year' => null,
            'term_label' => null,
            'enrollment_id' => null,
            'is_official' => false,
        ];

        if ($enrollment === null) {
            if ($profile instanceof StudentProfile) {
                $provisionalLevels = $this->provisionalLevels($profile);
                if ($provisionalLevels !== []) {
                    $provisionalLabels = array_map(
                        fn (string $level): string => self::formatLevelLabel($level),
                        $provisionalLevels,
                    );

                    return array_replace($context, [
                        'curriculum_levels' => $provisionalLevels,
                        'study_level_label' => count($provisionalLabels) === 1
                            ? $provisionalLabels[0]
                            : (YearLevelStanding::describe($provisionalLevels) ?? 'Mixed levels ('.implode(', ', $provisionalLabels).')'),
                    ]);
                }
            }

            return $context;
        }

        $enrollment->loadMissing('term.academicYear');
        $levels = $this->academicContext->forEnrollment($enrollment)['curriculum_levels'];
        $labels = array_map(fn (string $level): string => self::formatLevelLabel($level), $levels);

        return array_replace($context, [
            'curriculum_levels' => $levels,
            'study_level_label' => match (count($labels)) {
                0 => 'Unavailable',
                1 => $labels[0],
                // #117: a mixed load shows its highest year level, the same as every other screen.
                default => YearLevelStanding::describe($levels) ?? 'Mixed levels ('.implode(', ', $labels).')',
            },
            'semester' => Term::typeOptions()[$enrollment->term?->type] ?? null,
            'academic_year' => $enrollment->term?->academicYear?->label,
            'term_label' => $enrollment->term?->label,
            'enrollment_id' => (int) $enrollment->getKey(),
            'is_official' => true,
        ]);
    }

    /**
     * Derives provisional year standing for continuing/migrated students with active
     * prior credits who do not yet have a current official TALA enrollment.
     *
     * @return list<string>
     */
    private function provisionalLevels(StudentProfile $profile): array
    {
        $activePriorCredits = PriorAcademicCredit::query()
            ->active()
            ->where('student_profile_id', $profile->id)
            ->get();

        if ($activePriorCredits->isEmpty()) {
            return [];
        }

        if ($profile->curriculum_version_id !== null) {
            $entries = CurriculumEntry::query()
                ->with('courseSpecification')
                ->where('curriculum_version_id', $profile->curriculum_version_id)
                ->get()
                ->sort(function (CurriculumEntry $left, CurriculumEntry $right): int {
                    $yearComparison = self::yearLevelOrder((string) $left->year_level) <=> self::yearLevelOrder((string) $right->year_level);
                    if ($yearComparison !== 0) {
                        return $yearComparison;
                    }

                    $sequenceComparison = (int) $left->sequence <=> (int) $right->sequence;
                    if ($sequenceComparison !== 0) {
                        return $sequenceComparison;
                    }

                    return (int) $left->id <=> (int) $right->id;
                })
                ->values();

            if ($entries->isNotEmpty()) {
                $satisfiedCourseIds = $this->eligibility->satisfiedCourseIds($profile);
                $firstUnsatisfied = $entries->first(
                    fn (CurriculumEntry $entry): bool => $entry->courseSpecification?->course_id !== null
                        && ! $satisfiedCourseIds->contains((int) $entry->courseSpecification->course_id),
                );
                $level = trim((string) ($firstUnsatisfied?->year_level ?? $entries->last()?->year_level ?? ''));

                if ($level !== '') {
                    return [$level];
                }
            }
        }

        $highestCompletedYear = $activePriorCredits
            ->filter(fn (PriorAcademicCredit $credit): bool => $credit->isPassing() && $credit->year_level !== null && $credit->year_level > 0)
            ->max('year_level');

        if (is_numeric($highestCompletedYear) && (int) $highestCompletedYear > 0) {
            $maxDuration = max(1, (int) ($profile->program?->duration_years ?? ((int) $highestCompletedYear + 1)));
            $nextYear = min((int) $highestCompletedYear + 1, $maxDuration);

            return [(string) $nextYear];
        }

        return [];
    }

    private static function formatLevelLabel(string $level): string
    {
        return ctype_digit($level) ? 'Year '.$level : $level;
    }

    private static function yearLevelOrder(string $yearLevel): int
    {
        $trimmed = trim($yearLevel);
        if (ctype_digit($trimmed)) {
            return (int) $trimmed;
        }

        return match (strtolower($trimmed)) {
            'first year', 'year 1', '1st year' => 1,
            'second year', 'year 2', '2nd year' => 2,
            'third year', 'year 3', '3rd year' => 3,
            'fourth year', 'year 4', '4th year' => 4,
            'fifth year', 'year 5', '5th year' => 5,
            default => 99,
        };
    }
}
