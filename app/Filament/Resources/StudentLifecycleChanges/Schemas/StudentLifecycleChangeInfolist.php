<?php

namespace App\Filament\Resources\StudentLifecycleChanges\Schemas;

use App\Models\StudentLifecycleChange;
use App\Models\StudentProfile;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class StudentLifecycleChangeInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Lifecycle Result')->schema([
                    TextEntry::make('student_name')
                        ->label('Student')
                        ->state(function (StudentLifecycleChange $record): string {
                            $profile = $record->studentProfile;

                            if (! $profile instanceof StudentProfile) {
                                return 'Student record unavailable';
                            }

                            return collect([
                                $profile->last_name,
                                $profile->first_name,
                                $profile->middle_name,
                            ])->filter()->implode(', ');
                        }),
                    TextEntry::make('studentProfile.student_number')->label('Student Number'),
                    TextEntry::make('studentProfile.program.name')->label('Program'),
                    TextEntry::make('type')
                        ->label('Recorded Change')
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => StudentLifecycleChange::typeOptions()[$state] ?? str($state)->headline()->toString()),
                    TextEntry::make('term.label')->label('Effective Term'),
                    TextEntry::make('effective_on')->label('Effective Date')->date(),
                    TextEntry::make('decided_on')->label('Decision Date')->date(),
                    TextEntry::make('state')
                        ->label('Recorded Result')
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => match ($state) {
                            StudentLifecycleChange::StateRecordedApproved => 'Approved — pending application',
                            StudentLifecycleChange::StateApplied => 'Applied to official record',
                            StudentLifecycleChange::StateCancelled => 'Cancelled before application',
                            default => str($state)->headline()->toString(),
                        }),
                    TextEntry::make('authority')->label('Decision Authority'),
                    TextEntry::make('recorder.name')->label('Recorded By')->placeholder('System'),
                    TextEntry::make('responsible_office')->label('Responsible Office')->state('Registrar Office'),
                    TextEntry::make('reason')->label('Recorded Reason')->columnSpanFull(),
                    TextEntry::make('private_source_reference')->label('Office Reference')->placeholder('Not recorded')->columnSpanFull(),
                ])->columns(3),
                Section::make('Program Shift Assignment')->schema([
                    TextEntry::make('old_program')->label('Old / Current Program')
                        ->state(fn (StudentLifecycleChange $record): string => data_get($record->impact_snapshot, 'program_before.name') ?? data_get($record, 'studentProfile.program.name') ?? 'Not recorded'),
                    TextEntry::make('old_curriculum')->label('Old / Current Curriculum')
                        ->state(fn (StudentLifecycleChange $record): string => data_get($record->impact_snapshot, 'curriculum_version_before.name') ?? data_get($record, 'studentProfile.curriculumVersion.name') ?? 'Not recorded'),
                    TextEntry::make('targetProgram.name')->label('Target Program')->placeholder('Not recorded'),
                    TextEntry::make('targetCurriculumVersion.name')->label('Target Curriculum')->placeholder('Not recorded'),
                    TextEntry::make('state')->label('Evaluation State')->badge(),
                    TextEntry::make('impact_snapshot.finance_effect.message')->label('Accounting boundary')->placeholder('No enrollment-linked Accounting review is required.'),
                ])->columns(3)->visible(fn ($record): bool => $record?->type === StudentLifecycleChange::TypeProgramShift),
                Section::make('Recorded Operational Impact')
                    ->description('This is the immutable result calculated when the approved action was recorded.')
                    ->schema([
                        TextEntry::make('impact_snapshot.course_enrollment_ids')
                            ->label('Affected subjects')
                            ->state(function (StudentLifecycleChange $record): string {
                                $subjects = collect((array) data_get($record->impact_snapshot, 'affected_subjects', []))
                                    ->map(fn (array $subject): string => collect([
                                        $subject['code'] ?? null,
                                        $subject['title'] ?? null,
                                    ])->filter()->implode(' — '))
                                    ->filter()
                                    ->implode(', ');
                                $count = count((array) data_get($record->impact_snapshot, 'course_enrollment_ids', []));

                                return $subjects !== ''
                                    ? "{$count} subject enrollment(s): {$subjects}"
                                    : "{$count} subject enrollment(s)";
                            }),
                        TextEntry::make('impact_snapshot.binding_ids')
                            ->label('Schedule assignments released')
                            ->state(fn (StudentLifecycleChange $record): string => count((array) data_get($record->impact_snapshot, 'binding_ids', [])).' assignment(s)'),
                        TextEntry::make('impact_snapshot.reservation_ids')
                            ->label('Seat reservations released')
                            ->state(fn (StudentLifecycleChange $record): string => count((array) data_get($record->impact_snapshot, 'reservation_ids', [])).' reservation(s)'),
                        TextEntry::make('impact_snapshot.profile_status_after')
                            ->label('Student status after action')
                            ->formatStateUsing(fn (?string $state): string => filled($state) ? str((string) $state)->headline()->toString() : 'Unchanged'),
                        TextEntry::make('impact_program')
                            ->label('Program before and after')
                            ->state(fn (StudentLifecycleChange $record): string => collect([
                                data_get($record->impact_snapshot, 'program_before.name'),
                                data_get($record->impact_snapshot, 'program_after.name'),
                            ])->filter()->implode(' → ') ?: 'Not recorded'),
                        TextEntry::make('impact_curriculum')
                            ->label('Curriculum before and after')
                            ->state(fn (StudentLifecycleChange $record): string => collect([
                                data_get($record->impact_snapshot, 'curriculum_version_before.name'),
                                data_get($record->impact_snapshot, 'curriculum_version_after.name'),
                            ])->filter()->implode(' → ') ?: 'Not recorded'),
                        TextEntry::make('impact_snapshot.active_hold_count')
                            ->label('Active holds retained')
                            ->formatStateUsing(fn (mixed $state): string => ((int) $state).' hold(s)'),
                        TextEntry::make('impact_snapshot.finance_effect.message')
                            ->label('Accounting review')
                            ->placeholder('No automatic finance effect was recorded.'),
                        TextEntry::make('impact_snapshot.cor_available_after')
                            ->label('COR availability after action')
                            ->formatStateUsing(fn (mixed $state): string => (bool) $state ? 'Available' : 'Unavailable'),
                        TextEntry::make('impact_snapshot.master_schedule_changes')
                            ->label('Master schedule changes')
                            ->state(fn (StudentLifecycleChange $record): string => ((int) data_get($record->impact_snapshot, 'master_schedule_changes', 0) === 0)
                                ? 'None — the published master schedule is unchanged'
                                : (int) data_get($record->impact_snapshot, 'master_schedule_changes').' change(s)'),
                    ])->columns(2),
                Section::make('Program Shift Credit Checklist')->schema([
                    RepeatableEntry::make('programShiftCredits')->schema([
                        TextEntry::make('curriculumEntry.courseSpecification.course.code')->label('Target Course'),
                        TextEntry::make('curriculumEntry.courseSpecification.title')->label('Target Title'),
                        TextEntry::make('treatment')->badge(),
                        TextEntry::make('sourceCourse.code')->label('Source Course')->placeholder('Not required'),
                        TextEntry::make('numeric_grade'),
                        TextEntry::make('state')->badge(),
                    ])->columns(3),
                ])->visible(fn ($record): bool => $record?->type === StudentLifecycleChange::TypeProgramShift),
            ]);
    }
}
