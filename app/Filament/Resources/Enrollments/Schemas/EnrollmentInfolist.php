<?php

namespace App\Filament\Resources\Enrollments\Schemas;

use App\Actions\Enrollment\RegistrationReadinessQuery;
use App\Actions\Enrollment\RegistrationShortageProjection;
use App\Actions\Finance\EnrollmentPaymentRequirementProjection;
use App\Filament\Resources\StudentProfiles\StudentProfileResource;
use App\Models\Enrollment;
use App\Models\RegistrationCaseEvent;
use App\Models\RegistrationProposalItem;
use App\Support\DisplayDateTime;
use Carbon\CarbonImmutable;
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
            Section::make('State, owner, and next action')
                ->description('One exact-Term Registration Case. Current state is derived from authoritative facts, not an editable gate record.')
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('case_reference')->label('Case reference')->copyable(),
                        TextEntry::make('current_stage')->label('Current stage')->state(fn (Enrollment $record): string => self::stage($record))->badge(),
                        TextEntry::make('responsible_owner')->label('Responsible owner')->state(fn (Enrollment $record): string => self::owner($record)),
                        TextEntry::make('next_action')->label('Next action')->state(fn (Enrollment $record): string => self::nextAction($record))->columnSpan(2)->wrap(),
                        TextEntry::make('updated_at')->label('As of')->dateTime('M j, Y g:i A'),
                    ]),
                    RepeatableEntry::make('checkpoint_rows')
                        ->label('Five finalization checkpoints')
                        ->state(fn (Enrollment $record): array => self::checkpointRows($record))
                        ->schema([
                            TextEntry::make('label')->weight('bold'),
                            TextEntry::make('state')->badge()->color(fn (string $state): string => $state === 'Verified' ? 'success' : 'warning'),
                            TextEntry::make('source')->label('Source')->wrap(),
                            TextEntry::make('owner')->label('Owner'),
                            TextEntry::make('as_of')->label('As of'),
                            TextEntry::make('consequence')->wrap(),
                            TextEntry::make('recovery')->label('Recovery')->wrap()->columnSpanFull(),
                        ])
                        ->columns(3)
                        ->columnSpanFull(),
                ]),
            Section::make('Identity, Program, Curriculum, and exact Term')
                ->schema([
                    TextEntry::make('learner_name')->label('Learner')->state(fn (Enrollment $record): string => $record->credentialUser?->getFilamentName() ?? 'Identity unavailable'),
                    TextEntry::make('student_number')
                        ->label('Student number')
                        ->state(fn (Enrollment $record): ?string => $record->studentProfile?->student_number)
                        ->placeholder('Created only at first finalization')
                        ->url(fn (Enrollment $record): ?string => $record->student_profile_id !== null
                            ? StudentProfileResource::getUrl('view', ['record' => $record->student_profile_id])
                            : null),
                    TextEntry::make('program')->state(fn (Enrollment $record): string => $record->studentProfile?->program->name
                        ?? $record->admissionApplication?->program->name
                        ?? 'Program unavailable'),
                    TextEntry::make('curriculum')->state(fn (Enrollment $record): string => $record->currentProposalVersion?->curriculumVersion->name
                        ?? $record->studentProfile?->curriculumVersion->name
                        ?? 'Curriculum unavailable'),
                    TextEntry::make('term.label')->label('Exact Term'),
                    TextEntry::make('identity_source')->label('Identity source')->state(fn (Enrollment $record): string => $record->admission_application_id !== null
                        ? 'Ready Applicant and confirmed identity/contact version'
                        : 'Existing official Student profile'),
                ])
                ->columns(3),
            Section::make('Academic basis')
                ->description('Selection basis and eligibility remain source-owned; the learner does not choose them.')
                ->schema([
                    TextEntry::make('selection_basis')->label('Selection basis')->badge()->formatStateUsing(fn (?string $state): string => Str::headline((string) $state)),
                    TextEntry::make('eligibility_state')
                        ->label('Student eligibility')
                        ->state(fn (Enrollment $record): string => self::readiness($record)['eligibility'] && self::readiness($record)['identity'] ? 'Verified' : 'Action required')
                        ->badge()
                        ->color(fn (string $state): string => $state === 'Verified' ? 'success' : 'warning'),
                    TextEntry::make('status_reason')->label('Current explanation')->columnSpanFull()->wrap(),
                ])
                ->columns(2),
            Section::make('Proposal and confirmation')
                ->description('Proposal versions remain immutable after issue. A material successor requires a new confirmation.')
                ->schema([
                    TextEntry::make('proposal_version')->label('Proposal version')->state(fn (Enrollment $record): string => $record->currentProposalVersion !== null
                        ? "v{$record->currentProposalVersion->version} · {$record->currentProposalVersion->state}"
                        : 'Not prepared'),
                    TextEntry::make('timetable_version')
                        ->label('Published timetable source')
                        ->state(fn (Enrollment $record): ?string => $record->currentProposalVersion?->timetableVersion !== null
                            ? collect(['Version '.$record->currentProposalVersion->timetableVersion->version, $record->currentProposalVersion->timetableVersion->authority_reference])->filter()->implode(' · ')
                            : null)
                        ->placeholder('Not selected'),
                    TextEntry::make('confirmation')
                        ->label('Exact-version confirmation')
                        ->state(fn (Enrollment $record): ?string => $record->currentProposalVersion?->confirmation !== null
                            ? collect([$record->currentProposalVersion->confirmation->method, $record->currentProposalVersion->confirmation->confirmed_at?->toDateTimeString()])->filter()->implode(' · ')
                            : null)
                        ->placeholder('Pending'),
                ])
                ->columns(3),
            Section::make('Eligibility, placement, and shortage')
                ->description('Seat protection is atomic and does not create an official course registration.')
                ->schema([
                    RepeatableEntry::make('proposal_items')
                        ->label('Current proposal items')
                        ->state(fn (Enrollment $record): array => $record->currentProposalVersion?->items
                            ->map(fn (RegistrationProposalItem $item): array => [
                                'course' => "{$item->course_code_snapshot} — {$item->course_title_snapshot}",
                                'section' => $item->section->code ?? (string) $item->section_id,
                                'units' => $item->units_snapshot,
                                'scheduling' => $item->scheduling_treatment_snapshot,
                                'reservation' => $item->reservation->status ?? 'Not placed',
                                'deadline' => $item->reservation !== null
                                    ? DisplayDateTime::format($item->reservation->deadline, 'M j, Y g:i A', 'Institutional deadline not set')
                                    : null,
                            ])->all() ?? [])
                        ->schema([
                            TextEntry::make('course')->weight('bold')->columnSpan(2),
                            TextEntry::make('section'),
                            TextEntry::make('units'),
                            TextEntry::make('scheduling')->label('Scheduling treatment'),
                            TextEntry::make('reservation')->badge(),
                            TextEntry::make('deadline')->placeholder('—'),
                        ])
                        ->columns(3)
                        ->columnSpanFull(),
                    RepeatableEntry::make('shortage_rows')
                        ->label('Current shortages')
                        ->state(fn (Enrollment $record): array => app(RegistrationShortageProjection::class)->for($record))
                        ->visible(fn (Enrollment $record): bool => app(RegistrationShortageProjection::class)->for($record) !== [])
                        ->schema([
                            TextEntry::make('course')->weight('bold'),
                            TextEntry::make('section')->label('Full class'),
                            TextEntry::make('capacity'),
                            TextEntry::make('protected')->label('Protected/official'),
                            TextEntry::make('unmet_demand')->label('Aggregate unmet demand'),
                            TextEntry::make('alternatives')->formatStateUsing(fn (array $state): string => $state === [] ? 'No current alternative' : implode(', ', $state))->wrap(),
                            TextEntry::make('owner'),
                            TextEntry::make('deadline')->placeholder('No institutional deadline configured'),
                            TextEntry::make('recovery')->label('Recovery')->wrap()->columnSpanFull(),
                        ])
                        ->columns(4)
                        ->columnSpanFull(),
                ]),
            Section::make('Finance requirement')
                ->description('Read-only source-owned Enrollment Clearance evidence. Accounting actions remain in the Accounting context.')
                ->schema([
                    TextEntry::make('finance_state')->label('Clearance state')->state(fn (Enrollment $record): string => self::finance($record)['state'])->badge()->color(fn (string $state): string => $state === 'Cleared' ? 'success' : 'warning'),
                    TextEntry::make('finance_basis')->label('Assessment basis')->state(fn (Enrollment $record): ?string => self::finance($record)['basis'])->placeholder('Unavailable'),
                    TextEntry::make('finance_total')->label('Amount required now')->state(fn (Enrollment $record): ?string => self::money(self::finance($record)['total'])),
                    TextEntry::make('finance_payment')->label('Verified payment applied')->state(fn (Enrollment $record): ?string => self::money(self::finance($record)['payment_applied'])),
                    TextEntry::make('finance_coverage')->label('Approved Coverage applied')->state(fn (Enrollment $record): ?string => self::money(self::finance($record)['coverage_applied'])),
                    TextEntry::make('finance_balance')->label('Remaining required now')->state(fn (Enrollment $record): ?string => self::money(self::finance($record)['balance'])),
                    TextEntry::make('finance_satisfaction')->label('Satisfaction basis')->state(fn (Enrollment $record): string => Str::headline(self::finance($record)['satisfaction_basis'])),
                    TextEntry::make('finance_as_of')->label('As of')->state(fn (Enrollment $record): string => self::asOf(self::finance($record)['as_of'])),
                ])
                ->columns(4),
            Section::make('Registrar finalization')
                ->description('Only one atomic Registrar action creates Official Enrollment and the first immutable COR.')
                ->schema([
                    TextEntry::make('finalization_state')
                        ->label('Finalization checkpoint')
                        ->state(fn (Enrollment $record): string => $record->canonical_outcome === Enrollment::OutcomeOfficiallyEnrolled ? 'Verified' : (self::readiness($record)['ready'] ? 'Ready for Registrar' : 'Action required'))
                        ->badge()
                        ->color(fn (string $state): string => $state === 'Verified' ? 'success' : 'warning'),
                    TextEntry::make('finalizer.name')->label('Finalized by')->placeholder('Not finalized'),
                    TextEntry::make('officially_enrolled_at')->label('Finalized at')->dateTime('M j, Y g:i A')->placeholder('Not finalized'),
                    TextEntry::make('finalization_recovery')
                        ->label('Recovery')
                        ->state(fn (Enrollment $record): string => $record->canonical_outcome === Enrollment::OutcomeOfficiallyEnrolled
                            ? 'No action required. Use an authorized Adjustment or Course Drop for a later change.'
                            : self::nextAction($record))
                        ->columnSpanFull()
                        ->wrap(),
                ])
                ->columns(3),
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
                        ->state(fn (Enrollment $record): array => $record->registrationEvents()->with('actor')->orderBy('sequence')->get()
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

    /** @return array<string, mixed> */
    private static function readiness(Enrollment $enrollment): array
    {
        return app(RegistrationReadinessQuery::class)->for($enrollment);
    }

    /** @return array<string, mixed> */
    private static function finance(Enrollment $enrollment): array
    {
        return app(EnrollmentPaymentRequirementProjection::class)->forEnrollment($enrollment);
    }

    private static function stage(Enrollment $enrollment): string
    {
        if ($enrollment->canonical_outcome === Enrollment::OutcomeOfficiallyEnrolled) {
            return 'Officially enrolled';
        }

        if (in_array($enrollment->canonical_outcome, Enrollment::cancelledOutcomes(), true)) {
            return Str::headline((string) $enrollment->canonical_outcome);
        }

        if ($enrollment->canonical_outcome === Enrollment::OutcomeNotEnrolled) {
            return 'Not enrolled';
        }

        $readiness = self::readiness($enrollment);

        return match (true) {
            ! $readiness['eligibility'] || ! $readiness['identity'] => 'Eligibility action required',
            ! $readiness['proposal'] => 'Prepare proposal',
            ! $readiness['confirmation'] => 'Waiting for learner',
            ! $readiness['placement'] => 'Placement and shortage review',
            ! $readiness['finance'] => 'Finance pending',
            default => 'Ready to finalize',
        };
    }

    private static function owner(Enrollment $enrollment): string
    {
        return match (self::stage($enrollment)) {
            'Waiting for learner' => 'Learner',
            'Finance pending' => 'Accounting',
            default => 'Registrar',
        };
    }

    private static function nextAction(Enrollment $enrollment): string
    {
        return match (self::stage($enrollment)) {
            'Eligibility action required' => 'Resolve the current Applicant or Student eligibility and identity source.',
            'Prepare proposal' => 'Prepare and issue the current source-derived proposal.',
            'Waiting for learner' => 'The learner confirms the exact issued proposal, or the Registrar records bounded assisted-confirmation evidence.',
            'Placement and shortage review' => 'Resolve the named prerequisite, class, capacity, conflict, or timetable-source blocker.',
            'Finance pending' => 'Accounting resolves the current assessment and payment or coverage requirement.',
            'Ready to finalize' => 'Registrar revalidates all five checkpoints and finalizes atomically.',
            'Officially enrolled' => 'Current official registrations and immutable COR are available.',
            default => 'Review preserved history and the authorized same-case recovery path.',
        };
    }

    /** @return list<array{label:string,state:string,source:string,owner:string,as_of:string,consequence:string,recovery:string}> */
    private static function checkpointRows(Enrollment $enrollment): array
    {
        $readiness = self::readiness($enrollment);
        $asOf = $enrollment->updated_at?->format('M j, Y g:i A') ?? 'Current source time unavailable';

        return [
            ['label' => 'Student eligibility', 'state' => $readiness['eligibility'] && $readiness['identity'] ? 'Verified' : 'Action required', 'source' => $enrollment->admission_application_id !== null ? 'Ready Applicant and confirmed identity/contact version' : 'Released Student lifecycle, result, and progress facts', 'owner' => 'Registrar consumes; source office owns the fact', 'as_of' => $asOf, 'consequence' => 'A stale or blocked source prevents the next consuming action.', 'recovery' => 'Correct the owning source or prepare a source-valid successor; no local override is created.'],
            ['label' => 'Confirmed proposed subjects', 'state' => $readiness['confirmation'] ? 'Verified' : 'Action required', 'source' => 'Current immutable Registration Proposal and exact-version confirmation', 'owner' => 'Registrar and learner', 'as_of' => $asOf, 'consequence' => 'An unconfirmed or superseded proposal cannot be placed or finalized.', 'recovery' => 'Issue the current proposal and obtain online or attributable assisted confirmation.'],
            ['label' => 'Valid class placement', 'state' => $readiness['placement'] ? 'Verified' : 'Action required', 'source' => 'Current Published Timetable, prerequisites, conflicts, capacity, and reservations', 'owner' => 'Registrar', 'as_of' => $asOf, 'consequence' => 'Only the affected course is blocked; no ranked waitlist or silent move is created.', 'recovery' => 'Resolve the named placement or shortage condition using a current authorized source.'],
            ['label' => 'Accounting clearance', 'state' => $readiness['finance'] ? 'Verified' : 'Action required', 'source' => 'Enrollment Payment Requirement and exact Term Account', 'owner' => 'Accounting', 'as_of' => self::asOf(self::finance($enrollment)['as_of']), 'consequence' => 'Unavailable or unsatisfied current requirement blocks finalization only.', 'recovery' => 'Accounting records the current Fee Plan or eligible exact assessment and valid payment or coverage result.'],
            ['label' => 'Registrar finalization', 'state' => $enrollment->canonical_outcome === Enrollment::OutcomeOfficiallyEnrolled ? 'Verified' : 'Action required', 'source' => 'Atomic Registration Case result', 'owner' => 'Registrar', 'as_of' => $asOf, 'consequence' => 'No Student activation, official roster, or COR exists until the transaction commits.', 'recovery' => $readiness['ready'] ? 'All prior checkpoints are ready for Registrar finalization.' : 'Resolve the earlier named checkpoint, refresh current evidence, and retry.'],
        ];
    }

    private static function money(mixed $amount): ?string
    {
        return $amount === null ? null : 'PHP '.number_format((float) $amount, 2);
    }

    private static function asOf(string $value): string
    {
        return DisplayDateTime::format(CarbonImmutable::parse($value), 'M j, Y g:i A');
    }
}
