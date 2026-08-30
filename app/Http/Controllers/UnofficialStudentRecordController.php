<?php

namespace App\Http\Controllers;

use App\Actions\Academics\AcademicEnrollmentEffect;
use App\Actions\Academics\CumulativeGwaProjection;
use App\Actions\Academics\CurriculumEvaluation;
use App\Actions\Academics\OfficialCourseResultProjection;
use App\Actions\Academics\TermWeightedAverageProjection;
use App\Models\OutputAccessLog;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Http\Response;

class UnofficialStudentRecordController extends Controller
{
    public function __construct(
        private readonly OfficialCourseResultProjection $results,
        private readonly TermWeightedAverageProjection $termAverages,
        private readonly CumulativeGwaProjection $cumulativeGwa,
        private readonly CurriculumEvaluation $curriculumEvaluation,
        private readonly AcademicEnrollmentEffect $academicEnrollmentEffect,
    ) {}

    public function __invoke(StudentProfile $student): Response
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        abort_unless(
            $user->hasRole('student') && (int) $user->studentProfile?->id === (int) $student->id,
            403,
        );

        $results = $this->results->forStudent($student)->filter(fn (array $result): bool => $result['event'] !== null);
        abort_if($results->isEmpty(), 409, 'No released academic results are available for an unofficial record.');
        $terms = $results->pluck('term')->filter()->unique('id')->sortBy('ends_on')->values();
        $termAverages = $terms->mapWithKeys(fn ($term): array => [
            $term->id => $this->termAverages->forStudentAndTerm($student, $term),
        ]);
        $asOf = now('Asia/Manila');
        $html = view('outputs.unofficial-student-record', [
            'student' => $student,
            'results' => $results,
            'terms' => $terms,
            'termAverages' => $termAverages,
            'cumulative' => $this->cumulativeGwa->forStudent($student),
            'curriculum' => $this->curriculumEvaluation->forStudent($student),
            'academicEffect' => $this->academicEnrollmentEffect->forStudent($student),
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
