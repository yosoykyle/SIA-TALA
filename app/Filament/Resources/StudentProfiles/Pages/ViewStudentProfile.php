<?php

namespace App\Filament\Resources\StudentProfiles\Pages;

use App\Actions\Enrollment\AcademicProgressionService;
use App\Filament\Resources\StudentProfiles\StudentProfileResource;
use App\Models\StudentProfile;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;

class ViewStudentProfile extends ViewRecord
{
    protected static string $resource = StudentProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('confirmStanding')
                ->label('Confirm Academic Standing')
                ->visible(fn (): bool => auth()->user()?->hasRole(User::StaffRoleRegistrar) ?? false)
                ->modalHeading('Record Confirmed Academic Standing')
                ->modalDescription('Review the official standing and source-derived evidence before recording an authorized decision. This changes the Student Profile and creates audit evidence.')
                ->modalSubmitActionLabel('Record confirmed standing')
                ->fillForm(function (): array {
                    /** @var StudentProfile $profile */
                    $profile = $this->getRecord();

                    return ['standing' => $profile->academic_standing];
                })
                ->schema(function (): array {
                    /** @var StudentProfile $profile */
                    $profile = $this->getRecord();
                    $summary = StudentProfileResource::academicStandingSummary($profile)[0];

                    return [
                        Section::make('Decision Evidence')
                            ->description('System review is decision support. The Registrar remains responsible for the official recorded result.')
                            ->schema([
                                TextEntry::make('current_official_standing')
                                    ->label('Current Official Standing')
                                    ->state($summary['official_standing'])
                                    ->badge(),
                                TextEntry::make('system_review')
                                    ->label('System Review')
                                    ->state($summary['system_review'])
                                    ->badge(),
                                TextEntry::make('system_review_explanation')
                                    ->label('Review Basis')
                                    ->state($summary['system_review_explanation'])
                                    ->columnSpanFull(),
                                TextEntry::make('current_gwa')
                                    ->label('Current GWA')
                                    ->state($summary['gwa'])
                                    ->placeholder('Not available'),
                                TextEntry::make('requirements_completed')
                                    ->label('Required Subjects Completed')
                                    ->state($summary['requirements_completed']),
                                TextEntry::make('blockers')
                                    ->label('Academic Blockers and Recovery')
                                    ->state($summary['blockers'])
                                    ->listWithLineBreaks()
                                    ->bulleted()
                                    ->columnSpanFull(),
                                TextEntry::make('latest_confirmation')
                                    ->label('Latest Confirmation')
                                    ->state($summary['latest_confirmation'])
                                    ->columnSpanFull(),
                            ])
                            ->columns(2),
                        Select::make('standing')
                            ->label('Official Academic Standing')
                            ->options(AcademicProgressionService::standingOptions())
                            ->helperText('Record the approved institutional result; do not copy the system review without checking its source evidence.')
                            ->required(),
                        Textarea::make('reason')
                            ->label('Decision Reason')
                            ->helperText('State the reviewed evidence or approved office decision that supports this official result.')
                            ->required()
                            ->maxLength(2000),
                    ];
                })
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
