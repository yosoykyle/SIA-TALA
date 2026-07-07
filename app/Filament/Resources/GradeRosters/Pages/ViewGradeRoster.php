<?php

namespace App\Filament\Resources\GradeRosters\Pages;

use App\Actions\Grades\AuthorizeLateGradeEncoding;
use App\Actions\Grades\PostAndReleaseGradeRoster;
use App\Actions\Grades\ReturnGradeRoster;
use App\Filament\Resources\GradeRosters\GradeRosterResource;
use App\Models\GradeRoster;
use App\Models\LateGradeAuthorization;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Carbon;

class ViewGradeRoster extends ViewRecord
{
    protected static string $resource = GradeRosterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('return')
                ->schema([
                    Textarea::make('reason')->required()->maxLength(2000),
                ])
                ->visible(fn (): bool => auth()->user()?->hasRole(User::StaffRoleRegistrar) && $this->gradeRoster()->state === GradeRoster::StateSubmitted)
                ->action(function (array $data): void {
                    app(ReturnGradeRoster::class)->execute($this->gradeRoster(), auth()->user(), (string) $data['reason']);
                    Notification::make()->title('Grade roster returned')->warning()->send();
                }),
            Action::make('postAndRelease')
                ->label('Post & Release')
                ->requiresConfirmation()
                ->visible(fn (): bool => auth()->user()?->hasRole(User::StaffRoleRegistrar) && $this->gradeRoster()->state === GradeRoster::StateSubmitted)
                ->action(function (): void {
                    app(PostAndReleaseGradeRoster::class)->execute($this->gradeRoster(), auth()->user());
                    Notification::make()->title('Grade roster posted and released')->success()->send();
                }),
            Action::make('authorizeLateGradeEncoding')
                ->label('Authorize Late Encoding')
                ->schema([
                    Select::make('period')
                        ->label('Grading period')
                        ->options([
                            LateGradeAuthorization::PeriodPrelim => 'Prelim',
                            LateGradeAuthorization::PeriodMidterm => 'Midterm',
                            LateGradeAuthorization::PeriodFinal => 'Final',
                        ])
                        ->required(),
                    DateTimePicker::make('opens_at')
                        ->label('Opens at')
                        ->seconds(false)
                        ->required(),
                    DateTimePicker::make('closes_at')
                        ->label('Closes at')
                        ->seconds(false)
                        ->required(),
                    Textarea::make('reason')
                        ->required()
                        ->maxLength(2000),
                ])
                ->visible(fn (): bool => auth()->user()?->hasAnyRole([User::StaffRoleRegistrar, User::StaffRoleAcademicHead])
                    && in_array($this->gradeRoster()->state, [GradeRoster::StateReturned, GradeRoster::StateLateNotSubmitted], true))
                ->action(function (array $data): void {
                    $actor = auth()->user();

                    if (! $actor instanceof User) {
                        return;
                    }

                    app(AuthorizeLateGradeEncoding::class)->execute(
                        $this->gradeRoster(),
                        (string) $data['period'],
                        Carbon::parse($data['opens_at']),
                        Carbon::parse($data['closes_at']),
                        (string) $data['reason'],
                        $actor,
                    );

                    Notification::make()->title('Late grade authorization opened')->success()->send();
                }),
        ];
    }

    private function gradeRoster(): GradeRoster
    {
        return GradeRoster::query()->findOrFail($this->record->getKey());
    }
}
