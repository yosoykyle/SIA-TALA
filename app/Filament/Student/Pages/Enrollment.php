<?php

namespace App\Filament\Student\Pages;

use App\Actions\Enrollment\CancelRegistrationCase;
use App\Actions\Enrollment\ConfirmRegistrationProposal;
use App\Actions\Enrollment\RegistrationNotificationLedger;
use App\Actions\Enrollment\RegistrationReadinessQuery;
use App\Actions\Enrollment\StartRegistrationCase;
use App\Actions\Finance\SubmitPaymentEvidence;
use App\Models\Enrollment as EnrollmentRecord;
use App\Models\OperationalEvent;
use App\Models\Payment;
use App\Models\RegistrationProposalVersion;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Http\UploadedFile;
use Throwable;

class Enrollment extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationLabel = 'Enrollment';

    protected static ?string $title = 'Enrollment';

    protected string $view = 'filament.student.pages.enrollment';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('student') ?? false;
    }

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('startRegistration')
                ->label('Start registration')
                ->icon('heroicon-o-play')
                ->visible(fn (): bool => ! $this->currentEnrollment() instanceof EnrollmentRecord)
                ->schema([
                    Select::make('term_id')
                        ->label('Term')
                        ->options(Term::query()->orderByDesc('starts_on')->pluck('label', 'id'))
                        ->required()
                        ->searchable(),
                ])
                ->action(function (array $data): void {
                    $user = $this->actor();
                    $profile = StudentProfile::query()->where('user_id', $user->id)->firstOrFail();

                    try {
                        app(StartRegistrationCase::class)->forContinuingStudent(
                            $profile,
                            Term::query()->findOrFail($data['term_id']),
                            $user,
                        );
                        Notification::make()->title('Registration started')->body('The Registrar can now prepare the exact-Term proposal.')->success()->send();
                    } catch (Throwable $exception) {
                        $this->failure('Registration was not started', $exception);
                    }
                }),
            Action::make('confirmProposal')
                ->label('Confirm current proposal')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription('Confirm the complete course, class, schedule, and unit proposal shown on this page. Any later material revision requires a new confirmation.')
                ->visible(fn (): bool => $this->currentEnrollment()?->currentProposalVersion?->state === RegistrationProposalVersion::StateIssued)
                ->action(function (): void {
                    $enrollment = $this->currentEnrollment();

                    if (! $enrollment instanceof EnrollmentRecord || ! $enrollment->currentProposalVersion instanceof RegistrationProposalVersion) {
                        return;
                    }

                    try {
                        app(ConfirmRegistrationProposal::class)->execute($enrollment->currentProposalVersion, $this->actor());
                        Notification::make()->title('Proposal confirmed')->body('The Registrar can now protect the complete placement.')->success()->send();
                    } catch (Throwable $exception) {
                        $this->failure('Proposal was not confirmed', $exception);
                    }
                }),
            Action::make('cancelRegistration')
                ->label('Cancel registration')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->visible(function (): bool {
                    $enrollment = $this->currentEnrollment();

                    return $enrollment instanceof EnrollmentRecord
                        && $enrollment->canonical_outcome === EnrollmentRecord::OutcomeInProgress
                        && $enrollment->currentProposalVersion?->confirmation === null;
                })
                ->schema([Textarea::make('reason')->required()->maxLength(2000)])
                ->action(function (array $data): void {
                    $enrollment = $this->currentEnrollment();
                    if (! $enrollment instanceof EnrollmentRecord) {
                        return;
                    }

                    try {
                        app(CancelRegistrationCase::class)->execute($enrollment, $this->actor(), $data['reason'], $enrollment->lock_version);
                        Notification::make()->title('Registration cancelled')->body('Held capacity was released; the case and history remain available.')->success()->send();
                    } catch (Throwable $exception) {
                        $this->failure('Registration was not cancelled', $exception);
                    }
                }),
            Action::make('submitPaymentEvidence')
                ->label('Submit payment evidence')
                ->icon('heroicon-o-arrow-up-tray')
                ->visible(function (): bool {
                    $enrollment = $this->currentEnrollment();

                    return $enrollment instanceof EnrollmentRecord
                        && $enrollment->termAccount !== null
                        && $enrollment->termAccount->state !== 'Cleared';
                })
                ->schema([
                    FileUpload::make('evidence')
                        ->label('Private payment evidence')
                        ->storeFiles(false)
                        ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])
                        ->maxSize(10240)
                        ->required(),
                    TextInput::make('claimed_amount')->numeric()->minValue(0.01)->prefix('PHP')->required(),
                    Select::make('channel')->options(Payment::manualConfirmationChannelOptions())->required(),
                    DateTimePicker::make('paid_at')->required()->seconds(false)->maxDate(now()),
                    TextInput::make('payment_reference')->maxLength(255),
                ])
                ->action(function (array $data): void {
                    $enrollment = $this->currentEnrollment();
                    $file = $data['evidence'] ?? null;
                    abort_unless($enrollment instanceof EnrollmentRecord
                        && $enrollment->termAccount !== null
                        && $file instanceof UploadedFile, 404);

                    try {
                        app(SubmitPaymentEvidence::class)->execute(
                            $enrollment->termAccount,
                            $this->actor(),
                            $file,
                            (string) $data['claimed_amount'],
                            $data['channel'],
                            $data['paid_at'],
                            $data['payment_reference'] ?? null,
                        );
                        Notification::make()->title('Payment evidence submitted')->body('Accounting review remains required.')->success()->send();
                    } catch (Throwable $exception) {
                        $this->failure('Payment evidence was not submitted', $exception);
                    }
                }),
            Action::make('viewClassSchedule')
                ->label('Class schedule')
                ->icon('heroicon-o-calendar-days')
                ->url(ScheduleView::getUrl(panel: 'student'))
                ->visible(fn (): bool => $this->currentEnrollment()?->canonical_outcome === EnrollmentRecord::OutcomeOfficiallyEnrolled),
            Action::make('viewCor')
                ->label('Current COR')
                ->icon('heroicon-o-document-text')
                ->url(CorView::getUrl(panel: 'student'))
                ->visible(fn (): bool => $this->currentEnrollment()?->current_cor_version_id !== null),
            Action::make('resendRegistrationEmail')
                ->label('Resend failed update')
                ->icon('heroicon-o-arrow-path')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->failedRegistrationNotification() instanceof OperationalEvent)
                ->action(function (): void {
                    $event = $this->failedRegistrationNotification();
                    if (! $event instanceof OperationalEvent) {
                        return;
                    }

                    try {
                        app(RegistrationNotificationLedger::class)->resend($event, $this->actor());
                        Notification::make()->title('Enrollment email queued again')->success()->send();
                    } catch (Throwable $exception) {
                        $this->failure('Enrollment email was not resent', $exception);
                    }
                }),
        ];
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $enrollment = $this->currentEnrollment();
        $readiness = $enrollment instanceof EnrollmentRecord
            ? app(RegistrationReadinessQuery::class)->for($enrollment)
            : null;

        return [
            'enrollment' => $enrollment,
            'readiness' => $readiness,
            'proposal' => $enrollment?->currentProposalVersion?->loadMissing(['items.section', 'items.reservation', 'confirmation']),
            'corHistory' => $this->corHistory($enrollment),
        ];
    }

    /**
     * @return list<array{enrollment_id: int, term: string, version: int, status: 'Current'|'Historical'|'Superseded', url: string}>
     */
    private function corHistory(?EnrollmentRecord $currentEnrollment): array
    {
        $history = [];
        $enrollments = EnrollmentRecord::query()
            ->with(['term', 'corVersions'])
            ->where('credential_user_id', $this->actor()->id)
            ->whereHas('corVersions')
            ->latest('officially_enrolled_at')
            ->get();

        foreach ($enrollments as $enrollment) {
            foreach ($enrollment->corVersions->sortByDesc('version') as $version) {
                $history[] = [
                    'enrollment_id' => $enrollment->id,
                    'term' => $enrollment->term->label ?? 'Term not recorded',
                    'version' => (int) $version->version,
                    'status' => (int) $enrollment->current_cor_version_id === (int) $version->id
                        ? ($enrollment->is($currentEnrollment) ? 'Current' : 'Historical')
                        : 'Superseded',
                    'url' => route('cor.print', ['enrollment' => $enrollment->id, 'version' => $version->version]),
                ];
            }
        }

        return $history;
    }

    private function currentEnrollment(): ?EnrollmentRecord
    {
        return EnrollmentRecord::query()
            ->with([
                'term',
                'currentProposalVersion.items.section',
                'currentProposalVersion.items.reservation',
                'currentProposalVersion.confirmation',
                'termAccount',
                'currentCorVersion',
            ])
            ->where('credential_user_id', $this->actor()->id)
            ->latest('updated_at')
            ->first();
    }

    private function actor(): User
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    private function failedRegistrationNotification(): ?OperationalEvent
    {
        $enrollment = $this->currentEnrollment();

        return $enrollment instanceof EnrollmentRecord
            ? OperationalEvent::query()
                ->where('event_domain', OperationalEvent::DomainNotifications)
                ->whereIn('event_type', OperationalEvent::registrationNotificationTypes())
                ->where('user_id', $this->actor()->id)
                ->where('status', OperationalEvent::StatusFailed)
                ->latest('id')
                ->first()
            : null;
    }

    private function failure(string $title, Throwable $exception): void
    {
        Notification::make()->title($title)->body($exception->getMessage())->danger()->send();
    }
}
