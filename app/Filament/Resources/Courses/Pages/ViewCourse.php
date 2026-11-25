<?php

namespace App\Filament\Resources\Courses\Pages;

use App\Actions\AcademicSetup\CourseSpecificationLifecycleService;
use App\Filament\Pages\AcademicApprovals;
use App\Filament\Pages\CatalogCurriculaWorkbench;
use App\Filament\Resources\Courses\CourseResource;
use App\Filament\Resources\Courses\Schemas\CourseInfolist;
use App\Filament\Resources\CourseSpecifications\Schemas\CourseSpecificationForm;
use App\Filament\Resources\CurriculumVersions\CurriculumVersionResource;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseRequirement;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Section;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;

class ViewCourse extends ViewRecord
{
    protected static string $resource = CourseResource::class;

    #[Url]
    public ?int $oversightTermId = null;

    #[Url]
    public ?int $curriculumVersionId = null;

    #[Url]
    public ?int $programId = null;

    #[Url]
    public ?int $year = null;

    #[Url(as: 'revision')]
    public ?int $selectedRevisionId = null;

    public string $usageSearch = '';

    public string $usageProgramFilter = '';

    public string $usageStatusFilter = '';

    public int $usagePage = 1;

    public function updatedUsageSearch(): void
    {
        $this->usagePage = 1;
    }

    public function updatedUsageProgramFilter(): void
    {
        $this->usagePage = 1;
    }

    public function updatedUsageStatusFilter(): void
    {
        $this->usagePage = 1;
    }

    public function resetUsageFilters(): void
    {
        $this->usageSearch = '';
        $this->usageProgramFilter = '';
        $this->usageStatusFilter = '';
        $this->usagePage = 1;
    }

    public function setUsagePage(int $page): void
    {
        $this->usagePage = max(1, $page);
    }

    /**
     * @return array<string, mixed>
     */
    public function curriculumUsageData(): array
    {
        $record = $this->getRecord();

        if (! $record instanceof Course) {
            return [
                'rows' => collect(),
                'programOptions' => [],
                'totalCount' => 0,
                'filteredCount' => 0,
                'perPage' => CourseInfolist::UsagePerPage,
                'currentPage' => 1,
                'lastPage' => 1,
                'from' => 0,
                'to' => 0,
                'hasFilters' => false,
            ];
        }

        return CourseInfolist::curriculumUsage(
            course: $record,
            search: $this->usageSearch,
            programFilter: $this->usageProgramFilter,
            statusFilter: $this->usageStatusFilter,
            page: $this->usagePage,
        );
    }

    public function selectedRevision(): ?CourseSpecification
    {
        $course = $this->getRecord();
        $course->loadMissing('specifications.components', 'specifications.requirements.relatedCourse');

        if ($this->selectedRevisionId !== null) {
            return $course->specifications->firstWhere('id', $this->selectedRevisionId)
                ?? abort(404);
        }

        return $course->specifications->where('state', CourseSpecification::StateActive)->sortByDesc('id')->first()
            ?? $course->specifications->sortByDesc('id')->first();
    }

    public function selectRevision(int $revisionId): void
    {
        $revision = $this->getRecord()->specifications()->findOrFail($revisionId);
        Gate::authorize('view', $revision);
        $this->selectedRevisionId = $revision->id;
    }

    public function getTitle(): string
    {
        $course = $this->getRecord();
        if (! $course instanceof Course) {
            return parent::getTitle();
        }

        $spec = $this->selectedRevision();

        return $spec instanceof CourseSpecification
            ? "{$course->code} · {$spec->title}"
            : (string) $course->code;
    }

    public function getBreadcrumbs(): array
    {
        $course = $this->getRecord();
        $params = array_filter([
            'oversightTermId' => $this->oversightTermId,
        ], fn (mixed $value): bool => $value !== null);

        return [
            CatalogCurriculaWorkbench::getUrl($params) => 'Programs & Curricula',
            CourseResource::getUrl('index', $params) => 'Subjects',
            $course instanceof Course ? $course->code : 'Course',
        ];
    }

