<?php

namespace App\Filament\Student\Pages;

use App\Actions\Finance\EnrollmentPaymentRequirementProjection;
use App\Actions\Finance\SchoolPaymentChannels;
use App\Actions\Finance\StudentTermAccountPresenter;
use App\Actions\Finance\SubmitPaymentEvidence;
use App\Actions\Finance\TermAccountProjection;
use App\Actions\StudentLifecycle\LifecycleAccountingReview;
use App\Models\Enrollment;
use App\Models\Hold;
use App\Models\PaymentEvidenceVersion;
use App\Models\StudentProfile;
use App\Models\TermAccount;
use App\Models\User;
use App\Support\SafeUserNotification;
use App\Support\StudentPaymentActions;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Http\UploadedFile;
use Livewire\Attributes\Locked;
use Throwable;

class Finance extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Fees and payments';

    protected static ?string $title = 'Fees and payments';

    protected string $view = 'filament.student.pages.finance';

    /** @return array<string|int, string> */
    public function getBreadcrumbs(): array
    {
        return [Dashboard::getUrl(panel: 'student') => 'Overview', ...($this->showHistory
            ? [static::getUrl(panel: 'student') => 'Fees and payments', 'Past statements and payments']
            : ['Fees and payments'])];
    }

    /** @var array<string, mixed> */
    public array $finance = [];

    #[Locked]
    public bool $showHistory = false;

    #[Locked]
    public ?int $pastAccountId = null;

    public function mount(): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        $view = request()->query('view');
        $accountId = request()->query('account');
        abort_if($view !== null && $view !== 'history', 404);
        abort_if($accountId !== null && ($view !== 'history' || ! is_string($accountId)
            || ! ctype_digit($accountId) || filter_var($accountId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false), 404);
        $this->showHistory = $view === 'history';
        $this->pastAccountId = $accountId !== null ? (int) $accountId : null;
        $this->refreshFinance();
    }

    public function refreshFinance(): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User && $actor->hasRole('student'), 403);
        $this->finance = app(StudentTermAccountPresenter::class)->forUser($actor);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User && $actor->hasRole('student'), 403);
        $finance = app(StudentTermAccountPresenter::class)->forUser($actor);
        $account = $finance['account'] ?? null;
        $pastAccounts = TermAccount::query()->with('term')->where('credential_user_id', $actor->id)
            ->when($account !== null, fn ($query) => $query->where('id', '<>', $account->id))->latest('id')->get();
        $pastAccount = null;
        if ($this->pastAccountId !== null) {
            abort_unless($this->showHistory && $pastAccounts->contains('id', $this->pastAccountId), 404);
            $pastAccount = TermAccount::query()->where('credential_user_id', $actor->id)->with([
                'term', 'assessments' => fn ($query) => $query->latest('version'),
                'payments' => fn ($query) => $query->latest('paid_at'),
                'paymentEvidenceVersions' => fn ($query) => $query->latest('version'),
                'coverages' => fn ($query) => $query->latest('id'),
            ])->findOrFail($this->pastAccountId);
        }
        ($finance['assessment'] ?? null)?->loadMissing('lines');
        $reviewCase = ($this->showHistory ? $pastAccount : $account)?->enrollment;

        $howToPayDisplay = $finance['how_to_pay_display'] ?? app(SchoolPaymentChannels::class)->forDisplay();
        $hasEnabledDigitalPaymentChannels = (bool) ($finance['has_enabled_digital_channels'] ?? ($howToPayDisplay['hasEnabledDigitalChannels'] ?? false));

        return [
            'pastAccounts' => $pastAccounts,
            'activeFinancialHolds' => $actor->studentProfile?->holds()
                ->where('hold_type', Hold::TypeFinancial)
                ->where('status', Hold::StatusActive)
                ->where(fn ($query) => $query->whereNull('effective_at')->orWhere('effective_at', '<=', now()))
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->get() ?? collect(),
            'unavailableAccountReviews' => $account === null && ! $this->showHistory
                ? Enrollment::query()->where('credential_user_id', $actor->id)->whereDoesntHave('termAccount')->with('term')->get()
                    ->map(fn (Enrollment $case): array => [
                        'id' => $case->id, 'term' => $case->term?->label ?? 'Term unavailable',
                        'status' => app(LifecycleAccountingReview::class)->safeStatus($case),
                    ])->filter(fn (array $review): bool => $review['status']['pending'] + $review['status']['reviewed'] > 0)
                : collect(),
            'studentChangeReviewStatus' => $reviewCase !== null ? app(LifecycleAccountingReview::class)->safeStatus($reviewCase) : null,
            'pastAccount' => $pastAccount,
            'accountData' => $finance,
            'howToPayDisplay' => $howToPayDisplay,
            'whereToPayDisplay' => $howToPayDisplay,
            'hasEnabledDigitalPaymentChannels' => $hasEnabledDigitalPaymentChannels,
            'requirement' => $account !== null ? app(EnrollmentPaymentRequirementProjection::class)->forEnrollment($account->enrollment) : null,
            'position' => $account !== null ? app(TermAccountProjection::class)->forAccount($account) : null,
            'evidence' => $account?->paymentEvidenceVersions ?? collect(),
            'payments' => $account?->payments ?? collect(),
            'assessmentLines' => ($finance['assessment'] ?? null)?->lines ?? collect(),
            'latestEvidence' => $account?->paymentEvidenceVersions->first(),
        ];
    }

    protected function hasPayableObligations(): bool
    {
        if ($this->showHistory) {
            return false;
        }
        $account = $this->finance['account'] ?? null;
        if ($account === null) {
            return false;
        }

        $position = app(TermAccountProjection::class)->forAccount($account);

        return $position['state'] !== 'Unavailable'
            && collect($position['obligations'])->contains(fn (array $obligation): bool => $obligation['is_due'] && (float) $obligation['available_balance'] > 0);
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function statementAction(): Action
    {
        return $this->financeActions()[0];
    }

    public function submitEvidenceAction(): Action
    {
        return $this->financeActions()[1];
    }

    /** @return list<Action> */
    private function financeActions(): array
    {
        return [
            Action::make('statement')
                ->label('View statement')
                ->icon('heroicon-o-document-text')
                ->url(fn (): string => route('finance.statement', data_get($this->finance, 'assessment.id', 0)))
                ->openUrlInNewTab()
                ->visible(fn (): bool => ! $this->showHistory && ($this->finance['available'] ?? false) === true)
                ->color('gray'),
            StudentPaymentActions::submitReceiptAction(Action::make('submitEvidence'))
                ->label(fn (): string => data_get($this->finance, 'state.latest_evidence_state') === PaymentEvidenceVersion::StateRejected
                    ? 'Upload corrected receipt' : 'Submit receipt')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('primary')
                ->visible(fn (): bool => $this->hasPayableObligations()
                    && data_get($this->finance, 'account.enrollment.studentProfile.lifecycle_status') !== StudentProfile::LifecycleCompleted
                    && app(SchoolPaymentChannels::class)->hasEnabledDigitalChannels())
                ->authorize(fn (): bool => app(SchoolPaymentChannels::class)->hasEnabledDigitalChannels())
                ->action(function (array $data): void {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User, 403);
                    $finance = app(StudentTermAccountPresenter::class)->forUser($actor);
                    $account = $finance['account'] ?? null;
                    $file = $data['evidence'] ?? null;
                    abort_unless($account !== null && $file instanceof UploadedFile, 404);
                    try {
                        $position = app(TermAccountProjection::class)->forAccount($account);
                        $targets = collect($position['obligations'])
                            ->filter(fn (array $obligation): bool => $obligation['is_due'] && (float) $obligation['available_balance'] > 0)
                            ->pluck('id')->all();
                        $resolvedChannel = StudentPaymentActions::resolveSubmissionChannel($data);
                        app(SubmitPaymentEvidence::class)->execute(
                            $account,
                            $actor,
                            $file,
                            (string) $data['claimed_amount'],
                            $resolvedChannel['channel'],
                            $data['paid_at'],
                            $data['payment_reference'] ?? null,
                            $targets,
                            $resolvedChannel['snapshot'],
                        );
                        $this->refreshFinance();
                        Notification::make()->title('Receipt submitted for Accounting review')->body('Submission posts no payment.')->success()->send();
                    } catch (Throwable $exception) {
                        SafeUserNotification::sendError(
                            'Receipt was not submitted',
                            $exception,
                            'Your receipt could not be saved. No payment was posted. Check your file and details, then try again.',
                            persistent: true,
                        );
                    }
                }),
        ];
    }
}
