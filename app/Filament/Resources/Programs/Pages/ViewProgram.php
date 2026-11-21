<?php

namespace App\Filament\Resources\Programs\Pages;

use App\Filament\Pages\AcademicApprovals;
use App\Filament\Pages\CatalogCurriculaWorkbench;
use App\Filament\Resources\Courses\CourseResource;
use App\Filament\Resources\CurriculumVersions\CurriculumVersionResource;
use App\Filament\Resources\Programs\ProgramResource;
use App\Filament\Resources\Programs\RelationManagers\CurriculumVersionsRelationManager;
use App\Filament\Resources\Programs\Schemas\ProgramForm;
use App\Models\CourseComponent;
use App\Models\CourseRequirement;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Program;
use App\Models\Term;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

class ViewProgram extends ViewRecord
{
    protected static string $resource = ProgramResource::class;

    #[Url]
    public ?int $oversightTermId = null;

    public function getTitle(): string
    {
        $program = $this->getRecord();

        return $program instanceof Program ? $program->name : parent::getTitle();
    }

    public function getSubheading(): ?string
    {
        $program = $this->getRecord();

        if (! $program instanceof Program) {
            return null;
        }

        return collect([$program->code, $program->duration_years ? $program->duration_years.' years' : null, $program->is_active ? null : 'Inactive'])->filter()->implode(' · ');
    }

    public function getBreadcrumbs(): array
    {
        $program = $this->getRecord();
        $label = $program instanceof Program ? (string) $program->code : 'Program';
        $params = array_filter([
            'oversightTermId' => $this->oversightTermId,
        ], fn (mixed $value): bool => $value !== null);

        return [
            CatalogCurriculaWorkbench::getUrl($params) => 'Programs & Curricula',
            ProgramResource::getUrl('index', $params) => 'Programs',
            $label,
        ];
    }

    public function content(Schema $schema): Schema
    {
        $program = $this->getRecord();
        $components = [];

        if ($program instanceof Program && $program->curriculumVersions()->count() > 1) {
            $components[] = Livewire::make(CurriculumVersionsRelationManager::class, [
                'ownerRecord' => $program,
                'pageClass' => static::class,
            ])->key('program-curricula');
        }

        $components[] = View::make('filament.admin.catalog.program-active-subjects')
            ->viewData(fn (): array => $this->activeSubjectsViewData())
            ->columnSpanFull();

        return $schema->components($components);
    }

