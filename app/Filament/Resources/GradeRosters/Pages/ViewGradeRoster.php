<?php

namespace App\Filament\Resources\GradeRosters\Pages;

use App\Actions\Grades\PostAndReleaseGradeRoster;
use App\Actions\Grades\ReturnGradeRoster;
use App\Filament\Resources\GradeRosters\GradeRosterResource;
use App\Models\GradeRoster;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

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
        ];
    }

    private function gradeRoster(): GradeRoster
    {
        return GradeRoster::query()->findOrFail($this->record->getKey());
    }
}
