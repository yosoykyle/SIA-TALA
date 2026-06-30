<?php

namespace App\Filament\Resources\StudentProfiles\Pages;

use App\Actions\Enrollment\AcademicProgressionService;
use App\Filament\Resources\StudentProfiles\StudentProfileResource;
use App\Models\StudentProfile;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewStudentProfile extends ViewRecord
{
    protected static string $resource = StudentProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('confirmStanding')
                ->label('Confirm Academic Standing')
                ->visible(fn (): bool => auth()->user()?->hasAnyRole([User::StaffRoleRegistrar, User::StaffRoleSystemSuperAdmin]) ?? false)
                ->schema([
                    Select::make('standing')->options(array_combine(AcademicProgressionService::standingValues(), AcademicProgressionService::standingValues()))->required(),
                    Textarea::make('reason')->required()->maxLength(2000),
                ])
                ->action(function (array $data): void {
                    /** @var StudentProfile $profile */
                    $profile = $this->getRecord();
                    /** @var User $actor */
                    $actor = auth()->user();
                    app(AcademicProgressionService::class)->confirmStanding($profile, $data['standing'], $actor, $data['reason']);
                    Notification::make()->title('Academic standing confirmed')->success()->send();
                }),
        ];
    }
}
