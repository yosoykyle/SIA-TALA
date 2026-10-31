<?php

namespace App\Filament\Student\Pages;

use App\Actions\Academics\AcademicEnrollmentEffect;
use App\Actions\Academics\AcademicRecordNotificationService;
use App\Actions\Academics\CumulativeGwaProjection;
use App\Actions\Academics\CurriculumEvaluation;
use App\Actions\Academics\ExaminationPeriodProjection;
use App\Actions\Academics\OfficialCourseResultProjection;
use App\Actions\Academics\TermWeightedAverageProjection;
use App\Actions\Completion\CompletionReadinessProjection;
use App\Actions\Enrollment\CurrentOfficialEnrollmentResolver;
use App\Actions\StudentHub\StudentStudyContext;
use App\Models\CourseEnrollment;
use App\Models\CourseRequirement;
use App\Models\ExternalCompetencyResult;
use App\Models\OperationalEvent;
use App\Models\StudentProfile;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

class Academics extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?string $navigationLabel = 'Academics';

    protected static ?string $title = 'Academics';

    protected string $view = 'filament.student.pages.academics';

    #[Url(as: 'view', except: 'current', history: true)]
    public string $academicView = 'current';

    public function getTitle(): string
    {
        return match ($this->academicView) {
            'plan' => 'Study plan',
            'record' => 'Academic record',
            'graduation' => 'Graduation records',
            default => 'Academics',
        };
    }

    /** @return array<string|int, string> */
    public function getBreadcrumbs(): array
    {
        return $this->academicView === 'current' ? [Dashboard::getUrl(panel: 'student') => 'Overview', 'Academics'] : [
            Dashboard::getUrl(panel: 'student') => 'Overview',
            static::getUrl(panel: 'student') => 'Academics',
            ...($this->academicView === 'graduation' ? [static::getUrl(['view' => 'record'], panel: 'student') => 'Academic record'] : []),
            $this->getTitle(),
        ];
    }

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        if ($this->academicView === 'record') {
            $student = $this->actor()->studentProfile;

            return [
                Action::make('unofficialRecord')->label('Unofficial record')->icon('heroicon-o-printer')->color('gray')
                    ->url($student ? route('student-academics.unofficial-record', $student) : null)->openUrlInNewTab()
                    ->visible($student instanceof StudentProfile && app(OfficialCourseResultProjection::class)->forStudent($student)->whereNotNull('event')->isNotEmpty()),
                Action::make('graduation')->label('Graduation records')->color('gray')
                    ->url(static::getUrl(['view' => 'graduation'], panel: 'student'))
                    ->visible($this->hasGraduationHistory()),
            ];
        }

        return $this->academicView === 'current' ? [
            Action::make('academicRecord')->label('Academic record')->icon('heroicon-o-document-text')->color('gray')
                ->url(static::getUrl(['view' => 'record'], panel: 'student')),
        ] : [];
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('student') ?? false;
    }

    public function resendCompletionUpdateAction(): Action
    {
        return Action::make('resendCompletionUpdate')
            ->label('Resend failed completion update')
            ->icon('heroicon-o-paper-airplane')
            ->color('warning')
            ->requiresConfirmation()
            ->modalDescription('This retries only the failed completion email. Your application, readiness, conferral, and academic record will not change.')
            ->visible($this->failedCompletionNotification() instanceof OperationalEvent)
            ->action(function (): void {
                $event = $this->failedCompletionNotification();
                if (! $event instanceof OperationalEvent) {
                    return;
                }
                app(AcademicRecordNotificationService::class)->resend($event, $this->actor());
                Notification::make()->title('Completion update queued again')->success()->send();
            });
    }

    protected function yearLevelLabel(?string $yearLevel): ?string
    {
        if ($yearLevel === null || $yearLevel === '') {
            return null;
        }

        if (! ctype_digit($yearLevel)) {
            return $yearLevel;
        }

        return match ($yearLevel) {
            '1' => 'First Year',
            '2' => 'Second Year',
            '3' => 'Third Year',
            default => 'Year '.$yearLevel,
        };
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        abort_unless(in_array($this->academicView, ['current', 'plan', 'record', 'graduation'], true), 404);
        $student = auth()->user()?->studentProfile;

        if (! $student instanceof StudentProfile) {
            return ['student' => null];
        }
        abort_if($this->academicView === 'graduation' && ! $this->hasGraduationHistory(), 404);

        $results = app(OfficialCourseResultProjection::class)->forStudent($student);
        $terms = $results->pluck('term')->filter()->unique('id')->sortByDesc('ends_on')->values();
        $currentEnrollment = app(CurrentOfficialEnrollmentResolver::class)->forProfile($student);
        $currentResults = $results->filter(fn (array $result): bool => $currentEnrollment !== null
            && (int) $result['course_enrollment']->enrollment_id === (int) $currentEnrollment->id
            && $result['course_enrollment']->is_current
            && $result['course_enrollment']->status === CourseEnrollment::StatusActive);
        $historicalResults = $results->except($currentResults->keys()->all());
        $examinationTerm = $currentEnrollment?->term ?? $terms->first();
        $termAverages = $terms->mapWithKeys(fn ($term): array => [
            $term->id => app(TermWeightedAverageProjection::class)->forStudentAndTerm($student, $term),
        ]);
        $termYearLevels = $terms->mapWithKeys(fn ($term): array => [
            $term->id => $this->yearLevelLabel($results->where('term.id', $term->id)
                ->map(fn (array $result): ?string => $result['course_enrollment']->termOffering?->curriculumEntry?->year_level)
                ->filter()
                ->first()),
        ]);

        $completion = app(CompletionReadinessProjection::class)->forStudent($student);
        $curriculum = app(CurriculumEvaluation::class)->forStudent($student);

        return [
            'student' => $student,
            'studyContext' => app(StudentStudyContext::class)->forStudent($this->actor()),
            'results' => $results,
            'recordTerms' => $terms,
            'currentResults' => $currentResults,
            'currentUnits' => $currentResults->sum(fn (array $result): float => (float) $result['units']),
            'currentAverage' => $currentEnrollment !== null ? $termAverages->get($currentEnrollment->term_id) : null,
            'historicalResults' => $historicalResults,
            'termAverages' => $termAverages,
            'termYearLevels' => $termYearLevels,
            'examinationPeriod' => app(ExaminationPeriodProjection::class)->forTerm($examinationTerm),
            'cumulative' => app(CumulativeGwaProjection::class)->forStudent($student),
            'curriculum' => $curriculum,
            'curriculumGroups' => collect($curriculum['required'])->groupBy('curriculum_entry.year_level')
                ->map(fn ($rows) => $rows->groupBy('curriculum_entry.term_label')),
            'planRequirements' => collect($curriculum['required'])->mapWithKeys(fn (array $row): array => [
                $row['curriculum_entry']->id => $row['curriculum_entry']->courseSpecification?->requirements
                    ->filter(fn (CourseRequirement $requirement): bool => $requirement->state === CourseRequirement::StateActive
                        && in_array($requirement->rule_type, [CourseRequirement::TypePrerequisite, CourseRequirement::TypeCorequisite], true)
                        && ($requirement->effective_from === null || $requirement->effective_from->lte(today()))
                        && ($requirement->effective_until === null || $requirement->effective_until->gte(today()))),
            ]),
            'effect' => app(AcademicEnrollmentEffect::class)->forStudent($student),
            'competencyResults' => ExternalCompetencyResult::query()
                ->where('student_profile_id', $student->id)
                ->with('requirement')
                ->latest('recorded_at')->get(),
            'lifecycleChanges' => $student->lifecycleChanges()->latest('effective_on')->get(),
            'enrollmentUrl' => Enrollment::getUrl(panel: 'student'),
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

    private function hasGraduationHistory(): bool
    {
        $student = $this->actor()->studentProfile;

        return $student instanceof StudentProfile
            && ($student->graduationApplications()->exists() || $student->degreeConferrals()->exists());
    }

    private function failedCompletionNotification(): ?OperationalEvent
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return null;
        }

        $event = OperationalEvent::query()
            ->where('user_id', $user->id)
            ->whereIn('event_type', [
                OperationalEvent::TypeCompletionRequiresActionEmail,
                OperationalEvent::TypeConferralRecordedEmail,
            ])
            ->where('status', OperationalEvent::StatusFailed)
            ->latest('occurred_at')
            ->first();

        return $event instanceof OperationalEvent ? $event : null;
    }
}
