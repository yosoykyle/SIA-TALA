<?php

namespace App\Filament\Resources\Sections\Pages;

use App\Actions\Scheduling\SectionPlanningService;
use App\Filament\Pages\TermPlanningWorkbench;
use App\Filament\Resources\Sections\SectionResource;
use App\Models\PublishedTimetableMeeting;
use App\Models\Section;
use App\Models\User;
use App\Support\HasTermPlanningContext;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Alignment;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditSection extends EditRecord
{
    use HasTermPlanningContext {
        getSubheading as private termPlanningSubheading;
        getPageClasses as protected getTermPlanningPageClasses;
    }

    protected static string $resource = SectionResource::class;

    /** @return array<string> */
    public function getPageClasses(): array
    {
        return [...$this->getTermPlanningPageClasses(), 'tala-class-settings'];
    }

    public function getTitle(): string
    {
        return $this->isSourceProtected() ? 'Class settings' : 'Edit class';
    }

    public function getSubheading(): string|Htmlable|null
    {
        if ($this->isSourceProtected()) {
            $record = $this->getRecord();
            $courseTitle = $record->courseRevision?->title ?? $record->termOffering?->curriculumEntry?->courseSpecification?->title;

            return collect([$courseTitle, $record->code])->filter()->implode(' · ');
        }

        return $this->termPlanningSubheading();
    }

    public function isSourceProtected(): bool
    {
        return $this->record->confirmed_at !== null || $this->isPublished();
    }

    public function isPublished(): bool
    {
        return PublishedTimetableMeeting::query()->where('section_id', $this->record->id)->exists();
    }

    public function publishedTimetableUrl(): string
    {
        $termId = $this->termId ?? (int) ($this->record->calendarPackage()->value('term_id') ?? $this->record->termOffering()->value('term_id'));

        return TermPlanningWorkbench::getUrl(['termId' => $termId, 'viewTab' => 'published']);
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make()
                ->label('View class details')
                ->url(fn (): string => $this->getResourceUrl('view', ['record' => $this->getRecord()])),
        ];
    }

    /** @return array<Action|ActionGroup> */
    protected function getFormActions(): array
    {
        return $this->isSourceProtected()
            ? [$this->getCancelFormAction()->label('Cancel')]
            : [$this->getCancelFormAction()->label('Cancel'), $this->getSaveFormAction()->label('Save changes')];
    }

    public function getFormActionsAlignment(): string|Alignment
    {
        return Alignment::End;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        try {
            return $this->termPlanningFormData($data);
        } catch (ValidationException $exception) {
            throw $this->sourceFormException($exception);
        }
    }

    protected function getRedirectUrl(): ?string
    {
        return $this->termId === null ? parent::getRedirectUrl() : $this->getResourceUrl('index');
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $actor = auth()->user();
        abort_unless($record instanceof Section && $actor instanceof User, 403);

        try {
            return app(SectionPlanningService::class)->save($record, $data, $actor);
        } catch (ValidationException $exception) {
            throw $this->sourceFormException($exception);
        }
    }

    private function sourceFormException(ValidationException $exception): ValidationException
    {
        return ValidationException::withMessages(collect($exception->errors())->mapWithKeys(
            fn (array $messages, string $field): array => [$this->form->getStatePath().'.'.$field => $messages],
        )->all());
    }
}
