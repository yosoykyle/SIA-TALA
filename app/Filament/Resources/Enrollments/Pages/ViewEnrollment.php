<?php

namespace App\Filament\Resources\Enrollments\Pages;

use App\Actions\Enrollment\EnrollmentGateEvaluator;
use App\Actions\Enrollment\FinalizeOfficialEnrollment;
use App\Actions\Enrollment\RecordAcademicException;
use App\Actions\Enrollment\StudentUnitLoadService;
use App\Filament\Resources\Enrollments\EnrollmentResource;
use App\Filament\Resources\Enrollments\Tables\EnrollmentsTable;
use App\Models\Enrollment;
use App\Models\EnrollmentException;
use App\Models\EnrollmentGateResult;
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
use Throwable;

class ViewEnrollment extends ViewRecord
{
    protected static string $resource = EnrollmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EnrollmentsTable::confirmPlacementAction(),
            Action::make('refreshGateResults')
                ->label('Refresh Gate Results')
                ->icon('heroicon-o-arrow-path')
                ->authorize(fn (): bool => auth()->user()?->can('refreshGates', $this->getRecord()) ?? false)
                ->action(function (Enrollment $record): void {
                    app(EnrollmentGateEvaluator::class)->persist($record);
                    $this->record = $record->refresh();
                    Notification::make()->title('Enrollment gate results refreshed')->success()->send();
                }),
            Action::make('academicException')
                ->label('Record Approved Academic Exception')
                ->icon('heroicon-o-academic-cap')
                ->authorize(fn (): bool => auth()->user()?->can('create', EnrollmentException::class) ?? false)
                ->schema([
                    Select::make('exception_type')
                        ->label('Exception Type')
                        ->options([
                            EnrollmentException::TypePrerequisite => 'Prerequisite',
                            EnrollmentException::TypeCorequisite => 'Corequisite',
                            EnrollmentException::TypeBridging => 'Bridging',
                            EnrollmentException::TypeConflict => 'Schedule Conflict',
                        ])
                        ->required(),
                    Select::make('target_term_offering_id')
                        ->label('Affected Offering')
                        ->options(fn (Enrollment $record): array => $this->termOfferingOptions($record))
                        ->required(),
                    TextInput::make('original_rule')
                        ->label('Original Failed Rule')
                        ->helperText('Use the specific prerequisite, corequisite, bridging, or conflict rule being cleared.')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('authority')
                        ->label('Approving Authority')
                        ->default('Academic Head')
                        ->required()
                        ->maxLength(255),
                    Textarea::make('reason')->required()->maxLength(2000),
                    TextInput::make('evidence_reference')->required()->maxLength(255),
                    DateTimePicker::make('expires_at')
                        ->label('Effective Until')
                        ->after('now')
                        ->required(),
                ])
                ->action(function (Enrollment $record, array $data): void {
                    /** @var User $actor */
                    $actor = auth()->user();
                    app(RecordAcademicException::class)->record($record, $data, $actor);
                    app(EnrollmentGateEvaluator::class)->persist($record->refresh());
                    $this->record = $record->refresh();
                    Notification::make()->title('Academic exception recorded')->success()->send();
                })
                ->visible(fn (): bool => auth()->user()?->hasAnyRole([User::StaffRoleRegistrar, User::StaffRoleAcademicHead, User::StaffRoleSystemSuperAdmin]) ?? false),
            Action::make('unitLoadException')
                ->label('Record Approved Unit-Load Exception')
                ->icon('heroicon-o-scale')
                ->authorize(fn (): bool => auth()->user()?->can('create', EnrollmentException::class) ?? false)
                ->schema([
                    TextInput::make('normal_limit')
                        ->label('Computed Normal Load')
                        ->numeric()
                        ->readOnly()
                        ->default(fn (Enrollment $record): string => app(StudentUnitLoadService::class)->evaluate($record, 0)['normal_load'])
                        ->required(),
                    TextInput::make('requested_total')->numeric()->minValue(0)->required()->live(onBlur: true),
                    TextInput::make('configured_cap')
                        ->label('Configured Student Overload Cap')
                        ->helperText('Defaults to the student unit-load policy cap, not the faculty load cap.')
                        ->numeric()
                        ->minValue(0)
                        ->default(fn (Enrollment $record): string => app(StudentUnitLoadService::class)->evaluate($record, 0)['configured_cap'])
                        ->required()
                        ->live(onBlur: true),
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
                    Placeholder::make('other_gates')->content(fn (Enrollment $record): string => $record->gateResults()->where('result', EnrollmentGateResult::ResultFailed)->pluck('gate_type')->implode(', ') ?: 'No failed gates'),
                    TextInput::make('authority')
                        ->label('Approving Authority')
                        ->default(fn (Enrollment $record): string => app(StudentUnitLoadService::class)->evaluate($record, 0)['default_approving_authority'])
                        ->helperText('Registrar records approved exceptions; Academic Head is the default approving authority.')
                        ->required()
                        ->maxLength(255),
                    Textarea::make('reason')->required()->maxLength(2000),
                    TextInput::make('evidence_reference')->required()->maxLength(255),
                    DateTimePicker::make('expires_at')->after('now')->required(),
                ])
                ->action(function (Enrollment $record, array $data): void {
                    /** @var User $actor */
                    $actor = auth()->user();
                    app(StudentUnitLoadService::class)->approve($record, $data, $actor);
                    app(EnrollmentGateEvaluator::class)->persist($record->refresh());
                    $this->record = $record->refresh();
                    Notification::make()->title('Unit-load exception recorded')->success()->send();
                })
                ->visible(fn (): bool => auth()->user()?->hasAnyRole([User::StaffRoleRegistrar, User::StaffRoleAcademicHead, User::StaffRoleSystemSuperAdmin]) ?? false),
            Action::make('recordOfficialEnrollment')
                ->label('Record Official Enrollment')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->authorize(fn (): bool => auth()->user()?->can('officiallyEnroll', $this->getRecord()) ?? false)
                ->visible(function (): bool {
                    $record = $this->getRecord();
                    $user = auth()->user();

                    return $record instanceof Enrollment
                        && in_array($record->status, ['ready_for_official_enrollment', 'pre_enrolled'], true)
                        && $user instanceof User
                        && $user->can('officiallyEnroll', $record);
                })
                ->schema([
                    Textarea::make('remark')
                        ->label('Registrar remark (optional)')
                        ->maxLength(2000),
                ])
                ->modalHeading('Record official enrollment')
                ->modalDescription('This rechecks every enrollment gate, converts the seat reservation, binds the official schedule, and makes the COR available. The result is recorded and auditable.')
                ->modalSubmitActionLabel('Record Official Enrollment')
                ->action(function (Enrollment $record, array $data): void {
                    $actor = auth()->user();

                    if (! $actor instanceof User) {
                        return;
                    }

                    try {
                        app(FinalizeOfficialEnrollment::class)->execute($record, $actor, $data['remark'] ?? null);
                        $this->record = $record->refresh();

                        Notification::make()
                            ->title('Official enrollment recorded')
                            ->body('The enrollment is now official and the COR is available.')
                            ->success()
                            ->send();
                    } catch (Throwable $exception) {
                        Notification::make()
                            ->title('Official enrollment failed')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
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

    /**
     * @return array<int, string>
     */
    private function termOfferingOptions(Enrollment $record): array
    {
        return TermOffering::query()
            ->with('curriculumEntry.courseSpecification.course')
            ->where('term_id', $record->term_id)
            ->get()
            ->mapWithKeys(fn (TermOffering $offering): array => [
                $offering->id => collect([
                    $offering->curriculumEntry?->courseSpecification?->course?->code,
                    $offering->curriculumEntry?->courseSpecification?->title,
                ])->filter()->implode(' - '),
            ])->all();
    }
}
