<?php

namespace App\Filament\Student\Pages;

use App\Actions\Academics\AcademicEnrollmentGuidance;
use App\Actions\Academics\AcademicRecordNotificationService;
use App\Actions\Academics\AcademicYearGwaProjection;
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
use App\Models\ExternalCompetencyRequirement;
use App\Models\ExternalCompetencyResult;
use App\Models\OperationalEvent;
use App\Models\PriorAcademicCredit;
use App\Models\StudentProfile;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

class Academics extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?string $navigationLabel = 'Grades';

    protected static ?string $title = 'Grades';

    protected string $view = 'filament.student.pages.academics';

    protected Width|string|null $maxContentWidth = Width::Full;

    #[Url(as: 'view', except: 'record', history: true)]
    public string $academicView = 'record';

    #[Url]
    public ?int $change = null;

    #[Url]
    public ?int $enrollmentTerm = null;

    public function mount(): void
    {
        if ($this->academicView === 'current') {
            $this->academicView = 'record';
        }

        $student = $this->actor()->studentProfile;
        if ($this->change !== null
            && $student instanceof StudentProfile
            && $student->lifecycle_status !== StudentProfile::LifecycleArchived) {
            $this->redirect(
                Profile::getUrl(['change' => $this->change], panel: 'student').'#school-record-changes',
                navigate: false,
            );
        }
    }

    public function getTitle(): string
    {
        return match ($this->academicView) {
            'plan' => 'Study plan',
            'graduation' => 'Graduation records',
            default => 'Grades',
        };
    }

    /** @return array<string|int, string> */
    public function getBreadcrumbs(): array
    {
        return in_array($this->academicView, ['record', 'current'], true)
            ? [Dashboard::getUrl(panel: 'student') => 'Overview', 'Grades']
            : [
                Dashboard::getUrl(panel: 'student') => 'Overview',
                static::getUrl(panel: 'student') => 'Grades',
                $this->getTitle(),
            ];
    }

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        if (in_array($this->academicView, ['record', 'current'], true)) {
            return [
                Action::make('graduation')->label('Graduation records')->color('gray')
                    ->url(static::getUrl(['view' => 'graduation'], panel: 'student'))
                    ->visible($this->hasGraduationHistory()),
            ];
        }

        return [];
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
            ->modalWidth(Width::Large)
            ->stickyModalHeader()
            ->stickyModalFooter()
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
        if ($this->academicView === 'current') {
            $this->academicView = 'record';
        }

        abort_unless(in_array($this->academicView, ['plan', 'record', 'graduation'], true), 404);
        $student = auth()->user()?->studentProfile;

        if (! $student instanceof StudentProfile) {
            return ['student' => null];
        }
        abort_if($this->academicView === 'graduation' && ! $this->hasGraduationHistory(), 404);

        $results = app(OfficialCourseResultProjection::class)->forStudent($student);
        $terms = $results->pluck('term')->filter()->unique('id')->sortByDesc('ends_on')->values();
        $currentEnrollment = app(CurrentOfficialEnrollmentResolver::class)->forProfile($student);
        $currentResults = $results->filter(fn (array $result): bool => $currentEnrollment !== null
            && $result['course_enrollment'] instanceof CourseEnrollment
            && (int) $result['course_enrollment']->enrollment_id === (int) $currentEnrollment->id
            && $result['course_enrollment']->is_current
            && $result['course_enrollment']->status === CourseEnrollment::StatusActive);
        $historicalResults = $results->except($currentResults->keys()->all());
        $examinationTerm = $currentEnrollment?->term ?? $terms->first();
        $termAverageProjection = app(TermWeightedAverageProjection::class);
        $academicYearGwaProjection = app(AcademicYearGwaProjection::class);
        $termAverages = $terms->mapWithKeys(fn ($term): array => [
            $term->id => $termAverageProjection->forResults($results, $term),
        ]);
        $termYearLevels = $terms->mapWithKeys(fn ($term): array => [
            $term->id => $this->yearLevelLabel($results->where('term.id', $term->id)
                ->map(function (array $result): ?string {
                    if ($result['course_enrollment'] instanceof CourseEnrollment) {
                        return $result['course_enrollment']->termOffering?->curriculumEntry?->year_level;
                    }

                    $yearLevel = $result['year_level'] ?? null;

                    return $yearLevel !== null ? (string) $yearLevel : null;
                })
                ->filter()
                ->first()),
        ]);
        $recordPeriods = $results
            ->groupBy(fn (array $result): string => (string) ($result['period_key'] ?? ('term:'.(int) ($result['term']?->id ?? 0))))
            ->sortByDesc(fn (Collection $periodResults): string => (string) ($periodResults->first()['period_sort_key'] ?? ''))
            ->map(function (Collection $periodResults, string $periodKey) use ($termAverageProjection): array {
                $first = $periodResults->first();
                $label = (string) ($first['period_label'] ?? $first['term']?->label ?? 'Recorded Term');
                $yearLevelValue = $periodResults
                    ->map(function (array $result): ?string {
                        $level = $result['year_level'] ?? ($result['course_enrollment'] instanceof CourseEnrollment
                            ? $result['course_enrollment']->termOffering?->curriculumEntry?->year_level
                            : null);

                        return $level !== null && $level !== '' ? (string) $level : null;
                    })
                    ->filter()
                    ->first();
                $yearLevelLabel = $this->yearLevelLabel($yearLevelValue);
                $ayLabel = trim((string) ($first['academic_year_label'] ?? $first['term']?->academicYear?->label ?? ''));
                $ayLabel = $ayLabel !== '' ? $ayLabel : null;
                $subtitle = collect([$yearLevelLabel, $ayLabel])->filter()->implode(' · ');
                $average = $termAverageProjection->forPeriodResults($periodResults, $label);
                $average['label'] = 'Term GPA';

                return [
                    'key' => $periodKey,
                    'label' => $label,
                    'academic_year_label' => $ayLabel ?? 'Recorded academic year',
                    'sort_key' => (string) ($first['period_sort_key'] ?? ''),
                    'subtitle' => $subtitle,
                    'rows' => $periodResults->values(),
                    'average' => $average,
                ];
            })
            ->values();

        $recordAcademicYears = $recordPeriods
            ->groupBy(fn (array $period): string => (string) $period['academic_year_label'])
            ->sortByDesc(fn (Collection $yearPeriods): string => (string) $yearPeriods->max('sort_key'))
            ->map(function (Collection $yearPeriods, string $yearLabel) use ($academicYearGwaProjection): array {
                $yearRows = $yearPeriods->flatMap(fn (array $period): Collection => $period['rows'])->values();

                return [
                    'label' => $yearLabel,
                    'periods' => $yearPeriods->values(),
                    'gwa' => $academicYearGwaProjection->forYearResults($yearRows, $yearLabel),
                ];
            })
            ->values();

        $completion = $this->academicView === 'graduation' ? app(CompletionReadinessProjection::class)->forStudent($student) : null;
        $curriculum = app(CurriculumEvaluation::class)->forStudent($student);
        $guidance = app(AcademicEnrollmentGuidance::class)->forStudent($student);

        return [
            'student' => $student,
            'studyContext' => app(StudentStudyContext::class)->forStudent($this->actor()),
            'hasPriorCredits' => $results->contains(fn (array $result): bool => ($result['prior_credit'] ?? null) instanceof PriorAcademicCredit),
            'results' => $results,
            'recordTerms' => $terms,
            'recordPeriods' => $recordPeriods,
            'recordAcademicYears' => $recordAcademicYears,
            'currentResults' => $currentResults,
            'currentUnits' => $currentResults->sum(fn (array $result): float => (float) $result['units']),
            'currentAverage' => $currentEnrollment !== null ? $termAverages->get($currentEnrollment->term_id) : null,
            'historicalResults' => $historicalResults,
            'termAverages' => $termAverages,
            'termYearLevels' => $termYearLevels,
            'examinationPeriod' => app(ExaminationPeriodProjection::class)->forTerm($examinationTerm),
            'cumulative' => app(CumulativeGwaProjection::class)->forStudent($student),
            'curriculum' => $curriculum,
            'curriculumGroups' => collect($curriculum['required'])->groupBy(fn (array $row): string => $this->yearLevelLabel($row['curriculum_entry']->year_level) ?? 'Year not recorded')
                ->map(fn ($rows) => $rows->groupBy('curriculum_entry.term_label')),
            'planRequirements' => collect($curriculum['required'])->mapWithKeys(fn (array $row): array => [
                $row['curriculum_entry']->id => $row['curriculum_entry']->courseSpecification?->requirements
                    ->filter(fn (CourseRequirement $requirement): bool => $requirement->state === CourseRequirement::StateActive
                        && in_array($requirement->rule_type, [CourseRequirement::TypePrerequisite, CourseRequirement::TypeCorequisite], true)),
            ]),
            'effect' => $guidance,
            'competencyRequirements' => $this->academicView === 'plan'
                ? ExternalCompetencyRequirement::query()->applicableTo($student)
                    ->withCurrentResultFor($student)->orderBy('requirement_code')->get() : collect(),
            'competencyResults' => $this->academicView === 'record'
                ? ExternalCompetencyResult::query()->where('student_profile_id', $student->id)
                    ->with('requirement:id,qualification_label,qualification_level')
                    ->latest('recorded_at')->orderByDesc('id')
                    ->get(['id', 'external_competency_requirement_id', 'outcome', 'assessment_date', 'recorded_at', 'is_current']) : collect(),
            'enrollmentUrl' => Enrollment::getUrl(array_filter(['term' => $guidance['term_id']]), panel: 'student'),
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
