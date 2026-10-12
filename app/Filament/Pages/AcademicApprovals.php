<?php

namespace App\Filament\Pages;

use App\Actions\Academics\ExaminationPeriodProjection;
use App\Actions\AcademicSetup\AcademicReadinessService;
use App\Actions\Calendar\TermCalendarPackageReadinessService;
use App\Filament\Resources\CurriculumVersions\CurriculumVersionResource;
use App\Filament\Resources\GradeRosters\GradeRosterResource;
use App\Filament\Resources\Programs\ProgramResource;
use App\Filament\Resources\StudentLifecycleChanges\StudentLifecycleChangeResource;
use App\Models\Program;
use App\Models\PublishedTimetableVersion;
use App\Models\ScheduleGenerationRun;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\User;
use App\Support\HasSearchableTermSelection;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

class AcademicApprovals extends Page
{
    use HasSearchableTermSelection;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationLabel = 'Academic Oversight';

    protected static ?string $title = 'Academic Oversight';

    protected string $view = 'filament.pages.academic-approvals';

    /** @var array<string, string> */
    protected array $extraBodyAttributes = ['class' => 'tala-academic-oversight-page'];

    #[Url(history: true)]
    public ?int $termId = null;

    public function mount(): void
    {
        if ($this->termId !== null && ! Term::query()->whereKey($this->termId)->exists()) {
            $this->termId = null;
        }
        if ($this->termId === null) {
            $activeIds = TermCalendarPackage::query()->where('state', TermCalendarPackage::StateActive)->distinct()->pluck('term_id');
            $this->termId = $activeIds->count() === 1 ? (int) $activeIds->sole() : null;
        }
    }

    public function selectTerm(int|string|null $termId): void
    {
        $selectedId = filled($termId) ? (int) $termId : null;
        abort_unless($selectedId === null || Term::query()->whereKey($selectedId)->exists(), 404);
        $this->termId = $selectedId;
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $term = $this->termId !== null ? Term::query()->with('academicYear')->find($this->termId) : null;
        $calendar = $term?->calendarPackages()->where('state', TermCalendarPackage::StateActive)->latest('version')->first();
        $calendarReadiness = $calendar ? app(TermCalendarPackageReadinessService::class)->for($calendar) : null;
        $timetable = $term ? PublishedTimetableVersion::query()->where('term_id', $term->id)
            ->where('state', PublishedTimetableVersion::StatePublished)->latest('version')->first() : null;
        $readiness = app(AcademicReadinessService::class);
        $programs = Program::query()->with([
            'curriculumVersions.entries.courseSpecification.components',
            'curriculumVersions.entries.courseSpecification.course',
        ])->orderBy('code')->get();
        $authorityRows = $programs->map(function (Program $program) use ($readiness): array {
            $curriculum = $readiness->currentCurriculum($program);

            return [
                'program' => $program,
                'curriculum' => $curriculum,
                'readiness' => $readiness->programReadiness($program),
                'url' => $curriculum
                    ? CurriculumVersionResource::getUrl('review', ['record' => $curriculum, 'oversightTermId' => $this->termId])
                    : ProgramResource::getUrl('view', ['record' => $program, 'oversightTermId' => $this->termId]),
            ];
        });
        $attention = [];
        $calendarUrl = TermCalendar::getUrl(['termId' => $this->termId]);
        if ($term && ! $calendar) {
            $attention[] = ['title' => 'Active calendar unavailable', 'detail' => 'No active Calendar Package is recorded for this Term.', 'owner' => 'Registrar', 'url' => $calendarUrl];
        }
        foreach ($calendarReadiness['blockers'] ?? [] as $blocker) {
            $attention[] = ['title' => $blocker['source'], 'detail' => $blocker['reason'], 'owner' => $blocker['owner'], 'url' => $calendarUrl];
        }
        $examination = $this->examinationPeriod();
        if ($term && $examination['status'] === 'Unavailable') {
            $attention[] = ['title' => 'Examination Period unavailable', 'detail' => $examination['message'], 'owner' => $examination['owner'], 'url' => $calendarUrl];
        }
        foreach ($authorityRows as $row) {
            if ($row['readiness']['status'] !== 'Ready for offerings') {
                $attention[] = [
                    'title' => $row['program']->code.' · '.$row['readiness']['status'],
                    'detail' => $row['readiness']['blocker'],
                    'owner' => 'Registrar',
                    'url' => $row['url'],
                ];
            }
        }

        $draft = $term ? ScheduleGenerationRun::query()->where('term_id', $term->id)->where('status', '!=', 'published')->latest('id')->first() : null;

        return compact('draft', 'term', 'calendar', 'calendarReadiness', 'timetable', 'authorityRows', 'attention', 'examination') + [
            'terms' => Term::query()->with('academicYear')->latest('starts_on')->get(),
            'checkedAt' => now('Asia/Manila'),
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole(User::StaffRoleAcademicHead) ?? false;
    }

    /** @return array<string, mixed> */
    public function examinationPeriod(): array
    {
        $term = $this->termId !== null ? Term::query()->find($this->termId) : null;

        if ($term === null) {
            return app(ExaminationPeriodProjection::class)->latest();
        }

        return app(ExaminationPeriodProjection::class)->forTerm($term);
    }

    /**
     * @return list<array{title: string, description: string, action: string, url: string, icon: string}>
     */
    public function approvalAreas(): array
    {
        return collect([
            [
                'title' => 'Academic Authority',
                'description' => 'Institution-wide Program and curriculum evidence.',
                'action' => 'Catalog & Curricula',
                'url' => CatalogCurriculaWorkbench::getUrl(['oversightTermId' => $this->termId]),
                'icon' => 'heroicon-o-book-open',
                'available' => CatalogCurriculaWorkbench::canAccess(),
            ],
            [
                'title' => 'Term Planning',
                'description' => 'Review the selected term and published planning evidence.',
                'action' => 'Review term planning',
                'url' => TermPlanningWorkbench::getUrl(['termId' => $this->termId]),
                'icon' => 'heroicon-o-calendar-days',
                'available' => TermPlanningWorkbench::canAccess(),
            ],
            [
                'title' => 'Grades & Progress',
                'description' => 'Recorded grade rosters and released results.',
                'action' => 'Grade Review',
                'url' => GradeRosterResource::getUrl('index', ['oversightTermId' => $this->termId]),
                'icon' => 'heroicon-o-document-check',
                'available' => GradeRosterResource::canAccess(),
            ],
            [
                'title' => 'Lifecycle & Completion',
                'description' => 'Recorded lifecycle changes and their institutional authority.',
                'action' => 'Lifecycle Exceptions',
                'url' => StudentLifecycleChangeResource::getUrl('index', ['oversightTermId' => $this->termId]),
                'icon' => 'heroicon-o-arrow-path-rounded-square',
                'available' => StudentLifecycleChangeResource::canAccess(),
            ],
        ])
            ->where('available', true)
            ->map(function (array $area): array {
                unset($area['available']);

                return $area;
            })
            ->values()
            ->all();
    }
}
