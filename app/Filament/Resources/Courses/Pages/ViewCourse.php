<?php

namespace App\Filament\Resources\Courses\Pages;

use App\Actions\AcademicSetup\CourseSpecificationLifecycleService;
use App\Filament\Pages\AcademicApprovals;
use App\Filament\Pages\CatalogCurriculaWorkbench;
use App\Filament\Resources\Courses\CourseResource;
use App\Filament\Resources\Courses\Schemas\CourseForm;
use App\Filament\Resources\CourseSpecifications\Schemas\CourseSpecificationForm;
use App\Filament\Resources\CurriculumVersions\CurriculumVersionResource;
use App\Models\Course;
use App\Models\CourseSpecification;
use App\Models\CurriculumVersion;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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
            $this->editRevisionAction()
                ->visible(fn (): bool => $this->selectedRevision()?->state === CourseSpecification::StateDraft),
            $this->reviseSubjectAction()
                ->visible(fn (): bool => $this->selectedRevision() !== null && $this->selectedRevision()?->state !== CourseSpecification::StateDraft),
            $this->createRevisionAction()
                ->visible(fn (): bool => $this->selectedRevision() === null),
            $this->editCourseDetailsAction(),
        ];
    }

    public function editCourseDetailsAction(): Action
    {
        return Action::make('editCourseDetails')
            ->label('Update code & status')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('gray')
            ->modalHeading('Update code & status')
            ->modalDescription('Update the subject code or active status.')
            ->modalSubmitActionLabel('Save changes')
            ->modalWidth(Width::Large)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->authorize(fn (): bool => auth()->user()?->can('update', $this->getRecord()) ?? false)
            ->visible(fn (): bool => auth()->user()?->can('update', $this->getRecord()) ?? false)
            ->fillForm(function (): array {
                $course = $this->getRecord();
                abort_unless($course instanceof Course, 404);

                return [
                    'code' => $course->code,
                    'state' => $course->state,
                ];
            })
            ->schema(fn (Schema $schema): Schema => CourseForm::configure($schema->model($this->getRecord())))
            ->action(function (array $data): void {
                $course = $this->getRecord();
                $actor = auth()->user();
                abort_unless($course instanceof Course, 404);
                abort_unless($actor instanceof User, 403);
                Gate::forUser($actor)->authorize('update', $course);

                $normalizedCode = strtoupper(trim((string) ($data['code'] ?? '')));
                $validated = Validator::make([
                    'code' => $normalizedCode,
                    'state' => $data['state'] ?? null,
                ], [
                    'code' => [
                        'required',
                        'string',
                        'max:64',
                        Rule::unique('courses', 'code')->ignore($course->id),
                    ],
                    'state' => ['required', Rule::in(array_keys(Course::stateOptions()))],
                ])->validate();

                $course->update($validated);
                $course->refresh();

                Notification::make()
                    ->title('Subject code & status updated')
                    ->success()
                    ->send();
            });
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
        return EditAction::make('editRevision')->label('Edit subject')->color('primary')
            ->record(fn (): ?CourseSpecification => $this->selectedRevision())
            ->authorize(fn (?CourseSpecification $record): bool => $record !== null && (auth()->user()?->can('update', $record) ?? false))
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
            ->authorize(fn (?CourseSpecification $record): bool => $record !== null && (auth()->user()?->can('copy', $record) ?? false))
            ->requiresConfirmation()->modalHeading('Edit teaching details?')
            ->modalWidth(Width::Large)->stickyModalHeader()->stickyModalFooter()
            ->modalDescription('Opens an editable draft copy of units, weekly meetings, and prerequisites without altering active student records.')
            ->modalSubmitActionLabel('Create editable draft')
            ->action(function (CourseSpecification $record): void {
                $draft = app(CourseSpecificationLifecycleService::class)->copyToDraft(
                    auth()->user(), $record, (string) Str::ulid(),
                );
                $this->refreshRevision($draft->id);
                Notification::make()->title('Draft ready to edit')->success()->send();
            });
    }

    public function activateRevisionAction(): Action
    {
        return Action::make('activateRevision')->label('Activate changes')->color('gray')
            ->record(fn (): ?CourseSpecification => $this->selectedRevision())
            ->authorize(fn (?CourseSpecification $record): bool => $record !== null && (auth()->user()?->can('activate', $record) ?? false))
            ->requiresConfirmation()->modalHeading('Activate these teaching details?')
            ->modalWidth(Width::Large)->stickyModalHeader()->stickyModalFooter()
            ->modalDescription('These details become read-only once active. Existing curriculum assignments stay unchanged.')
            ->action(function (CourseSpecification $record): void {
                $activated = app(CourseSpecificationLifecycleService::class)->activate(auth()->user(), $record);
                $this->refreshRevision($activated->id);
                Notification::make()->title('Subject changes activated')->success()->send();
            });
    }

    private function refreshRevision(int $revisionId): void
    {
        $this->getRecord()->refresh();
        $this->selectedRevisionId = $revisionId;
    }

    public function getRelationManagers(): array
    {
        return [];
    }
}
