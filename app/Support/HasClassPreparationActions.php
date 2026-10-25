<?php

namespace App\Support;

use App\Actions\Scheduling\PrepareClassOffering;
use App\Actions\Scheduling\RecordTermCohort;
use App\Models\CurriculumVersion;
use App\Models\Section;
use App\Models\TermOffering;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;

trait HasClassPreparationActions
{
    public function recordCohortAction(): Action
    {
        return Action::make('recordCohort')

            ->label('Add student section')
            ->modalHeading('Add student section')->modalSubmitActionLabel('Add student section')
            ->modalCancelActionLabel('Cancel')
            ->visible(fn (): bool => $this->termId !== null && (bool) auth()->user()?->hasRole(User::StaffRoleRegistrar))
            ->modalDescription(fn (): string => $this->selectedTerm()->label.'. Choose the active curriculum and record this student section’s confirmed learners.')
            ->schema([
                Select::make('curriculum_version_id')->label('Active curriculum')
                    ->options(fn (): array => CurriculumVersion::query()->where('state', CurriculumVersion::StateActive)->with('program')->get()
                        ->mapWithKeys(fn (CurriculumVersion $curriculum): array => [$curriculum->id => $curriculum->program?->name.' · '.$curriculum->version_code])->all())
                    ->required()->searchable(),
                TextInput::make('reference')->label('Student section reference')->required()->maxLength(64),
                TextInput::make('confirmed_count')->label('Confirmed learners')->required()->integer()->minValue(1),
            ])
            ->action(function (array $data, Schema $schema): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                try {
                    app(RecordTermCohort::class)->execute($this->selectedTerm(), $actor, $data);
                } catch (ValidationException $exception) {
                    if (isset($exception->errors()['term_id'])) {
                        Notification::make()->title('Cannot record cohort')->body($exception->errors()['term_id'][0])->danger()->persistent()->send();
                    }
                    throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn (array $messages, string $field): array => [$schema->getStatePath().'.'.$field => $messages])->all());
                }
                Notification::make()->title('Confirmed cohort recorded')->success()->send();
            });
    }

    public function prepareClassOfferingAction(): Action
    {
        return Action::make('prepareClassOffering')

            ->label('Prepare class')
            ->modalHeading('Prepare class')->modalSubmitActionLabel('Save class preparation')->modalCancelActionLabel('Cancel')
            ->visible(fn (): bool => $this->termId !== null && (bool) auth()->user()?->hasRole(User::StaffRoleRegistrar))
            ->modalDescription('Review this class’s course and active teaching calendar, then confirm its linked student sections.')
            ->fillForm(fn (array $arguments, ?Section $record = null): array => ['section_id' => $record?->id ?? $arguments['sectionId'] ?? null, 'class_reference' => $record?->class_reference ?: $record?->code, 'source' => $record?->source, 'delivery_mode' => $record?->delivery_mode])
            ->schema(fn (array $arguments, ?Section $record = null): array => [
                Select::make('section_id')->label('Class')->disabled($record !== null || isset($arguments['sectionId']))->dehydrated()
                    ->options(fn (): array => Section::query()->whereHas('termOffering', fn ($query) => $query->where('term_id', $this->termId))
                        ->whereNull('confirmed_at')->whereNotIn('state', [Section::StateClosed, Section::StateCancelled])->orderBy('code')->pluck('code', 'id')->all())
                    ->required()->searchable(),
                TextInput::make('class_reference')->label('Stable class reference')->required()->maxLength(64),
                Select::make('source')->options(Section::confirmableSourceOptions())->required(),
                Select::make('delivery_mode')->label('Delivery mode')->options(TermOffering::modalityOptions())->required(),
                TextInput::make('authority_reference')->label('Additional class authority reference')
                    ->helperText('Required for an externally approved Additional class.')->maxLength(255),
            ])
            ->action(function (array $data, Schema $schema): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                try {
                    app(PrepareClassOffering::class)->execute($this->selectedTerm(), Section::query()->findOrFail((int) $data['section_id']), $actor, $data);
                } catch (ValidationException $exception) {
                    $sourceErrors = collect($exception->errors())->only(['calendar_package', 'course_revision'])->flatten();
                    if ($sourceErrors->isNotEmpty()) {
                        Notification::make()->title('Cannot prepare class')->body($sourceErrors->first())->danger()->persistent()->send();
                    }
                    throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn (array $messages, string $field): array => [$schema->getStatePath().'.'.$field => $messages])->all());
                }
                Notification::make()->title('Class prepared')->body('Review its linked student sections, then confirm the class.')->success()->send();
            });
    }
}
