<?php

namespace App\Filament\Resources\Sections\Pages;

use App\Actions\Scheduling\SectionPlanningService;
use App\Filament\Resources\Sections\SectionResource;
use App\Models\PublishedTimetableMeeting;
use App\Models\Section;
use App\Models\User;
use App\Support\HasTermPlanningContext;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditSection extends EditRecord
{
    use HasTermPlanningContext {
        getSubheading as private termPlanningSubheading;
    }

    protected static string $resource = SectionResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        $context = $this->termPlanningSubheading();
        if ($this->record->confirmed_at !== null || PublishedTimetableMeeting::query()->where('section_id', $this->record->id)->exists()) {
            return trim($context.' · Confirmed class identity, capacity and state are protected. Published changes require a validated timetable revision.', ' ·');
        }

        return $context;
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
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
