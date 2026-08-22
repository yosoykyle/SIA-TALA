<?php

namespace App\Filament\Student\Pages;

use App\Actions\Academics\AcademicEnrollmentEffect;
use App\Actions\Academics\CumulativeGwaProjection;
use App\Actions\Academics\CurriculumEvaluation;
use App\Actions\Academics\OfficialCourseResultProjection;
use App\Actions\Academics\TermWeightedAverageProjection;
use App\Models\ExternalCompetencyResult;
use App\Models\StudentProfile;
use Filament\Pages\Page;

class Academics extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?string $navigationLabel = 'Academics';

    protected static ?string $title = 'Student Academics';

    protected string $view = 'filament.student.pages.academics';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('student') ?? false;
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $student = auth()->user()?->studentProfile;

        if (! $student instanceof StudentProfile) {
            return ['student' => null];
        }

        $results = app(OfficialCourseResultProjection::class)->forStudent($student);
        $terms = $results->pluck('term')->filter()->unique('id')->sortByDesc('ends_on')->values();
        $termAverages = $terms->mapWithKeys(fn ($term): array => [
            $term->id => app(TermWeightedAverageProjection::class)->forStudentAndTerm($student, $term),
        ]);

        return [
            'student' => $student,
            'results' => $results,
            'terms' => $terms,
            'termAverages' => $termAverages,
            'cumulative' => app(CumulativeGwaProjection::class)->forStudent($student),
            'curriculum' => app(CurriculumEvaluation::class)->forStudent($student),
            'effect' => app(AcademicEnrollmentEffect::class)->forStudent($student),
            'competencyResults' => ExternalCompetencyResult::query()
                ->where('student_profile_id', $student->id)
                ->with('requirement')
                ->latest('recorded_at')->get(),
            'lifecycleChanges' => $student->lifecycleChanges()->latest('effective_on')->get(),
            'scheduleUrl' => ScheduleView::getUrl(panel: 'student'),
            'holdsUrl' => HoldsView::getUrl(panel: 'student'),
            'unofficialRecordUrl' => route('student-academics.unofficial-record', $student),
        ];
    }
}