    /** @return array<string, mixed> */
    private function activeSubjectsViewData(): array
    {
        $program = $this->getRecord();
        abort_unless($program instanceof Program, 404);

        $activeCurriculum = $program->curriculumVersions()
            ->where('state', CurriculumVersion::StateActive)
            ->with([
                'entries' => fn ($query) => $query->orderBy('year_level')->orderBy('term_type')->orderBy('sequence')->orderBy('id'),
                'entries.courseSpecification.course',
                'entries.courseSpecification.components',
                'entries.courseSpecification.requirements.relatedCourse',
            ])
            ->latest('id')
            ->first();

        $latestDraft = $activeCurriculum instanceof CurriculumVersion
            ? null
            : $program->curriculumVersions()
                ->where('state', CurriculumVersion::StateDraft)
                ->latest('id')
                ->first();

        $yearProspectus = $activeCurriculum instanceof CurriculumVersion
            ? $this->buildYearProspectus($program, $activeCurriculum)
            : [];

        return [
            'program' => $program,
            'activeCurriculum' => $activeCurriculum,
            'latestDraft' => $latestDraft,
            'yearProspectus' => $yearProspectus,
            'oversightTermId' => $this->oversightTermId,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildYearProspectus(Program $program, CurriculumVersion $curriculum): array
    {
        $entriesByYear = $curriculum->entries->groupBy(fn (CurriculumEntry $entry): int => $this->parseYearNumber($entry->year_level));
        $maxEntryYear = $entriesByYear->keys()->filter(fn (int $year): bool => $year > 0)->max() ?? 0;
        $maxYear = max(1, min(8, max((int) ($program->duration_years ?: 4), (int) $maxEntryYear)));
        $componentTypes = CourseComponent::typeOptions();

        $years = [];

        for ($year = 1; $year <= $maxYear; $year++) {
            /** @var Collection<int, CurriculumEntry> $yearEntries */
            $yearEntries = $entriesByYear->get($year, collect());
            $termTypes = [
                Term::TypeFirstSemester => 'First Semester',
                Term::TypeSecondSemester => 'Second Semester',
            ];

            if ($yearEntries->contains(fn (CurriculumEntry $entry): bool => $entry->term_type === Term::TypeSpecialTerm)) {
                $termTypes[Term::TypeSpecialTerm] = 'Summer';
            }

            $semesters = [];

            foreach ($termTypes as $termType => $termLabel) {
                $semesterEntries = $yearEntries->where('term_type', $termType)->values();
                $totalUnits = $semesterEntries->sum(fn (CurriculumEntry $entry): float => (float) ($entry->courseSpecification?->credit_units ?? 0));

                $rows = $semesterEntries->map(function (CurriculumEntry $entry) use ($program, $curriculum, $componentTypes): array {
                    $spec = $entry->courseSpecification;
                    $course = $spec?->course;

                    $prerequisites = $spec instanceof CourseSpecification
                        ? $spec->requirements
                            ->where('rule_type', CourseRequirement::TypePrerequisite)
                            ->map(fn (CourseRequirement $requirement): ?string => $requirement->relatedCourse?->code)
                            ->filter()
                            ->implode(', ')
                        : '';

                    $hoursSummary = $spec instanceof CourseSpecification
                        ? $spec->components
                            ->sortBy('sequence')
                            ->map(fn (CourseComponent $component): string => ($componentTypes[$component->component_type] ?? $component->component_type).' '.$this->formatUnits($component->weekly_contact_hours).'h')
                            ->implode(' + ')
                        : '';

                    return [
                        'id' => $entry->id,
                        'code' => (string) ($course?->code ?? '—'),
                        'title' => (string) ($spec?->title ?? 'Untitled subject'),
                        'units' => $spec instanceof CourseSpecification ? $this->formatUnits($spec->credit_units) : '0',
                        'prerequisites' => $prerequisites !== '' ? $prerequisites : 'None',
                        'hours_summary' => $hoursSummary,
                        'url' => $course
                            ? CourseResource::getUrl('view', array_filter([
                                'record' => $course->id,
                                'programId' => $program->id,
                                'curriculumVersionId' => $curriculum->id,
                                'oversightTermId' => $this->oversightTermId,
                            ], fn (mixed $value): bool => $value !== null))
                            : null,
                    ];
                })->all();

                $semesters[] = [
                    'term_type' => $termType,
                    'label' => $termLabel,
                    'subject_count' => $semesterEntries->count(),
                    'total_units' => $this->formatUnits($totalUnits),
                    'rows' => $rows,
                ];
            }

            $years[] = [
                'year' => $year,
                'tab_label' => $this->shortOrdinalYearLabel($year),
                'heading' => $this->fullOrdinalYearLabel($year),
                'subject_count' => $yearEntries->count(),
                'total_units' => $this->formatUnits($yearEntries->sum(fn (CurriculumEntry $entry): float => (float) ($entry->courseSpecification?->credit_units ?? 0))),
                'semesters' => $semesters,
            ];
        }

        return $years;
    }

    private function parseYearNumber(mixed $yearLevel): int
    {
        if (is_int($yearLevel)) {
            return $yearLevel;
        }

        $normalized = strtolower(trim((string) $yearLevel));

        if (preg_match('/\d+/', $normalized, $matches) === 1) {
            return (int) $matches[0];
        }

        return match (true) {
            str_contains($normalized, 'first') => 1,
            str_contains($normalized, 'second') => 2,
            str_contains($normalized, 'third') => 3,
            str_contains($normalized, 'fourth') => 4,
            str_contains($normalized, 'fifth') => 5,
            default => 1,
        };
    }

    private function shortOrdinalYearLabel(int $year): string
    {
        return match ($year) {
            1 => '1st Year',
            2 => '2nd Year',
            3 => '3rd Year',
            default => "{$year}th Year",
        };
    }

    private function fullOrdinalYearLabel(int $year): string
    {
        return match ($year) {
            1 => 'First Year',
            2 => 'Second Year',
            3 => 'Third Year',
            4 => 'Fourth Year',
            5 => 'Fifth Year',
            default => "Year {$year}",
        };
    }

    private function formatUnits(float|int|string $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }

    protected function getHeaderActions(): array
    {
        $program = $this->getRecord();
        abort_unless($program instanceof Program, 404);

        $oversightParameters = array_filter([
            'termId' => $this->oversightTermId,
        ], fn (mixed $value): bool => $value !== null);

        $activeCurriculum = $program->curriculumVersions()
            ->where('state', CurriculumVersion::StateActive)
            ->latest('id')
            ->first();

        $latestDraft = $activeCurriculum instanceof CurriculumVersion
            ? null
            : $program->curriculumVersions()
                ->where('state', CurriculumVersion::StateDraft)
                ->latest('id')
                ->first();

        $targetCurriculum = $activeCurriculum ?? $latestDraft;
        $hasAnyCurriculum = $program->curriculumVersions()->exists();

        return [
            Action::make('returnToOversight')
                ->label('Return to Academic Oversight')
                ->icon(Heroicon::OutlinedArrowLeft)
                ->color('gray')
                ->url(AcademicApprovals::getUrl($oversightParameters))
                ->visible(fn (): bool => auth()->user()?->hasRole(User::StaffRoleAcademicHead) ?? false),
            Action::make('openActiveCurriculum')
                ->label($activeCurriculum instanceof CurriculumVersion ? 'Open active curriculum' : 'Open draft curriculum')
                ->icon('heroicon-m-arrow-right')
                ->iconPosition('after')
                ->color('primary')
                ->url(fn (): string => $targetCurriculum instanceof CurriculumVersion
                    ? CurriculumVersionResource::getUrl('review', array_filter([
                        'record' => $targetCurriculum->id,
                        'programId' => $program->id,
                        'oversightTermId' => $this->oversightTermId,
                    ], fn (mixed $value): bool => $value !== null))
                    : '')
                ->visible(fn (): bool => $targetCurriculum instanceof CurriculumVersion),
            CurriculumVersionResource::createDraftAction($program->id, $this->oversightTermId, 'createCurriculum')
                ->label($hasAnyCurriculum ? 'New curriculum' : 'Create curriculum')
                ->icon(Heroicon::Plus)
                ->color($targetCurriculum instanceof CurriculumVersion ? 'gray' : 'primary')
                ->visible(fn (): bool => auth()->user()?->can('create', CurriculumVersion::class) ?? false),
            EditAction::make()
                ->label('Edit program details')
                ->color('gray')
                ->modal()
                ->modalHeading('Edit program details')
                ->modalDescription('Update program code, name, length, or active status.')
                ->modalWidth(Width::TwoExtraLarge)
                ->stickyModalHeader()
                ->stickyModalFooter()
                ->schema(fn (Schema $schema): Schema => ProgramForm::configure($schema))
                ->visible(fn (): bool => auth()->user()?->can('update', $program) ?? false),
        ];
    }
}