    protected function getHeaderActions(): array
    {
        $course = $this->getRecord();
        $oversightParameters = array_filter([
            'termId' => $this->oversightTermId,
        ], fn (mixed $value): bool => $value !== null);

        return [
            Action::make('returnToOversight')
                ->label('Return to Academic Oversight')
                ->icon(Heroicon::OutlinedArrowLeft)
                ->color('gray')
                ->url(AcademicApprovals::getUrl($oversightParameters))
                ->visible(fn (): bool => auth()->user()?->hasRole(User::StaffRoleAcademicHead) ?? false),
            Action::make('returnToCurriculumSheet')->label('Return to curriculum')
                ->color('gray')->icon(Heroicon::OutlinedArrowLeft)
                ->visible(fn (): bool => $this->curriculumVersionId !== null && CurriculumVersion::query()->whereKey($this->curriculumVersionId)->exists())
                ->url(fn (): string => CurriculumVersionResource::getUrl('review', array_filter([
                    'record' => $this->curriculumVersionId,
                    'programId' => $this->programId,
                    'year' => $this->year,
                    'oversightTermId' => $this->oversightTermId,
                ], fn (mixed $value): bool => $value !== null))),
            $this->reviseSubjectAction()
                ->visible(fn (): bool => $this->selectedRevision() !== null && $this->selectedRevision()?->state !== CourseSpecification::StateDraft),
            $this->createRevisionAction()
                ->visible(fn (): bool => $this->selectedRevision() === null),
            $this->editCourseDetailsAction(),
            $this->retireCourseAction(),
            $this->reactivateCourseAction(),
            $this->deleteCourseAction(),
        ];
    }

