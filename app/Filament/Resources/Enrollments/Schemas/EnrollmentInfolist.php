<?php

namespace App\Filament\Resources\Enrollments\Schemas;

use App\Actions\Enrollment\RegistrationPlacementValidator;
use App\Actions\Enrollment\RegistrationReadinessQuery;
use App\Actions\Enrollment\RegistrationShortageProjection;
use App\Actions\Finance\EnrollmentPaymentRequirementProjection;
use App\Actions\Finance\TermAccountProjection;
use App\Filament\Resources\Enrollments\Pages\ViewEnrollment;
use App\Filament\Resources\Enrollments\Tables\EnrollmentsTable;
use App\Filament\Resources\StudentProfiles\StudentProfileResource;
use App\Models\AdmissionApplication;
use App\Models\Assessment;
use App\Models\CourseEnrollment;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\EnrollmentSeatReservation;
use App\Models\PaymentAttempt;
use App\Models\PaymentEvidenceVersion;
use App\Models\PublishedTimetableMeeting;
use App\Models\PublishedTimetableVersion;
use App\Models\RegistrationCaseEvent;
use App\Models\RegistrationCreditEvaluation;
use App\Models\RegistrationProposalItem;
use App\Models\RegistrationProposalVersion;
use App\Models\User;
use App\Support\DisplayDateTime;
use App\Support\RegistrationSchedulePresentation;
use App\Support\TimetablePresentation;
use Carbon\CarbonImmutable;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EnrollmentInfolist
{
    /** @return array<string, mixed> */
    private static function overview(Enrollment $case): array
    {
        $case->loadMissing(['credentialUser', 'term', 'studentProfile.program', 'admissionApplication.program',
            'currentProposalVersion.items.section', 'currentProposalVersion.items.reservation', 'currentProposalVersion.confirmation',
            'currentProposalVersion.timetableVersion.scheduleRun', 'termAccount.paymentEvidenceVersions', 'termAccount.payments', 'termAccount.coverages']);
        $proposal = $case->currentProposalVersion;
        $schedule = null;
        if ($proposal?->timetableVersion !== null) {
            $ids = $proposal->items->flatMap(fn ($item) => collect($item->meeting_snapshot)->pluck('id'))->filter()->unique();
            $meetings = PublishedTimetableMeeting::query()->where('published_timetable_version_id', $proposal->published_timetable_version_id)
                ->whereIn('id', $ids)->with(['classOffering.courseRevision.course', 'faculty', 'room'])->get();
            $schedule = TimetablePresentation::forPublished($proposal->timetableVersion, $meetings);
            $captured = $proposal->items->flatMap(fn (RegistrationProposalItem $item) => collect($item->meeting_snapshot)
                ->map(fn (array $meeting): array => ['id' => $meeting['id'] ?? null, 'item' => $item, 'meeting' => $meeting]))->keyBy('id');
            $schedule['meetings'] = collect($schedule['meetings'])->map(function (array $meeting) use ($captured): array {
                $source = $captured->get($meeting['id']);
                if ($source !== null) {
                    $meeting['courseCode'] = $source['item']->course_code_snapshot;
                    $meeting['courseTitle'] = $source['item']->course_title_snapshot;
                    $meeting['class'] = $source['item']->section?->code ?? $meeting['class'];
                    $meeting['faculty'] = $source['meeting']['faculty_name'] ?? $meeting['faculty'];
                    $meeting['room'] = $source['meeting']['room_label'] ?? $meeting['room'];
                }

                return $meeting;
            })->all();
            $schedule['source'] = 'Registration proposal v'.$proposal->version.' · Published timetable v'.$proposal->timetableVersion->version;
        }
        $position = $case->termAccount !== null ? app(TermAccountProjection::class)->forAccount($case->termAccount) : null;
        $assessment = $case->termAccount?->assessments()->with('lines')->where('state', Assessment::StateActive)->latest('version')->first();
        $placementCurrent = false;
        if ($proposal !== null) {
            try {
                app(RegistrationPlacementValidator::class)->assertCurrent($case, $proposal, requireConfirmation: false);
                $placementCurrent = true;
            } catch (ValidationException) {
                $placementCurrent = false;
            }
        }

        return ['case' => $case, 'proposal' => $proposal, 'schedule' => $schedule, 'position' => $position, 'assessment' => $assessment,
            'requirement' => self::finance($case), 'readiness' => self::readiness($case), 'placementCurrent' => $placementCurrent,
            'accounting' => auth()->user()?->hasRole(User::StaffRoleAccounting) ?? false];
    }

    public static function configure(Schema $schema): Schema
    {
        $page = $schema->getLivewire();
        if ($page instanceof ViewEnrollment && $page->isHeadReader()
            && $page->getRecord()->canonical_outcome === Enrollment::OutcomeOfficiallyEnrolled) {
            return $schema->components([View::make('filament.admin.enrollments.head-official')
                ->viewData(fn (Enrollment $record): array => self::headOfficial($record))
                ->columnSpanFull()]);
        }
        if ($page instanceof ViewEnrollment && $page->isHeadReader()
            && $page->getRecord()->canonical_outcome === Enrollment::OutcomeInProgress) {
            return $schema->components([View::make('filament.admin.enrollments.head-progress')
                ->viewData(fn (Enrollment $record): array => self::headProgress($record))
                ->columnSpanFull()]);
        }
        if ($page instanceof ViewEnrollment && $page->isHeadReader()) {
            return $schema->components([View::make('filament.admin.enrollments.head-closed')
                ->viewData(fn (Enrollment $record): array => self::headClosed($record))
                ->columnSpanFull()]);
        }
        if ($page instanceof ViewEnrollment && ($accountingView = $page->focusedAccountingView()) !== null) {
            return $schema->components([View::make('filament.admin.enrollments.'.$accountingView)
                ->viewData(fn (): array => $page->accountingViewData() + match ($accountingView) {
                    'accounting-proof' => $page->paymentProofViewData(),
                    'accounting-online' => $page->onlinePaymentViewData(),
                    'accounting-webhook' => $page->webhookPaymentViewData(),
                    'accounting-history' => ['onlineHistory' => $page->onlinePaymentHistory()],
                    default => [],
                })
                ->columnSpanFull()]);
        }
        if ($page instanceof ViewEnrollment && ($focusedView = $page->focusedRegistrarView()) !== null) {
            return $schema->components([
                View::make('filament.admin.enrollments.'.($page->enrollmentView === 'history' ? 'history'
                    : (in_array($focusedView, ['payment-waiting', 'assessment-recovery', 'placement-recovery'], true) ? 'progress' : $focusedView)))
                    ->viewData(fn (Enrollment $record): array => match (true) {
                        $page->enrollmentView === 'history' => self::history($record),
                        $focusedView === 'requirements' => self::overview($record) + ['requirements' => $page->registrarRequirements()],
                        in_array($focusedView, ['payment-waiting', 'assessment-recovery', 'placement-recovery'], true) => self::registrarProgress($record, $focusedView),
                        $focusedView === 'review' => ['review' => $page->registrarReviewData()],
                        $focusedView === 'official' => self::official($record),
                        $focusedView === 'adjustment' => $page->adjustmentViewData(),
                        $focusedView === 'missing-cor' => self::official($record),
                        $focusedView === 'closed' => self::registrarClosed($record) + [
                            'cutoff' => $page->reopenCutoff(),
                            'lateAuthorityRequired' => $page->reopenRequiresLateAuthority(),
                            'hasLateAuthority' => $page->lateReopenAuthorityOptions() !== [],
                        ],
                        $focusedView === 'preparing' => self::preparation($record),
                        default => self::overview($record),
                    })
                    ->columnSpanFull(),
            ]);
        }

        $evidence = [
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
                        ? 'Verified admissions identity and contact source'
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
        ];

        return $schema->components(auth()->user()?->hasAnyRole([User::StaffRoleRegistrar, User::StaffRoleAccounting]) ? [
            View::make('filament.pages.partials.enrollment-case-overview')->viewData(fn (Enrollment $record): array => self::overview($record))->columnSpanFull(),
            Section::make('Source evidence and case history')->schema($evidence)->collapsible()->collapsed()->columnSpanFull(),
        ] : $evidence);
    }

    /** @return array<string, mixed> */
    private static function preparation(Enrollment $case): array
    {
        $case->loadMissing(['credentialUser', 'term', 'studentProfile.program', 'admissionApplication.program']);

        $evaluation = null;
        if ($case->admissionApplication?->application_path === AdmissionApplication::PathTransferee) {
            $latest = RegistrationCreditEvaluation::query()->where('enrollment_id', $case->id)->latest('id')->first();
            if ($latest !== null
                && (int) $latest->application_submission_version_id === (int) $case->admissionApplication->current_submission_version_id
                && CurriculumVersion::query()->whereKey($latest->curriculum_version_id)
                    ->where('program_id', $case->admissionApplication->program_id)->where('state', CurriculumVersion::StateActive)->exists()) {
                $evaluation = $latest;
            }
        }

        return ['case' => $case, 'evaluation' => $evaluation];
    }

    /** @return array<string, mixed> */
    private static function official(Enrollment $case): array
    {
        $case->loadMissing(['credentialUser', 'term', 'studentProfile.program', 'currentCorVersion']);
        $subjects = $case->courseEnrollments()->where('is_current', true)->where('status', CourseEnrollment::StatusActive)
            ->with(['proposalItem', 'termOffering.curriculumEntry.courseSpecification.course', 'section'])->get();

        return ['case' => $case, 'subjects' => $subjects, 'schedule' => app(RegistrationSchedulePresentation::class)->currentForEnrollment($case)];
    }

    /** @return array<string, mixed> */
    private static function headOfficial(Enrollment $case): array
    {
        $data = self::official($case);
        $case->loadMissing(['finalizer', 'studentProfile.curriculumVersion', 'currentCorVersion.proposalVersion.curriculumVersion',
            'currentCorVersion.proposalVersion.confirmation', 'registrationEvents.actor',
            'currentProposalVersion.curriculumVersion', 'currentProposalVersion.items.section']);
        $pendingProposal = $case->currentProposalVersion?->purpose === RegistrationProposalVersion::PurposeAdjustment
            && in_array($case->currentProposalVersion->state, [RegistrationProposalVersion::StateDraft, RegistrationProposalVersion::StateIssued, RegistrationProposalVersion::StateConfirmed], true)
            && $case->currentProposalVersion->id !== $case->currentCorVersion?->registration_proposal_version_id
                ? $case->currentProposalVersion : null;

        return $data + ['requirement' => self::finance($case), 'pendingProposal' => $pendingProposal];
    }

    /** @return array<string, mixed> */
    private static function headProgress(Enrollment $case): array
    {
        $data = self::overview($case);
        $case->loadMissing(['currentProposalVersion.curriculumVersion.program', 'registrationEvents.actor']);
        $stage = EnrollmentsTable::stage($case);
        $deadline = $case->currentProposalVersion?->items
            ->filter(fn (RegistrationProposalItem $item): bool => in_array($item->reservation?->status, EnrollmentSeatReservation::capacityHoldingStatuses(), true))
            ->map(fn (RegistrationProposalItem $item) => $item->reservation?->deadline)->filter()->min();

        return $data + ['progress' => EnrollmentsTable::headProgress($case), 'stage' => $stage,
            'owner' => match ($stage) {
                'Waiting for learner' => 'Learner', 'Payment or fee review' => 'Accounting', default => 'Registrar'
            },
            'deadline' => $deadline];
    }

    /** @return array<string, mixed> */
    private static function registrarProgress(Enrollment $case, string $view): array
    {
        $data = self::overview($case);
        $proposal = $data['proposal'];
        $issues = [];
        try {
            app(RegistrationPlacementValidator::class)->assertCurrent($case, $proposal, requireConfirmation: false);
        } catch (ValidationException $exception) {
            $issues = $exception->errors();
        }
        $currentTimetableId = PublishedTimetableVersion::query()->where('term_id', $case->term_id)
            ->where('state', PublishedTimetableVersion::StatePublished)->latest('version')->value('id');
        $canReserve = $proposal->items->isNotEmpty() && (int) $proposal->published_timetable_version_id === (int) $currentTimetableId
            && ($issues === [] || array_keys($issues) === ['placement']) && $data['readiness']['shortages'] === []
            && $proposal->items->every(fn (RegistrationProposalItem $item): bool => $item->section?->state === \App\Models\Section::StateOpen
                && ! ($item->reservation?->status === EnrollmentSeatReservation::StatusActive && $item->reservation->deadline?->isPast()));
        $assessment = $data['assessment'];
        $paymentReview = $assessment !== null && ($case->termAccount?->paymentEvidenceVersions
            ->where('assessment_id', $assessment->id)->where('state', PaymentEvidenceVersion::StateSubmitted)->isNotEmpty()
            || PaymentAttempt::query()->where('term_account_id', $case->termAccount?->id)->where('assessment_id', $assessment->id)
                ->where('status', PaymentAttempt::StatusReviewRequired)->exists());
        $deadline = $data['placementCurrent'] ? $proposal->items->map(fn (RegistrationProposalItem $item) => $item->reservation?->deadline)
            ->filter()->sort()->first() : null;

        return $data + ['progressView' => $view, 'paymentReview' => $paymentReview, 'canReserve' => $canReserve,
            'reservationDeadline' => $deadline, 'placementIssues' => $issues,
            'taskTitle' => match ($view) {
                'assessment-recovery' => 'Waiting for Accounting assessment',
                'placement-recovery' => $canReserve ? 'Seats need a new reservation' : 'Review the selected classes',
                default => $paymentReview ? 'Payment awaiting Accounting review' : 'Waiting for enrollment payment',
            }];
    }

    /** @return array<string, mixed> */
    private static function registrarClosed(Enrollment $case): array
    {
        $case->loadMissing(['credentialUser', 'term', 'studentProfile.program', 'admissionApplication.program', 'registrationEvents.actor']);
        $event = $case->registrationEvents->filter(fn (RegistrationCaseEvent $event): bool => $event->to_outcome === $case->canonical_outcome
            && $event->from_outcome !== $case->canonical_outcome)->sortByDesc('sequence')->first();

        return ['case' => $case, 'closingEvent' => $event,
            'knownOutcome' => in_array($case->canonical_outcome, Enrollment::reopenableOutcomes(), true),
            'outcome' => match ($case->canonical_outcome) {
                Enrollment::OutcomeCancelledByLearner => 'Cancelled by learner',
                Enrollment::OutcomeCancelledByRegistrar => 'Cancelled by Registrar',
                Enrollment::OutcomeCancelled => 'Cancelled',
                Enrollment::OutcomeNotEnrolled => 'Not enrolled',
                default => 'Outcome unavailable',
            }];
    }

    /** @return array<string, mixed> */
    private static function headClosed(Enrollment $case): array
    {
        $case->loadMissing(['credentialUser', 'term', 'studentProfile.program', 'admissionApplication.program',
            'currentProposalVersion.curriculumVersion.program', 'registrationEvents.actor', 'corVersions']);

        return ['case' => $case, 'requirement' => self::finance($case),
            'knownOutcome' => in_array($case->canonical_outcome, Enrollment::reopenableOutcomes(), true),
            'outcome' => match ($case->canonical_outcome) {
                Enrollment::OutcomeCancelledByLearner => 'Cancelled by learner',
                Enrollment::OutcomeCancelledByRegistrar => 'Cancelled by Registrar',
                Enrollment::OutcomeCancelled => 'Cancelled',
                Enrollment::OutcomeNotEnrolled => 'Not enrolled',
                default => 'Outcome unavailable',
            }];
    }

    /** @return array<string, mixed> */
    private static function history(Enrollment $case): array
    {
        $case->loadMissing(['credentialUser', 'term', 'admissionApplication.program', 'studentProfile.program',
            'proposalVersions.timetableVersion', 'proposalVersions.curriculumVersion', 'proposalVersions.confirmation', 'proposalVersions.items.section',
            'termAccount.assessments.lines', 'registrationEvents.actor', 'corVersions']);

        return ['case' => $case];
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
            $enrollment->currentProposalVersion === null || $enrollment->currentProposalVersion->state === RegistrationProposalVersion::StateDraft => 'Prepare proposal',
            $enrollment->currentProposalVersion->state === RegistrationProposalVersion::StateIssued => 'Waiting for learner',
            ! $readiness['eligibility'] || ! $readiness['identity'] => 'Eligibility action required',
            ! $readiness['proposal'] => 'Prepare proposal',
            ! $readiness['confirmation'] => 'Waiting for learner',
            ! $readiness['placement'] => 'Placement and shortage review',
            ! $readiness['finance'] => 'Finance pending',
            ! $readiness['ready'] => 'Source review required',
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
            'Source review required' => 'Resolve the current finalization blockers before retrying.',
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
            ['label' => 'Student eligibility', 'state' => $readiness['eligibility'] && $readiness['identity'] ? 'Verified' : 'Action required', 'source' => $enrollment->admission_application_id !== null ? 'Verified admissions identity and contact source' : 'Released Student lifecycle, result, and progress facts', 'owner' => 'Registrar consumes; source office owns the fact', 'as_of' => $asOf, 'consequence' => 'A stale or blocked source prevents the next consuming action.', 'recovery' => 'Correct the owning source or prepare a source-valid successor; no local override is created.'],
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
