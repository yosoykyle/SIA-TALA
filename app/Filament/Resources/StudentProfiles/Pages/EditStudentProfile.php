<?php

namespace App\Filament\Resources\StudentProfiles\Pages;

use App\Actions\StudentProfiles\RecordStudentProfileCorrection;
use App\Filament\Resources\StudentProfiles\StudentProfileResource;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditStudentProfile extends EditRecord
{
    protected static string $resource = StudentProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            Action::make('recordCorrection')
                ->label('Record factual correction')
                ->icon('heroicon-o-pencil-square')
                ->schema([
                    TextInput::make('first_name')->default(fn (): string => $this->studentProfile()->first_name)->required()->maxLength(255),
                    TextInput::make('middle_name')->default(fn (): ?string => $this->studentProfile()->middle_name)->maxLength(255),
                    TextInput::make('last_name')->default(fn (): string => $this->studentProfile()->last_name)->required()->maxLength(255),
                    DatePicker::make('birth_date')->default(fn () => $this->studentProfile()->birth_date)->native(false),
                    TextInput::make('prior_identifier')->default(fn (): ?string => $this->studentProfile()->prior_identifier)->maxLength(255),
                    TextInput::make('email')->default(fn (): ?string => $this->studentProfile()->email)->email()->maxLength(255),
                    TextInput::make('phone')->default(fn (): ?string => $this->studentProfile()->phone)->maxLength(255),
                    Textarea::make('address')->default(fn (): ?string => $this->studentProfile()->address)->maxLength(2000),
                    Select::make('entry_term_id')
                        ->label('Entry Term')
                        ->options(fn (): array => Term::query()->orderByDesc('starts_on')->pluck('label', 'id')->all())
                        ->default(fn (): ?int => $this->studentProfile()->entry_term_id)
                        ->searchable(),
                    TextInput::make('authority_reference')->required()->maxLength(255),
                    Textarea::make('reason')->required()->maxLength(2000),
                ])
                ->action(function (array $data): void {
                    $actor = auth()->user();
                    $record = $this->getRecord();
                    abort_unless($actor instanceof User && $record instanceof StudentProfile, 403);

                    app(RecordStudentProfileCorrection::class)->execute(
                        $record,
                        collect($data)->except(['authority_reference', 'reason'])->all(),
                        $actor,
                        (string) $data['authority_reference'],
                        (string) $data['reason'],
                    );
                    Notification::make()
                        ->title('Student Profile correction recorded')
                        ->body('The factual correction is attributable and prior issued outputs remain unchanged.')
                        ->success()
                        ->send();
                }),
        ];
    }

    /** @return array<Action> */
    protected function getFormActions(): array
    {
        return [];
    }

    private function studentProfile(): StudentProfile
    {
        $record = $this->getRecord();
        abort_unless($record instanceof StudentProfile, 404);

        return $record;
    }
}