    public function deleteCourseAction(): Action
    {
        return Action::make('deleteCourse')
            ->label('Delete')
            ->color('danger')
            ->icon(Heroicon::OutlinedTrash)
            ->requiresConfirmation()
            ->modalHeading(fn (): string => ($course = $this->getRecord()) instanceof Course ? "Delete draft {$course->code}?" : 'Delete draft subject?')
            ->modalDescription('Only unused, unpublished draft subjects can be permanently deleted. Published (Active), retired, or referenced subjects cannot be deleted.')
            ->modalSubmitActionLabel('Delete draft subject')
            ->authorize(fn (): bool => ($course = $this->getRecord()) instanceof Course
                && (auth()->user()?->can('create', Course::class) ?? false)
                && CourseResource::canDeleteUnusedSubject($course))
            ->visible(fn (): bool => ($course = $this->getRecord()) instanceof Course
                && (auth()->user()?->can('create', Course::class) ?? false)
                && CourseResource::canDeleteUnusedSubject($course))
            ->action(function (): void {
                $course = $this->getRecord();
                $actor = auth()->user();
                abort_unless($course instanceof Course, 404);
                abort_unless($actor instanceof User, 403);

                try {
                    CourseResource::deleteUnusedSubject($course, $actor);
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->title('Subject cannot be deleted')
                        ->body(collect($exception->errors())->flatten()->implode(' '))
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Subject deleted')
                    ->success()
                    ->send();

                $params = array_filter([
                    'oversightTermId' => $this->oversightTermId,
                ], fn (mixed $value): bool => $value !== null);

                $this->redirect(CourseResource::getUrl('index', $params));
            });
    }

    public function editCourseDetailsAction(): Action
    {
        return Action::make('editCourseDetails')
            ->label('Edit code')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('gray')
            ->modalHeading('Edit subject code')
            ->modalDescription('Update the subject code used across curricula and schedules. Use Retire or Reactivate to change availability.')
            ->modalSubmitActionLabel('Save code')
            ->modalWidth(Width::Medium)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->authorize(fn (): bool => auth()->user()?->can('update', $this->getRecord()) ?? false)
            ->visible(fn (): bool => auth()->user()?->can('update', $this->getRecord()) ?? false)
            ->fillForm(function (): array {
                $course = $this->getRecord();
                abort_unless($course instanceof Course, 404);

                return [
                    'code' => $course->code,
                ];
            })
            ->schema([
                TextInput::make('code')
                    ->label('Subject code')
                    ->required()
                    ->maxLength(64)
                    ->unique(
                        table: Course::class,
                        column: 'code',
                        ignorable: fn (): ?Course => $this->getRecord() instanceof Course ? $this->getRecord() : null,
                    )
                    ->helperText('Example: IT 101, GE 101'),
            ])
            ->action(function (array $data): void {
                $course = $this->getRecord();
                $actor = auth()->user();
                abort_unless($course instanceof Course, 404);
                abort_unless($actor instanceof User, 403);
                Gate::forUser($actor)->authorize('update', $course);

                $normalizedCode = strtoupper(trim((string) ($data['code'] ?? '')));
                $validated = Validator::make([
                    'code' => $normalizedCode,
                ], [
                    'code' => [
                        'required',
                        'string',
                        'max:64',
                        Rule::unique('courses', 'code')->ignore($course->id),
                    ],
                ])->validate();

                $course->update($validated);
                $course->refresh();

                Notification::make()
                    ->title('Subject code updated')
                    ->success()
                    ->send();
            });
    }

    public function retireCourseAction(): Action
    {
        return Action::make('retireCourse')
            ->label('Retire')
            ->color('gray')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->authorize(fn (): bool => ($course = $this->getRecord()) instanceof Course
                && $course->state === Course::StateActive
                && (auth()->user()?->can('update', $course) ?? false))
            ->visible(fn (): bool => ($course = $this->getRecord()) instanceof Course
                && $course->state === Course::StateActive
                && (auth()->user()?->can('update', $course) ?? false))
            ->requiresConfirmation()
            ->modalHeading(fn (): string => ($course = $this->getRecord()) instanceof Course ? "Retire {$course->code}?" : 'Retire subject?')
            ->modalDescription(function (): string {
                $course = $this->getRecord();
                $curriculumCount = $course instanceof Course ? $this->referencedCurriculaCount($course) : 0;

                if ($curriculumCount > 0) {
                    $curriculumWord = Str::plural('curriculum', $curriculumCount);

                    return "This subject is currently referenced in {$curriculumCount} {$curriculumWord}. Existing published curricula remain intact, while retired subjects are hidden from new curriculum assignments.";
                }

                return 'This subject is not currently referenced in any curriculum. Existing published curricula remain intact, while retired subjects are hidden from new curriculum assignments.';
            })
            ->modalSubmitActionLabel('Retire')
            ->action(function (): void {
                $course = $this->getRecord();
                $actor = auth()->user();
                abort_unless($course instanceof Course, 404);
                abort_unless($actor instanceof User, 403);
                Gate::forUser($actor)->authorize('update', $course);

                $curriculumCount = $this->referencedCurriculaCount($course);

                $course->update(['state' => Course::StateRetired]);
                $course->refresh();
                $this->record = $course;

                activity()
                    ->performedOn($course)
                    ->causedBy($actor)
                    ->event('course_retired')
                    ->withProperties([
                        'course_code' => $course->code,
                        'referenced_curricula_count' => $curriculumCount,
                    ])
                    ->log('Subject retired');

                unset(
                    $this->cachedActions['retireCourse'],
                    $this->cachedActions['reactivateCourse'],
                );

                Notification::make()
                    ->title("{$course->code} retired")
                    ->body('Existing published curricula remain intact. This subject is now hidden from new curriculum assignments.')
                    ->success()
                    ->send();
            });
    }

    public function reactivateCourseAction(): Action
    {
        return Action::make('reactivateCourse')
            ->label('Reactivate')
            ->color('gray')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->authorize(fn (): bool => ($course = $this->getRecord()) instanceof Course
                && $course->state === Course::StateRetired
                && (auth()->user()?->can('update', $course) ?? false))
            ->visible(fn (): bool => ($course = $this->getRecord()) instanceof Course
                && $course->state === Course::StateRetired
                && (auth()->user()?->can('update', $course) ?? false))
            ->requiresConfirmation()
            ->modalHeading(fn (): string => ($course = $this->getRecord()) instanceof Course ? "Reactivate {$course->code}?" : 'Reactivate subject?')
            ->modalDescription(fn (): string => ($course = $this->getRecord()) instanceof Course
                ? "Reactivate {$course->code}? It will become available again for new curriculum assignments."
                : 'This subject will become available again for new curriculum assignments.')
            ->modalSubmitActionLabel('Reactivate')
            ->action(function (): void {
                $course = $this->getRecord();
                $actor = auth()->user();
                abort_unless($course instanceof Course, 404);
                abort_unless($actor instanceof User, 403);
                Gate::forUser($actor)->authorize('update', $course);

                $course->update(['state' => Course::StateActive]);
                $course->refresh();
                $this->record = $course;

                activity()
                    ->performedOn($course)
                    ->causedBy($actor)
                    ->event('course_reactivated')
                    ->withProperties([
                        'course_code' => $course->code,
                    ])
                    ->log('Subject reactivated');

                unset(
                    $this->cachedActions['retireCourse'],
                    $this->cachedActions['reactivateCourse'],
                );

                Notification::make()
                    ->title("{$course->code} reactivated")
                    ->body('This subject is now active and available for new curriculum assignments.')
                    ->success()
                    ->send();
            });
    }

    private function referencedCurriculaCount(Course $course): int
    {
        return (int) CurriculumEntry::query()
            ->whereHas('courseSpecification', fn ($query) => $query->where('course_id', $course->id))
            ->distinct('curriculum_version_id')
            ->count('curriculum_version_id');
    }

    public function createRevisionAction(): CreateAction
    {
        return CreateAction::make('createRevision')->label('Add teaching details')
            ->model(CourseSpecification::class)
            ->authorize(fn (): bool => auth()->user()?->can('create', CourseSpecification::class) ?? false)
            ->schema(fn (Schema $schema): Schema => CourseSpecificationForm::configure($schema, $this->getRecord()->id))
            ->mutateDataUsing(fn (array $data): array => [...$data,
                'course_id' => $this->getRecord()->id,
                'revision_code' => (string) Str::ulid(),
                'state' => CourseSpecification::StateDraft,
                'grading_profile_key' => CourseSpecification::GradingProfileServitechV1, 'grading_profile_version' => 1,
            ])
            ->modalHeading('Add teaching details')->modalSubmitActionLabel('Create draft')
            ->modalWidth(Width::FiveExtraLarge)->stickyModalHeader()->stickyModalFooter()
            ->databaseTransaction()->createAnother(false)
            ->after(fn (CourseSpecification $record) => $this->refreshRevision($record->id));
    }

    public function editRevisionAction(): EditAction
    {
        return EditAction::make('editRevision')->label('Edit draft')->icon(Heroicon::OutlinedPencilSquare)->color('gray')
            ->record(fn (): ?CourseSpecification => $this->selectedRevision())
            ->authorize(fn (): bool => ($record = $this->selectedRevision()) instanceof CourseSpecification && (auth()->user()?->can('update', $record) ?? false))
            ->visible(fn (): bool => ($record = $this->selectedRevision()) instanceof CourseSpecification && $record->state === CourseSpecification::StateDraft)
            ->before(function (CourseSpecification $record): void {
                $locked = CourseSpecification::query()->lockForUpdate()->findOrFail($record->id);
                Gate::authorize('update', $locked);
            })
            ->schema(fn (Schema $schema): Schema => CourseSpecificationForm::configure($schema, $this->getRecord()->id))
            ->mutateDataUsing(fn (array $data, CourseSpecification $record): array => [...$data,
                'course_id' => $record->course_id, 'revision_code' => $record->revision_code,
                'state' => $record->state, 'grading_profile_key' => $record->grading_profile_key,
                'grading_profile_version' => $record->grading_profile_version,
            ])
            ->modalHeading('Edit subject')->modalSubmitActionLabel('Save changes')
            ->modalWidth(Width::FiveExtraLarge)->stickyModalHeader()->stickyModalFooter()->databaseTransaction()
            ->after(fn (CourseSpecification $record) => $this->refreshRevision($record->id));
    }

    public function reviseSubjectAction(): Action
    {
        return Action::make('reviseSubject')->label('Edit teaching details')->icon(Heroicon::OutlinedDocumentDuplicate)
            ->record(fn (): ?CourseSpecification => $this->selectedRevision())
            ->authorize(fn (): bool => ($record = $this->selectedRevision()) instanceof CourseSpecification && (auth()->user()?->can('copy', $record) ?? false))
            ->requiresConfirmation()
            ->modalHeading(fn (): string => $this->existingDraftRevision() !== null ? 'Open existing draft?' : 'Edit teaching details?')
            ->modalWidth(Width::Large)->stickyModalHeader()->stickyModalFooter()
            ->modalDescription(fn (): string => $this->existingDraftRevision() !== null
                ? 'A draft revision already exists for this subject. Open it to continue editing without creating a duplicate draft.'
                : 'Creates an editable draft copy of units, weekly meetings, and prerequisites and opens the editor without altering active student records.')
            ->modalSubmitActionLabel(fn (): string => $this->existingDraftRevision() !== null ? 'Open existing draft' : 'Create editable draft')
            ->action(function (CourseSpecification $record): void {
                $existingDraft = CourseSpecification::query()
                    ->where('course_id', $record->course_id)
                    ->where('state', CourseSpecification::StateDraft)
                    ->orderByDesc('id')
                    ->first();

                if ($existingDraft instanceof CourseSpecification) {
                    $this->refreshRevision($existingDraft->id);
                    Notification::make()->title('Opened existing draft to edit')->success()->send();

                    return;
                }

                $draft = app(CourseSpecificationLifecycleService::class)->copyToDraft(
                    auth()->user(), $record, (string) Str::ulid(),
                );
                $this->refreshRevision($draft->id);
                Notification::make()->title('Draft ready to edit')->success()->send();
                $this->replaceMountedAction('editRevision');
            });
    }

    public function activateRevisionAction(): Action
    {
        return Action::make('activateRevision')->label('Activate changes')->color('primary')
            ->record(fn (): ?CourseSpecification => $this->selectedRevision())
            ->authorize(fn (): bool => ($record = $this->selectedRevision()) instanceof CourseSpecification && (auth()->user()?->can('activate', $record) ?? false))
            ->visible(fn (): bool => ($record = $this->selectedRevision()) instanceof CourseSpecification && $record->state === CourseSpecification::StateDraft)
            ->requiresConfirmation()->modalHeading('Activate these teaching details?')
            ->modalWidth(Width::Large)->stickyModalHeader()->stickyModalFooter()
            ->modalDescription('These details become read-only once active. Existing curriculum assignments stay unchanged.')
            ->action(function (CourseSpecification $record): void {
                $activated = app(CourseSpecificationLifecycleService::class)->activate(auth()->user(), $record);
                $this->refreshRevision($activated->id);
                Notification::make()->title('Subject changes activated')->success()->send();
            });
    }

    public function discardDraftAction(): Action
    {
        return Action::make('discardDraft')
            ->label('Discard draft')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->outlined()
            ->record(fn (): ?CourseSpecification => $this->selectedRevision())
            ->authorize(fn (): bool => ($record = $this->selectedRevision()) instanceof CourseSpecification && (auth()->user()?->can('update', $record) ?? false))
            ->visible(fn (): bool => $this->canDiscardDraft($this->selectedRevision()))
            ->requiresConfirmation()
            ->modalHeading('Discard this draft?')
            ->modalDescription('Permanently removes this unpublished draft and returns to the active teaching details. Active records are not affected.')
            ->modalSubmitActionLabel('Discard draft')
            ->modalWidth(Width::Large)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->action(function (CourseSpecification $record): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);

                try {
                    app(CourseSpecificationLifecycleService::class)->discardDraft($actor, $record);
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->title('Cannot discard draft')
                        ->body((string) (collect($exception->errors())->flatten()->first() ?? 'This draft cannot be discarded.'))
                        ->danger()
                        ->send();

                    return;
                }

                $course = $this->getRecord();
                $fallbackId = $course instanceof Course
                    ? ($course->specifications()->where('state', CourseSpecification::StateActive)->orderByDesc('id')->value('id')
                        ?? $course->specifications()->orderByDesc('id')->value('id'))
                    : null;

                $this->refreshRevision(is_numeric($fallbackId) ? (int) $fallbackId : null);

                Notification::make()
                    ->title('Draft discarded')
                    ->success()
                    ->send();
            });
    }

    public function canDiscardDraft(?CourseSpecification $spec): bool
    {
        if (! $spec instanceof CourseSpecification || $spec->state !== CourseSpecification::StateDraft) {
            return false;
        }

        if (! (auth()->user()?->can('update', $spec) ?? false)) {
            return false;
        }

        return ! $spec->curriculumEntries()->exists()
            && ! Section::query()->where('course_specification_id', $spec->id)->exists();
    }

    /**
     * Compare a Draft revision against the course's latest Active revision.
     *
     * @return array{has_active: bool, changes: list<string>}
     */
    public function draftChangesSummary(?CourseSpecification $spec): array
    {
        if (! $spec instanceof CourseSpecification || $spec->state !== CourseSpecification::StateDraft) {
            return ['has_active' => false, 'changes' => []];
        }

        $course = $this->getRecord();

        if (! $course instanceof Course) {
            return ['has_active' => false, 'changes' => []];
        }

        $course->loadMissing('specifications.components', 'specifications.requirements.relatedCourse');
        $active = $course->specifications
            ->where('state', CourseSpecification::StateActive)
            ->sortByDesc('id')
            ->first();

        if (! $active instanceof CourseSpecification) {
            return ['has_active' => false, 'changes' => []];
        }

        $changes = [];
        $formatUnits = static fn (mixed $value): string => rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');

        if (trim((string) $active->title) !== trim((string) $spec->title)) {
            $changes[] = "Subject name ({$active->title} → {$spec->title})";
        }

        $activeDescription = trim((string) ($active->description ?? ''));
        $draftDescription = trim((string) ($spec->description ?? ''));

        if ($activeDescription !== $draftDescription) {
            $changes[] = match (true) {
                $activeDescription === '' => 'Description added',
                $draftDescription === '' => 'Description removed',
                default => 'Description updated',
            };
        }

        if (round((float) $active->credit_units, 2) !== round((float) $spec->credit_units, 2)) {
            $changes[] = 'Units ('.$formatUnits($active->credit_units).' → '.$formatUnits($spec->credit_units).')';
        }

        if ((string) $active->academic_classification !== (string) $spec->academic_classification) {
            $changes[] = 'Subject type ('.CourseSpecification::shortClassificationLabel($active->academic_classification).' → '.CourseSpecification::shortClassificationLabel($spec->academic_classification).')';
        }

        if ((string) $active->scheduling_treatment !== (string) $spec->scheduling_treatment) {
            $scheduleLabel = static fn (?string $treatment): string => $treatment === CourseSpecification::SchedulingExternallyArranged
                ? 'Arranged / flexible'
                : 'Weekly scheduled';
            $changes[] = 'Schedule type ('.$scheduleLabel($active->scheduling_treatment).' → '.$scheduleLabel($spec->scheduling_treatment).')';
        }

        $normalizeModalities = static fn (mixed $modalities): array => collect(is_array($modalities) ? $modalities : [])
            ->filter(fn (mixed $mode): bool => is_string($mode) && $mode !== '')
            ->unique()
            ->sort()
            ->values()
            ->all();

        $activeModalities = $normalizeModalities($active->allowed_modalities);
        $draftModalities = $normalizeModalities($spec->allowed_modalities);

        if ($activeModalities !== $draftModalities) {
            $modalityOptions = CourseSpecification::modalityOptions();
            $formatModes = static fn (array $modes): string => $modes === []
                ? 'None'
                : implode(', ', array_map(fn (string $mode): string => $modalityOptions[$mode] ?? $mode, $modes));
            $changes[] = 'Delivery modes ('.$formatModes($activeModalities).' → '.$formatModes($draftModalities).')';
        }

        if ((bool) $active->same_faculty_default !== (bool) $spec->same_faculty_default) {
            $changes[] = $spec->same_faculty_default
                ? 'Same teacher for Lecture & Lab enabled'
                : 'Same teacher for Lecture & Lab disabled';
        }

        if ($this->normalizedComponents($active) !== $this->normalizedComponents($spec)) {
            $changes[] = 'Weekly meetings updated';
        }

        if ($this->normalizedRequirements($active) !== $this->normalizedRequirements($spec)) {
            $changes[] = 'Prerequisites & equivalents updated';
        }

        return [
            'has_active' => true,
            'changes' => $changes,
        ];
    }

    private function existingDraftRevision(): ?CourseSpecification
    {
        $course = $this->getRecord();

        if (! $course instanceof Course) {
            return null;
        }

        $course->loadMissing('specifications');

        return $course->specifications
            ->where('state', CourseSpecification::StateDraft)
            ->sortByDesc('id')
            ->first();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizedComponents(CourseSpecification $spec): array
    {
        return $spec->components
            ->sortBy(fn (CourseComponent $component): string => sprintf('%05d-%s', (int) $component->sequence, (string) $component->component_type))
            ->map(function (CourseComponent $component): array {
                $features = collect((array) ($component->required_room_feature_keys ?? []))
                    ->filter(fn (mixed $key): bool => is_string($key) && $key !== '')
                    ->unique()
                    ->sort()
                    ->values()
                    ->all();

                return [
                    'component_type' => (string) $component->component_type,
                    'weekly_contact_hours' => round((float) $component->weekly_contact_hours, 2),
                    'meeting_pattern' => (string) $component->meeting_pattern,
                    'room_type_default' => $component->room_type_default !== null ? (string) $component->room_type_default : null,
                    'required_room_feature_keys' => $features,
                    'modality_restriction' => $component->modality_restriction !== null ? (string) $component->modality_restriction : null,
                    'requires_consecutive_block' => (bool) $component->requires_consecutive_block,
                    'same_faculty' => (bool) $component->same_faculty,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizedRequirements(CourseSpecification $spec): array
    {
        return $spec->requirements
            ->map(fn (CourseRequirement $requirement): array => [
                'rule_type' => (string) $requirement->rule_type,
                'related_course_id' => (int) $requirement->related_course_id,
                'state' => (string) $requirement->state,
            ])
            ->sortBy(fn (array $item): string => "{$item['rule_type']}-{$item['related_course_id']}-{$item['state']}")
            ->values()
            ->all();
    }

    private function refreshRevision(?int $revisionId): void
    {
        $course = $this->getRecord();
        $course->refresh();
        $course->unsetRelation('specifications');
        $this->selectedRevisionId = $revisionId;

        unset(
            $this->cachedActions['editRevision'],
            $this->cachedActions['activateRevision'],
            $this->cachedActions['discardDraft'],
            $this->cachedActions['reviseSubject'],
        );

        $this->forceRender();
    }

    public function getRelationManagers(): array
    {
        return [];
    }
}
