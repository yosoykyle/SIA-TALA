<?php

namespace App\Http\Controllers;

use App\Actions\Academics\CumulativeGwaProjection;
use App\Actions\Academics\CurriculumEvaluation;
use App\Actions\Academics\OfficialCourseResultProjection;
use App\Actions\Academics\TermWeightedAverageProjection;
use App\Models\CourseEnrollment;
use App\Models\OutputAccessLog;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;

class UnofficialStudentRecordController extends Controller
{
    public function __construct(
        private readonly OfficialCourseResultProjection $results,
        private readonly TermWeightedAverageProjection $termAverages,
        private readonly CumulativeGwaProjection $cumulativeGwa,
        private readonly CurriculumEvaluation $curriculumEvaluation,
    ) {}

    public function __invoke(StudentProfile $student): Response
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        abort_unless(
            $user->hasRole('student') && (int) $user->studentProfile?->id === (int) $student->id,
            403,
        );

        $results = $this->results->forStudent($student)->filter(fn (array $result): bool => $result['outcome_source'] !== null);
        abort_if($results->isEmpty(), 409, 'No released academic results are available for an unofficial record.');
        $terms = $results->pluck('term')->filter()->unique('id')->sortBy('ends_on')->values();
        $termAverages = $terms->mapWithKeys(fn ($term): array => [
            $term->id => $this->termAverages->forStudentAndTerm($student, $term),
        ]);
        $recordPeriods = $results
            ->groupBy(fn (array $result): string => (string) ($result['period_key'] ?? ('term:'.(int) ($result['term']?->id ?? 0))))
            ->sortBy(fn (Collection $periodResults): string => (string) ($periodResults->first()['period_sort_key'] ?? ''))
            ->map(function (Collection $periodResults, string $periodKey): array {
                $first = $periodResults->first();
                $term = $first['term'] ?? null;
                $label = (string) ($first['period_label'] ?? $term?->label ?? 'Recorded Term');
                $ayLabel = $first['academic_year_label'] ?? $term?->academicYear?->label;
                $yearLevel = $periodResults
                    ->map(function (array $result): ?string {
                        $level = $result['year_level'] ?? (($result['course_enrollment'] ?? null) instanceof CourseEnrollment
                            ? $result['course_enrollment']->termOffering?->curriculumEntry?->year_level
                            : null);

                        return $level !== null && $level !== '' ? (string) $level : null;
                    })
                    ->filter()
                    ->first();
                $yearLevelLabel = match ($yearLevel) {
                    '1' => 'First Year',
                    '2' => 'Second Year',
                    '3' => 'Third Year',
                    null, '' => null,
                    default => ctype_digit($yearLevel) ? 'Year '.$yearLevel : $yearLevel,
                };
                $headingAy = ($ayLabel && ! str_contains($label, $ayLabel)) ? $ayLabel : null;
                $heading = collect([$label, $headingAy])->filter()->implode(' · ');
                $subtitle = collect([$yearLevelLabel, $ayLabel])->filter()->implode(' · ');

                return [
                    'key' => $periodKey,
                    'id_slug' => str($periodKey)->slug()->toString(),
                    'label' => $label,
                    'heading' => $heading,
                    'subtitle' => $subtitle,
                    'rows' => $periodResults->values(),
                    'average' => $this->termAverages->forPeriodResults($periodResults, $label),
                ];
            })
            ->values();
        $asOf = now('Asia/Manila');
        $html = view('outputs.unofficial-student-record', [
            'student' => $student,
            'results' => $results,
            'terms' => $terms,
            'termAverages' => $termAverages,
            'recordPeriods' => $recordPeriods,
            'cumulative' => $this->cumulativeGwa->forStudent($student),
            'curriculum' => $this->curriculumEvaluation->forStudent($student),
            'asOf' => $asOf,
        ])->render();

        OutputAccessLog::query()->create([
            'output_type' => 'Unofficial Student Record',
            'source_record_type' => StudentProfile::class,
            'source_record_id' => $student->id,
            'student_profile_id' => $student->id,
            'actor_user_id' => $user->id,
            'actor_role' => $user->getRoleNames()->first(),
            'action' => 'print',
            'copy_context' => 'released facts as of '.$asOf->toIso8601String(),
            'row_count' => $results->count(),
            'purpose' => 'Authorized read-only academic reference.',
            'sensitivity' => 'restricted_student_academic_record',
            'status' => 'generated',
            'occurred_at' => $asOf,
        ]);

        return response($html);
    }
}
