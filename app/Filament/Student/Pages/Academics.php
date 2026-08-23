<?php

namespace App\Filament\Student\Pages;

use App\Actions\Academics\AcademicEnrollmentEffect;
use App\Actions\Academics\CumulativeGwaProjection;
use App\Actions\Academics\CurriculumEvaluation;
use App\Actions\Academics\OfficialCourseResultProjection;
use App\Actions\Academics\TermWeightedAverageProjection;
use App\Actions\Completion\CompletionReadinessProjection;
use App\Actions\Completion\SubmitGraduationApplication;
use App\Actions\Completion\WithdrawGraduationApplication;
use App\Models\ExternalCompetencyResult;
use App\Models\GraduationApplication;
use App\Models\StudentProfile;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Throwable;

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

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        $student = auth()->user()?->studentProfile;
        if (! $student instanceof StudentProfile) {
            return [];
        }
        $projection = app(CompletionReadinessProjection::class)->forStudent($student);
        $application = $projection['application'];

        return [
            Action::make('applyForGraduation')
                ->label('Apply for graduation')
                ->icon('heroicon-o-academic-cap')
                ->color('primary')
                ->requiresConfirmation()
                ->modalDescription('This records your completion intent. Registrar still verifies every requirement before conferral.')
                ->visible($projection['state'] === CompletionReadinessProjection::EligibleToApply)
                ->action(function () use ($student): void {
                    try {
                        app(SubmitGraduationApplication::class)->execute($student, $this->actor());
                        Notification::make()->title('Graduation application recorded')->success()->send();
                    } catch (Throwable $exception) {
                        report($exception);
                        Notification::make()->title('Application was not recorded')->body('Refresh completion readiness and follow the named recovery step before retrying.')->danger()->send();
                    }
                }),
            Action::make('withdrawGraduationApplication')
                ->label('Withdraw application')
                ->icon('heroicon-o-x-circle')
                ->color('gray')
                ->requiresConfirmation()
                ->schema([Textarea::make('reason')->required()->maxLength(1000)])
                ->visible($application instanceof GraduationApplication)
                ->action(function (array $data) use ($application): void {
                    if (! $application instanceof GraduationApplication) {
                        return;
                    }
                    app(WithdrawGraduationApplication::class)->execute($application, $this->actor(), (string) $data['reason']);
                    Notification::make()->title('Graduation application withdrawn')->success()->send();
                }),
        ];
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

        $completion = app(CompletionReadinessProjection::class)->forStudent($student);

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
            'completion' => $completion,
            'graduationApplications' => $student->graduationApplications()->latest('version')->get(),
            'degreeConferrals' => $student->degreeConferrals()->latest('version')->get(),
        ];
    }

    private function actor(): User
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
