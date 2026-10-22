<?php

namespace App\Filament\Student\Pages;

use App\Actions\Enrollment\CancelRegistrationCase;
use App\Actions\Enrollment\ConfirmRegistrationProposal;
use App\Actions\Enrollment\RegistrationEntryContext;
use App\Actions\Enrollment\RegistrationNotificationLedger;
use App\Actions\Enrollment\RegistrationReadinessQuery;
use App\Actions\Enrollment\StartRegistrationCase;
use App\Actions\Finance\EnrollmentPaymentRequirementProjection;
use App\Actions\Finance\SubmitPaymentEvidence;
use App\Actions\Finance\TermAccountProjection;
use App\Actions\Integrations\Payments\CreatePaymentCheckoutSession;
use App\Actions\Integrations\Payments\PayMongoCheckoutReadinessService;
use App\Models\Assessment;
use App\Models\CourseEnrollment;
use App\Models\Enrollment as EnrollmentRecord;
use App\Models\OperationalEvent;
use App\Models\RegistrationProposalVersion;
use App\Models\User;
use App\Support\RegistrationSchedulePresentation;
use App\Support\StudentPaymentActions;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\View as SchemaView;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\View\View;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Throwable;

class Enrollment extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationLabel = 'Enrollment';

    protected static ?string $title = 'Enrollment';

    protected string $view = 'filament.student.pages.enrollment';

    /** Proposal fixed when the confirmation form opened. */
    #[Locked]
    public ?int $reviewedProposalId = null;

    /** Case and version fixed when the cancellation form opened. */
    #[Locked]
    public ?int $reviewedCaseId = null;

    #[Locked]
    public ?int $reviewedCaseVersion = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('student') ?? false;
    }

    public function getHeading(): string
    {
        $case = $this->currentEnrollment();

        return match (true) {
            $case?->canonical_outcome === EnrollmentRecord::OutcomeOfficiallyEnrolled => 'Official enrollment',
            $case?->canonical_outcome === EnrollmentRecord::OutcomeInProgress
                && $case->currentProposalVersion?->state === RegistrationProposalVersion::StateDraft => 'Enrollment is being prepared',
            default => 'Enrollment',
        };
    }

    /** @return array<string|int, string> */
    public function getBreadcrumbs(): array
    {
        return $this->actor()->hasRole('student')
            ? [Dashboard::getUrl(panel: 'student') => 'Overview', 'Enrollment']
            : [\App\Filament\Applicant\Pages\Dashboard::getUrl(panel: 'applicant') => 'Applicant Home', 'Enrollment'];
    }

    public function startRegistrationAction(): Action
    {
        return Action::make('startRegistration')->label('Prepare enrollment')->icon('heroicon-o-play')
            ->visible(fn (): bool => $this->entryContext()['can_start'])
            ->action(function (): void {
                $context = $this->entryContext();
                abort_unless($context['can_start'] && $context['term'] !== null, 403);
                try {
                    $starter = app(StartRegistrationCase::class);
                    if ($context['application'] !== null) {
                        $starter->forReadyApplicant($context['application'], $context['term'], $this->actor());
                    } else {
                        $starter->forContinuingStudent($context['student_profile'], $context['term'], $this->actor());
                    }
                    Notification::make()->title('Enrollment opened')->body('Review the prepared proposal, or the named Registrar or Accounting action.')->success()->send();
                } catch (Throwable $exception) {
                    $this->failure('Enrollment could not be prepared', $exception);
                }
            });
    }

    public function confirmProposalAction(): Action
    {
        return Action::make('confirmProposal')->label('Confirm subjects')->icon('heroicon-o-check-circle')
            ->color('primary')->modal()->modalHeading('Confirm these subjects?')
            ->modalWidth(Width::Large)
            ->modalIcon('heroicon-o-check-circle')
            ->modalContent(fn (): View => view('filament.student.pages.enrollment-confirmation', $this->getViewData()))
            ->modalCancelActionLabel('Keep reviewing')->modalSubmitActionLabel('Confirm subjects')
            ->visible(fn (): bool => $this->preparedForConfirmation())
            ->mountUsing(function (): void {
                $this->reviewedProposalId = $this->currentEnrollment()?->currentProposalVersion?->id;
            })
            ->action(function (): void {
                try {
                    $proposal = $this->currentEnrollment()?->currentProposalVersion;
                    if (! $proposal instanceof RegistrationProposalVersion || $this->reviewedProposalId !== $proposal->id) {
                        throw ValidationException::withMessages(['proposal' => 'Your subjects changed after you opened this form. Close it and review the current subjects.']);
                    }
                    app(ConfirmRegistrationProposal::class)->execute($proposal, $this->actor());
                    Notification::make()->title('Subjects confirmed')->body('Check the next enrollment step below.')->success()->send();
                } catch (Throwable $exception) {
                    $this->failure('Proposal was not confirmed', $exception);
                }
            });
    }

    private function preparedForConfirmation(): bool
    {
        $case = $this->currentEnrollment();
        if ($case?->currentProposalVersion?->state !== RegistrationProposalVersion::StateIssued) {
            return false;
        }
        try {
            app(RegistrationReadinessQuery::class)->assertPrepared($case, $case->currentProposalVersion);

            return true;
        } catch (ValidationException) {
            return false;
        }
    }

    public function cancelRegistrationAction(): Action
    {
        return Action::make('cancelRegistration')->label('Cancel enrollment')->button()->outlined()->color('danger')
            ->modalHeading('Cancel this enrollment?')
            ->modalWidth(Width::Large)
            ->modalSubmitActionLabel('Cancel enrollment')->modalCancelActionLabel('Keep enrollment')
            ->modalSubmitAction(fn (Action $action): Action => $action->color('danger'))
            ->visible(fn (): bool => $this->currentEnrollment()?->canonical_outcome === EnrollmentRecord::OutcomeInProgress
                && $this->currentEnrollment()?->currentProposalVersion?->confirmation === null)
            ->fillForm(function (): array {
                $case = $this->currentEnrollment();
                $this->reviewedCaseId = $case?->id;
                $this->reviewedCaseVersion = $case?->lock_version;

                return [];
            })
            ->schema([
                SchemaView::make('filament.student.pages.enrollment-cancel-context')->viewData(fn (): array => $this->getViewData()),
            ])
            ->action(function (): void {
                try {
                    $case = $this->currentEnrollment();
                    if (! $case instanceof EnrollmentRecord || $case->id !== $this->reviewedCaseId
                        || $case->canonical_outcome !== EnrollmentRecord::OutcomeInProgress
                        || $case->currentProposalVersion?->confirmation !== null) {
                        throw ValidationException::withMessages(['case' => 'This enrollment changed after you opened this form. Close it and review the current enrollment.']);
                    }
                    app(CancelRegistrationCase::class)->execute($case, $this->actor(), 'Cancelled by the learner.', $this->reviewedCaseVersion);
                    Notification::make()->title('Registration case cancelled')->body('Temporary seats were released. Your case and financial history remain.')->success()->send();
                } catch (Throwable $exception) {
                    $this->failure('Registration was not cancelled', $exception);
                }
            });
    }

    public function submitPaymentEvidenceAction(): Action
    {
        return StudentPaymentActions::proof(Action::make('submitPaymentEvidence')->label('Submit payment proof')->icon('heroicon-o-arrow-up-tray'))
            ->visible(function (): bool {
                $case = $this->currentEnrollment();

                return $case?->canonical_outcome === EnrollmentRecord::OutcomeInProgress
                    && $case->currentProposalVersion?->confirmation !== null
                    && app(EnrollmentPaymentRequirementProjection::class)->forEnrollment($case)['state'] === 'ActionNeeded';
            })
            ->action(function (array $data): void {
                $case = $this->currentEnrollment();
                $file = $data['evidence'] ?? null;
                abort_unless($case?->termAccount !== null && $file instanceof UploadedFile, 404);
                try {
                    $position = app(TermAccountProjection::class)->forAccount($case->termAccount);
                    $targets = collect($position['obligations'])->where('required_for_enrollment', true)->pluck('id')->all();
                    app(SubmitPaymentEvidence::class)->execute(
                        $case->termAccount, $this->actor(), $file, (string) $data['claimed_amount'],
                        $data['channel'], $data['paid_at'], $data['payment_reference'] ?? null, $targets,
                    );
                    Notification::make()->title('Evidence submitted for Accounting review')->body('No payment is posted by submission. You can review the current evidence below.')->success()->send();
                } catch (Throwable $exception) {
                    $this->failure('Payment evidence was not submitted', $exception);
                }
            });
    }

    public function payExactCurrentDueAction(): Action
    {
        return StudentPaymentActions::online(
            Action::make('payExactCurrentDue')->label('Pay online')->icon('heroicon-o-credit-card'),
            fn (): array => $this->checkout(),
        )
            ->visible(fn (): bool => $this->currentEnrollment()?->canonical_outcome === EnrollmentRecord::OutcomeInProgress
                && $this->currentEnrollment()?->currentProposalVersion?->confirmation !== null)
            ->disabled(fn (): bool => $this->checkout()['enabled'] !== true)
            ->action(function (): void {
                try {
                    $result = app(CreatePaymentCheckoutSession::class)->create(
                        actor: $this->actor(), successUrl: static::getUrl(['checkout' => 'success']),
                        cancelUrl: static::getUrl(['checkout' => 'cancelled']), metadata: ['source' => 'learner_enrollment'],
                    );
                    $this->redirect($result['checkout_url'], navigate: false);
                } catch (Throwable $exception) {
                    $this->failure('Online payment is unavailable', $exception);
                }
            });
    }

    public function resendRegistrationEmailAction(): Action
    {
        return Action::make('resendRegistrationEmail')->label('Resend enrollment email')->icon('heroicon-o-arrow-path')
            ->visible(fn (): bool => $this->failedRegistrationNotification() !== null)
            ->action(function (): void {
                $event = $this->failedRegistrationNotification();
                abort_unless($event instanceof OperationalEvent, 404);
                try {
                    app(RegistrationNotificationLedger::class)->resend($event, $this->actor());
                    Notification::make()->title('Enrollment email queued again')->success()->send();
                } catch (Throwable $exception) {
                    $this->failure('Enrollment email was not resent', $exception);
                }
            });
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $context = $this->entryContext();
        $case = $this->currentEnrollment();
        $proposal = $case?->currentProposalVersion;
        $official = $case?->canonical_outcome === EnrollmentRecord::OutcomeOfficiallyEnrolled;
        $position = ! $official && $case?->termAccount !== null ? app(TermAccountProjection::class)->forAccount($case->termAccount) : null;
        $requirement = ! $official && $case !== null ? app(EnrollmentPaymentRequirementProjection::class)->forEnrollment($case) : null;
        $assessment = isset($position['assessment_id']) ? Assessment::query()->with(['lines', 'obligations'])->find($position['assessment_id']) : null;

        return [
            'entryContext' => $context, 'enrollment' => $case, 'proposal' => $proposal,
            'readiness' => ! $official && $case !== null ? app(RegistrationReadinessQuery::class)->for($case) : null,
            'preparedForConfirmation' => ! $official && $this->preparedForConfirmation(),
            'paymentRequirement' => $requirement, 'accountPosition' => $position, 'assessment' => $assessment,
            'schedulePresentation' => ! $official ? $this->schedulePresentation($proposal, $case) : null,
            'checkout' => ! $official ? $this->checkout() : null,
            'registeredSubjects' => $official ? $case->courseEnrollments()
                ->where('is_current', true)->where('status', CourseEnrollment::StatusActive)
                ->with(['termOffering.curriculumEntry.courseSpecification.course', 'section'])->get() : collect(),
            'latestEvidence' => $case?->termAccount?->latestPaymentEvidenceVersion,
            'corHistory' => $this->corHistory($case), 'isStudent' => $this->actor()->hasRole('student'),
        ];
    }

    /** @return array<string, mixed> */
    protected function entryContext(): array
    {
        return app(RegistrationEntryContext::class)->forUser($this->actor());
    }

    protected function currentEnrollment(): ?EnrollmentRecord
    {
        $case = $this->entryContext()['enrollment'];

        return $case?->loadMissing([
            'term.academicYear', 'studentProfile', 'admissionApplication.program', 'currentProposalVersion.curriculumVersion.program', 'currentProposalVersion.items.section',
            'currentProposalVersion.items.reservation', 'currentProposalVersion.confirmation',
            'currentProposalVersion.timetableVersion.scheduleRun', 'termAccount.latestPaymentEvidenceVersion', 'currentCorVersion.timetableVersion.scheduleRun',
        ]);
    }

    /** @return array<string, mixed> */
    protected function checkout(): array
    {
        $account = $this->currentEnrollment()?->termAccount;

        return $account !== null ? app(PayMongoCheckoutReadinessService::class)->for($this->actor(), $account)
            : ['enabled' => false, 'reason' => 'Online payment is unavailable until Accounting records a current assessment.', 'amount' => null, 'obligations' => []];
    }

    /** @return array<string, mixed>|null */
    private function schedulePresentation(?RegistrationProposalVersion $proposal, ?EnrollmentRecord $case): ?array
    {
        return app(RegistrationSchedulePresentation::class)->for($proposal, $case);
    }

    /** @return list<array<string, mixed>> */
    private function corHistory(?EnrollmentRecord $currentEnrollment): array
    {
        return EnrollmentRecord::query()->with(['term', 'corVersions'])->where('credential_user_id', $this->actor()->id)
            ->whereHas('corVersions')->latest('officially_enrolled_at')->get()
            ->flatMap(fn (EnrollmentRecord $case) => $case->corVersions->sortByDesc('version')->map(fn ($version): array => [
                'term' => $case->term?->label, 'version' => $version->version,
                'status' => (int) $case->current_cor_version_id !== (int) $version->id ? 'Superseded' : ($case->is($currentEnrollment) ? 'Current' : 'Historical'),
                'url' => route('cor.print', ['enrollment' => $case->id, 'version' => $version->version]),
            ]))->values()->all();
    }

    protected function actor(): User
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    private function failedRegistrationNotification(): ?OperationalEvent
    {
        return OperationalEvent::query()->where('event_domain', OperationalEvent::DomainNotifications)
            ->whereIn('event_type', OperationalEvent::registrationNotificationTypes())
            ->where('user_id', $this->actor()->id)->where('status', OperationalEvent::StatusFailed)->latest('id')->first();
    }

    protected function failure(string $title, Throwable $exception): void
    {
        Notification::make()->title($title)->body($exception->getMessage())->danger()->persistent()->send();
    }
}
