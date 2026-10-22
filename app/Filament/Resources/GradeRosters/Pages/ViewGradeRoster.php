<?php

namespace App\Filament\Resources\GradeRosters\Pages;

use App\Actions\Grades\AuthorizeLateGradeEncoding;
use App\Actions\Grades\PostAndReleaseGradeRoster;
use App\Actions\Grades\ReturnGradeRoster;
use App\Filament\Resources\GradeRosters\Actions\ExportClassRosterAction;
use App\Filament\Resources\GradeRosters\GradeRosterResource;
use App\Models\GradeRoster;
use App\Models\LateGradeAuthorization;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;

class ViewGradeRoster extends ViewRecord
{
    protected static string $resource = GradeRosterResource::class;

    #[Url]
    public ?int $oversightTermId = null;

    public function getTitle(): string
    {
        return $this->isHeadReader()
            ? ($this->record->termOffering?->curriculumEntry?->courseSpecification?->title ?? 'Class roster')
            : parent::getTitle();
    }

    public function getSubheading(): ?string
    {
        return $this->isHeadReader() ? collect([
            $this->record->termOffering?->curriculumEntry?->courseSpecification?->course?->code,
            $this->record->section?->code, $this->record->termOffering?->term?->label,
        ])->filter()->implode(' · ') : parent::getSubheading();
    }

    private function isHeadReader(): bool
    {
        return (auth()->user()?->hasRole(User::StaffRoleAcademicHead) ?? false)
            && ! auth()->user()->hasRole(User::StaffRoleRegistrar);
    }

    public function getExtraBodyAttributes(): array
    {
        return $this->isHeadReader() ? ['class' => 'tala-head-rosters-page'] : parent::getExtraBodyAttributes();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToClassRosters')->label('Back to class rosters')->color('gray')
                ->url(fn (): string => GradeRosterResource::getUrl('index', ['oversightTermId' => $this->oversightTermId]))
                ->visible(fn (): bool => $this->isHeadReader()),
            ExportClassRosterAction::make('exportRoster')
                ->record(fn (): GradeRoster => $this->gradeRoster())
                ->visible(fn (): bool => (auth()->user()?->hasRole(User::StaffRoleRegistrar) ?? false)
                    && $this->record->rows()->where('is_current_membership', true)->exists()),
            Action::make('return')
                ->schema([
                    Textarea::make('reason')->required()->minLength(10)->maxLength(1000),
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
                ->label('Authorize Late Grade Entry')
                ->schema([
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
                ->visible(fn (): bool => auth()->user()?->hasRole(User::StaffRoleRegistrar)
                    && in_array($this->gradeRoster()->state, [GradeRoster::StateReturned, GradeRoster::StateLateNotSubmitted], true))
                ->action(function (array $data): void {
                    $actor = auth()->user();

                    if (! $actor instanceof User) {
                        return;
                    }

                    app(AuthorizeLateGradeEncoding::class)->execute(
                        $this->gradeRoster(),
                        LateGradeAuthorization::PeriodFinal,
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
