<?php

namespace App\Actions\Enrollment;

use App\Models\Enrollment;
use App\Models\StudentProfile;

class SubjectSuggestionService
{
    public const StatusSuggested = 'suggested';

    public const StatusBackSubject = 'back_subject';

    public const StatusBlocked = 'blocked';

    public const StatusAlreadyPassed = 'already_passed';

    public const BlockerMissingHistory = 'missing_history';

    public const BlockerFailed = 'failed';

    public const BlockerActiveInc = 'active_inc';

    public const BlockerPendingGrade = 'pending_grade';

    public function __construct(private readonly AcademicProgressionService $progression) {}

    /**
     * @return array<string, mixed>
     */
    public function suggestForEnrollment(Enrollment $enrollment): array
    {
        $enrollment->loadMissing(['studentProfile', 'term']);
        $studentProfile = $enrollment->studentProfile;

        if (! $studentProfile instanceof StudentProfile) {
            return $this->emptyResult($enrollment, ['missing_student_profile']);
        }

        $result = $this->progression->evaluate($studentProfile, $enrollment->term);
        $suggested = collect($result['suggestions'])->map(fn (array $item): array => $this->legacyItem($item))->all();
        $backSubjects = collect($result['back_subjects'])->map(fn (array $item): array => $this->legacyItem($item))->all();
        $blocked = collect($result['blockers'])
            ->groupBy('course_id')
            ->map(function ($items): array {
                $first = $items->first();
                $itemBlockers = $items->flatMap(fn (array $item): array => $item['alternative_blockers'] ?? [$item]);

                return [
                    'subject_id' => $first['course_id'],
                    'course_id' => $first['course_id'],
                    'code' => $first['course_code'],
                    'status' => self::StatusBlocked,
                    'blockers' => $itemBlockers->map(fn (array $item): array => [
                        ...$item,
                        'reason' => $item['reason'] ?? self::BlockerMissingHistory,
                    ])->values()->all(),
                ];
            })->values()->all();
        $alreadyPassed = collect($result['completed'])
            ->map(fn (array $item): array => $this->legacyItem($item))
            ->all();

        return [
            'enrollment_id' => (int) $enrollment->id,
            'student_profile_id' => (int) $studentProfile->id,
            'term_id' => (int) $enrollment->term_id,
            'curriculum_id' => $studentProfile->curriculum_version_id,
            'year_level' => null,
            'curriculum_period' => $enrollment->term?->label,
            'suggested' => $suggested,
            'back_subjects' => $backSubjects,
            'blocked' => $blocked,
            'already_passed' => $alreadyPassed,
            'setup_blockers' => $result['standing'] === StudentProfile::StandingNotYetEvaluated ? ['missing_curriculum_baseline'] : [],
            'standing' => $result['standing'],
            'facts' => $result['facts'],
            'summary' => [
                'suggested_count' => count($suggested),
                'back_subject_count' => count($backSubjects),
                'blocked_count' => count($blocked),
                'already_passed_count' => count($alreadyPassed),
                'has_blockers' => $blocked !== [],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function legacyItem(array $item): array
    {
        return [
            'subject_id' => $item['course_id'],
            'course_id' => $item['course_id'],
            'code' => $item['course_code'],
            'description' => $item['title'],
            'units' => $item['units'],
            'curriculum_subject_id' => $item['curriculum_entry_id'],
            'curriculum_entry_id' => $item['curriculum_entry_id'],
            'year_level' => $item['year_level'],
            'curriculum_period' => $item['term_label'],
            'status' => $item['status'],
            'source' => $item['source'],
            'term_offering_id' => $item['term_offering_id'] ?? null,
            'latest_grade' => ($item['source']['type'] ?? null) === 'grade_roster_row' ? [
                'grade_roster_row_id' => $item['source']['id'],
                'grade' => is_numeric($item['source']['code']) ? $item['source']['code'] : null,
                'outcome_code' => $item['source']['code'],
                'remarks' => $item['source']['category'],
            ] : null,
        ];
    }

    /** @return array<string, mixed> */
    private function emptyResult(Enrollment $enrollment, array $setupBlockers): array
    {
        return [
            'enrollment_id' => (int) $enrollment->id,
            'student_profile_id' => null,
            'term_id' => $enrollment->term_id,
            'curriculum_id' => null,
            'year_level' => null,
            'curriculum_period' => null,
            'suggested' => [],
            'back_subjects' => [],
            'blocked' => [],
            'already_passed' => [],
            'setup_blockers' => $setupBlockers,
            'summary' => [
                'suggested_count' => 0,
                'back_subject_count' => 0,
                'blocked_count' => 0,
                'already_passed_count' => 0,
                'has_blockers' => true,
            ],
        ];
    }
}
