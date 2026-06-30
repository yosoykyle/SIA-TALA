<?php

namespace App\Filament\Resources\Enrollments\Pages;

use App\Actions\Enrollment\StudentUnitLoadService;
use App\Filament\Resources\Enrollments\EnrollmentResource;
use App\Filament\Resources\Enrollments\Tables\EnrollmentsTable;
use App\Models\Enrollment;
use App\Models\EnrollmentException;
use App\Models\TermOffering;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;

class ViewEnrollment extends ViewRecord
{
    protected static string $resource = EnrollmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EnrollmentsTable::confirmPlacementAction(),
            Action::make('unitLoadException')
                ->label('Student Unit Load Exception')
                ->icon('heroicon-o-scale')
                ->authorize(fn (): bool => auth()->user()?->can('create', EnrollmentException::class) ?? false)
                ->schema([
                    TextInput::make('normal_limit')
                        ->label('Computed Normal Load')
                        ->numeric()
                        ->readOnly()
                        ->default(fn (Enrollment $record): string => app(StudentUnitLoadService::class)->evaluate($record, 0, (float) ($record->term->default_max_units ?? 0))['normal_load'])
                        ->required(),
                    TextInput::make('requested_total')->numeric()->minValue(0)->required()->live(onBlur: true),
                    TextInput::make('configured_cap')->label('Configured Term Cap')->numeric()->minValue(0)->required()->live(onBlur: true),
                    Placeholder::make('approved_excess')->content(fn (Get $get): string => number_format(max(0, (float) $get('requested_total') - (float) $get('normal_limit')), 2).' units'),
                    Select::make('affected_term_offering_ids')
                        ->label('Affected Offerings')
                        ->multiple()
                        ->options(fn (Enrollment $record): array => TermOffering::query()
                            ->with('curriculumEntry.courseSpecification.course')
                            ->where('term_id', $record->term_id)
                            ->get()
                            ->mapWithKeys(fn (TermOffering $offering): array => [
                                $offering->id => $offering->curriculumEntry->courseSpecification->course->code,
                            ])->all())
                        ->required(),
                    Placeholder::make('other_gates')->content(fn (Enrollment $record): string => $record->gateResults()->where('result', 'FAILED')->pluck('gate_type')->implode(', ') ?: 'No failed gates'),
                    TextInput::make('authority')->required()->maxLength(255),
                    Textarea::make('reason')->required()->maxLength(2000),
                    TextInput::make('evidence_reference')->required()->maxLength(255),
                    DateTimePicker::make('expires_at')->after('now')->required(),
                ])
                ->action(function (Enrollment $record, array $data): void {
                    /** @var User $actor */
                    $actor = auth()->user();
                    app(StudentUnitLoadService::class)->approve($record, $data, $actor);
                    Notification::make()->title('Unit-load exception recorded')->success()->send();
                })
                ->visible(fn (): bool => auth()->user()?->hasAnyRole([User::StaffRoleRegistrar, User::StaffRoleAcademicHead, User::StaffRoleSystemSuperAdmin]) ?? false),
            Action::make('printCor')
                ->label('Print COR')
                ->icon('heroicon-o-printer')
                ->url(fn (): string => route('cor.print', $this->getRecord()))
                ->openUrlInNewTab()
                ->visible(function (): bool {
                    $record = $this->getRecord();
                    $user = auth()->user();

                    return $record instanceof Enrollment
                        && $record->status === 'officially_enrolled'
                        && $record->officially_enrolled_at !== null
                        && $user instanceof User
                        && $user->hasAnyRole([User::StaffRoleRegistrar, User::StaffRoleAccounting]);
                }),
        ];
    }
}
