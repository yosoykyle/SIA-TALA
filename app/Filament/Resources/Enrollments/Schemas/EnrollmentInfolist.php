<?php

namespace App\Filament\Resources\Enrollments\Schemas;

use App\Actions\Enrollment\RegistrationReadinessQuery;
use App\Actions\Enrollment\RegistrationShortageProjection;
use App\Filament\Resources\StudentProfiles\StudentProfileResource;
use App\Models\Enrollment;
use App\Models\RegistrationCaseEvent;
use App\Models\RegistrationProposalItem;
use App\Support\DisplayDateTime;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class EnrollmentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Registration Case')
                ->description('One exact-Term journey from authoritative eligibility to official enrollment.')
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('case_reference')->label('Case reference')->copyable(),
                        TextEntry::make('term.label')->label('Term'),
                        TextEntry::make('canonical_outcome')
                            ->label('Outcome')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => Str::headline((string) $state)),
                        TextEntry::make('learner_name')
                            ->label('Learner')
                            ->state(fn (Enrollment $record): string => $record->credentialUser?->getFilamentName() ?? 'Identity unavailable'),
                        TextEntry::make('student_number')
                            ->label('Student number')
                            ->state(fn (Enrollment $record): ?string => $record->studentProfile?->student_number)
                            ->placeholder('Created only at first finalization')
                            ->url(fn (Enrollment $record): ?string => $record->student_profile_id !== null
                                ? StudentProfileResource::getUrl('view', ['record' => $record->student_profile_id])
                                : null),
                        TextEntry::make('selection_basis')
                            ->label('Selection basis')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => Str::headline((string) $state)),
                        TextEntry::make('status_reason')
                            ->label('Current explanation')
                            ->columnSpanFull(),
                    ]),
                ]),
            Section::make('Five finalization checkpoints')
                ->description('These are derived from current source facts; they are not editable gate records.')
                ->schema([
                    RepeatableEntry::make('checkpoint_rows')
                        ->label('Current readiness')
                        ->state(fn (Enrollment $record): array => self::checkpointRows($record))
                        ->schema([
                            TextEntry::make('label')->weight('bold'),
                            TextEntry::make('state')->badge()->color(fn (string $state): string => $state === 'Ready' ? 'success' : 'warning'),
                            TextEntry::make('recovery')->label('Next action')->wrap(),
                        ])
                        ->columns(3)
                        ->columnSpanFull(),
                ]),
            Section::make('Current proposal and protected placement')
                ->description('Proposal versions remain immutable after issue. Seat protection does not create an official course registration.')
                ->schema([
                    TextEntry::make('proposal_version')
                        ->label('Proposal version')
                        ->state(fn (Enrollment $record): string => $record->currentProposalVersion !== null
                            ? "v{$record->currentProposalVersion->version} · {$record->currentProposalVersion->state}"
                            : 'Not prepared'),
                    TextEntry::make('timetable_version')
                        ->label('Published timetable source')
                        ->state(fn (Enrollment $record): ?string => $record->currentProposalVersion?->timetableVersion !== null
                            ? collect([
                                'Version '.$record->currentProposalVersion->timetableVersion->version,
                                $record->currentProposalVersion->timetableVersion->authority_reference,
                            ])->filter()->implode(' · ')
                            : null)
                        ->placeholder('Not selected'),
                    TextEntry::make('confirmation')
                        ->label('Learner confirmation')
                        ->state(fn (Enrollment $record): ?string => $record->currentProposalVersion?->confirmation !== null
                            ? collect([$record->currentProposalVersion->confirmation->method, $record->currentProposalVersion->confirmation->confirmed_at?->toDateTimeString()])->filter()->implode(' · ')
                            : null)
                        ->placeholder('Pending'),
                    RepeatableEntry::make('proposal_items')
                        ->label('Courses')
                        ->state(fn (Enrollment $record): array => $record->currentProposalVersion?->items
                            ->map(fn (RegistrationProposalItem $item): array => [
                                'course' => "{$item->course_code_snapshot} — {$item->course_title_snapshot}",
                                'section' => $item->section !== null ? $item->section->code : (string) $item->section_id,
                                'units' => $item->units_snapshot,
                                'reservation' => $item->reservation !== null ? $item->reservation->status : 'Not placed',
                                'deadline' => $item->reservation !== null
                                    ? DisplayDateTime::format($item->reservation->deadline, 'M j, Y g:i A', 'Institutional deadline not set')
                                    : null,
                            ])->all() ?? [])
                        ->schema([
                            TextEntry::make('course')->weight('bold')->columnSpan(2),
                            TextEntry::make('section'),
                            TextEntry::make('units'),
                            TextEntry::make('reservation')->badge(),
                            TextEntry::make('deadline')->placeholder('—'),
                        ])
                        ->columns(3)
                        ->columnSpanFull(),
                ])
                ->columns(3),
            Section::make('Capacity shortage recovery')
                ->description('A derived current projection only; no waitlist or silent learner move is created.')
                ->visible(fn (Enrollment $record): bool => app(RegistrationShortageProjection::class)->for($record) !== [])
                ->schema([
                    RepeatableEntry::make('shortage_rows')
                        ->label('Current shortages')
                        ->state(fn (Enrollment $record): array => app(RegistrationShortageProjection::class)->for($record))
                        ->schema([
                            TextEntry::make('course')->weight('bold'),
                            TextEntry::make('section')->label('Full class'),
                            TextEntry::make('capacity')->label('Capacity'),
                            TextEntry::make('protected')->label('Protected/official'),
                            TextEntry::make('alternatives')
                                ->formatStateUsing(fn (array $state): string => $state === [] ? 'No current alternative' : implode(', ', $state))
                                ->wrap(),
                            TextEntry::make('owner'),
                            TextEntry::make('deadline')->placeholder('No institutional deadline configured'),
                            TextEntry::make('recovery')->label('Recovery')->wrap()->columnSpanFull(),
                        ])
                        ->columns(4)
                        ->columnSpanFull(),
                ]),
            Section::make('Official result and history')
                ->schema([
                    TextEntry::make('current_cor')
                        ->label('Current COR')
                        ->state(fn (Enrollment $record): ?string => $record->currentCorVersion !== null
                            ? "Version {$record->currentCorVersion->version} · {$record->currentCorVersion->content_hash}"
                            : null)
                        ->placeholder('Created only by atomic finalization'),
                    RepeatableEntry::make('case_history')
                        ->label('Case events')
                        ->state(fn (Enrollment $record): array => $record->registrationEvents()
                            ->with('actor')
                            ->orderBy('sequence')
                            ->get()
                            ->map(fn (RegistrationCaseEvent $event): array => [
                                'event' => $event->event_type,
                                'reason' => $event->reason,
                                'authority' => $event->authority_reference,
                                'actor' => $event->actor?->getFilamentName(),
                                'time' => $event->recorded_at?->toDateTimeString(),
                            ])->all())
                        ->schema([
                            TextEntry::make('event')->badge(),
                            TextEntry::make('reason')->wrap(),
                            TextEntry::make('authority')->placeholder('Not required'),
                            TextEntry::make('actor')->placeholder('System'),
                            TextEntry::make('time'),
                        ])
                        ->columns(5)
                        ->columnSpanFull(),
                ])
                ->collapsible(),
        ]);
    }

    /**
     * @return list<array{label:string,state:string,recovery:string}>
     */
    private static function checkpointRows(Enrollment $enrollment): array
    {
        $readiness = app(RegistrationReadinessQuery::class)->for($enrollment);

        return [
            ['label' => 'Identity and exact Term', 'state' => $readiness['identity'] ? 'Ready' : 'Action required', 'recovery' => 'Resolve the authoritative Applicant or Student identity source.'],
            ['label' => 'Current proposal', 'state' => $readiness['proposal'] ? 'Ready' : 'Action required', 'recovery' => 'Prepare, issue, and confirm the current proposal.'],
            ['label' => 'Learner confirmation', 'state' => $readiness['confirmation'] ? 'Ready' : 'Action required', 'recovery' => 'Learner confirms or Registrar records assisted confirmation evidence.'],
            ['label' => 'Protected placement', 'state' => $readiness['placement'] ? 'Ready' : 'Action required', 'recovery' => 'Resolve capacity, conflict, deadline, or timetable-source changes.'],
            ['label' => 'Accounting clearance', 'state' => $readiness['finance'] ? 'Ready' : 'Action required', 'recovery' => 'Accounting supplies the current assessment and payment or coverage result.'],
        ];
    }
}
