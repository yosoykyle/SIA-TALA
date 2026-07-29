<?php

namespace App\Filament\Pages;

use App\Actions\Integrations\Payments\PayMongoCheckoutRecoveryService;
use App\Actions\Integrations\Payments\PayMongoReconciliationService;
use App\Filament\Resources\PaymentAttempts\PaymentAttemptResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Models\Assessment;
use App\Models\OperationalEvent;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\StudentProfile;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class PayMongoReconciliation extends Page implements HasTable
{
    use InteractsWithTable;

    /** @var array<int, PaymentAttempt|null> */
    private array $attemptCache = [];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Accounting';

    protected static ?string $navigationLabel = 'Payment Exceptions';

    protected static ?int $navigationSort = 22;

    protected static ?string $slug = 'paymongo-reconciliation';

    protected string $view = 'filament.pages.pay-mongo-reconciliation';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->canProcessPayments();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function getTitle(): string
    {
        return 'Payment Exceptions';
    }

    public function getSubheading(): ?string
    {
        return 'Review failed or mismatched PayMongo evidence. A browser return never posts a payment by itself.';
    }

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('recoverCheckout')
                ->label('Recover a PayMongo checkout')
                ->icon(Heroicon::OutlinedArrowPath)
                ->schema([
                    Select::make('payment_attempt_id')
                        ->label('Pending or expired PayMongo Payment Attempt')
                        ->options(fn (): array => PaymentAttempt::query()
                            ->with(['studentProfile.user', 'assessment'])
                            ->where('provider', 'paymongo')
                            ->whereNotNull('provider_checkout_id')
                            ->whereIn('status', ['pending', 'expired'])
                            ->whereHas('assessment', fn (Builder $query): Builder => $query->where('state', Assessment::StateActive))
                            ->latest('id')
                            ->limit(100)
                            ->get()
                            ->mapWithKeys(fn (PaymentAttempt $attempt): array => [$attempt->id => $attempt->displayLabel()])
                            ->all())
                        ->searchable()
                        ->required()
                        ->native(false),
                ])
                ->modalHeading('Recover a known PayMongo checkout')
                ->modalDescription('TALA will retrieve this checkout from PayMongo. A reported payment still requires an Accounting decision before any ledger posting.')
                ->modalSubmitActionLabel('Retrieve provider state')
                ->action(function (array $data): void {
                    $result = $this->recoveryService()->recover(
                        (int) $data['payment_attempt_id'],
                        $this->actor(),
                    );

                    Notification::make()
                        ->title(match ($result['status']) {
                            'review_required' => 'Provider payment found — Accounting confirmation required',
                            'expired' => 'Checkout confirmed expired',
                            'failed' => 'Provider payment attempt confirmed failed',
                            'duplicate' => 'Checkout recovery was already processed',
                            default => 'Checkout remains pending',
                        })
                        ->color($result['status'] === 'review_required' ? 'warning' : 'success')
                        ->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(OperationalEvent::query()
                ->where('event_domain', OperationalEvent::DomainIntegration)
                ->where('integration', OperationalEvent::IntegrationPayMongo)
                ->whereIn('channel', [OperationalEvent::ChannelWebhook, OperationalEvent::ChannelProviderApi])
                ->whereIn('status', [OperationalEvent::StatusFailed, OperationalEvent::StatusReviewRequired])
                ->latest('occurred_at'))
            ->columns([
                TextColumn::make('external_id')
                    ->label('Technical Event ID')
                    ->searchable()
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('source')
                    ->label('Evidence Source')
                    ->state(fn (OperationalEvent $record): string => $record->channel === OperationalEvent::ChannelProviderApi
                        ? 'Provider recovery'
                        : 'Signed webhook')
                    ->badge(),
                TextColumn::make('event_type')
                    ->label('Event Type')
                    ->formatStateUsing(fn (string $state): string => str($state)->replace('.', ' ')->headline()->toString())
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => str($state)->replace('_', ' ')->headline()->toString())
                    ->color(fn (string $state): string => $state === OperationalEvent::StatusFailed ? 'danger' : 'warning'),
                TextColumn::make('review_reason')
                    ->label('Reason')
                    ->state(fn (OperationalEvent $record): string => $this->reasonLabel($record))
                    ->badge()
                    ->color('warning'),
                TextColumn::make('next_step')
                    ->label('Next Step')
                    ->state(fn (OperationalEvent $record): string => $this->nextStepLabel($record))
                    ->wrap(),
                TextColumn::make('student')
                    ->state(fn (OperationalEvent $record): string => $this->studentLabel($record))
                    ->wrap(),
                TextColumn::make('assessment')
                    ->state(fn (OperationalEvent $record): string => $this->assessmentLabel($record)),
                TextColumn::make('amount')
                    ->state(fn (OperationalEvent $record): string => $this->paymentLabel($record))
                    ->wrap(),
                TextColumn::make('occurred_at')
                    ->label('Received')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('channel')
                    ->label('Evidence Source')
                    ->options([
                        OperationalEvent::ChannelWebhook => 'Signed webhook',
                        OperationalEvent::ChannelProviderApi => 'Provider recovery',
                    ]),
                SelectFilter::make('status')
                    ->options([
                        OperationalEvent::StatusFailed => 'Failed processing',
                        OperationalEvent::StatusReviewRequired => 'Accounting review required',
                    ]),
                SelectFilter::make('reason')
                    ->label('Reason')
                    ->options([
                        'unknown_reference' => 'Unknown Reference',
                        'processing_failed' => 'Processing Failed',
                        'amount_mismatch' => 'Amount Mismatch',
                        'missing_tala_reference' => 'Missing TALA Reference',
                        'reference_mismatch' => 'Reference Mismatch',
                        'recovered_paid_without_webhook' => 'Recovered Without Webhook',
                        'refund_or_reversal' => 'Refund or Reversal',
                        'unknown_refund_payment' => 'Unknown Refund Payment',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['value'] ?? null),
                        fn (Builder $events): Builder => $events->where(
                            'diagnostics->reason',
                            (string) $data['value'],
                        ),
                    )),
            ])
            ->recordActions([
                Action::make('linkAndReprocess')
                    ->label('Link and Reprocess')
                    ->icon(Heroicon::OutlinedLink)
                    ->visible(fn (OperationalEvent $record): bool => $record->channel === OperationalEvent::ChannelWebhook
                        && $record->status === OperationalEvent::StatusReviewRequired
                        && data_get($record->diagnostics, 'reason') === 'unknown_reference')
                    ->schema([
                        Select::make('payment_attempt_id')
                            ->label('Eligible PayMongo Payment Attempt')
                            ->options(fn (): array => PaymentAttempt::query()
                                ->with(['studentProfile.user', 'assessment'])
                                ->where('provider', 'paymongo')
                                ->whereIn('status', ['pending', 'under_review'])
                                ->whereHas('assessment', fn (Builder $query): Builder => $query->where('state', Assessment::StateActive))
                                ->latest('id')
                                ->limit(100)
                                ->get()
                                ->mapWithKeys(fn (PaymentAttempt $attempt): array => [$attempt->id => $attempt->displayLabel()])
                                ->all())
                            ->searchable()
                            ->required()
                            ->native(false),
                        $this->reasonField(),
                    ])
                    ->modalHeading('Link persisted evidence to a Payment Attempt')
                    ->modalSubmitActionLabel('Link and Reprocess')
                    ->action(function (OperationalEvent $record, array $data): void {
                        $this->service()->linkAndReprocess(
                            $record->id,
                            (int) $data['payment_attempt_id'],
                            (string) $data['reason'],
                            $this->actor(),
                        );
                        Notification::make()->title('PayMongo evidence linked and requeued')->success()->send();
                    }),
                Action::make('reprocess')
                    ->label('Reprocess')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->visible(fn (OperationalEvent $record): bool => $record->channel === OperationalEvent::ChannelWebhook
                        && $record->status === OperationalEvent::StatusFailed
                        && data_get($record->diagnostics, 'reason') === 'processing_failed')
                    ->schema([$this->reasonField()])
                    ->requiresConfirmation()
                    ->action(function (OperationalEvent $record, array $data): void {
                        $this->service()->reprocess($record->id, (string) $data['reason'], $this->actor());
                        Notification::make()->title('PayMongo evidence requeued')->success()->send();
                    }),
                Action::make('confirm')
                    ->label('Confirm Payment')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->visible(fn (OperationalEvent $record): bool => $record->channel === OperationalEvent::ChannelWebhook
                        && $record->status === OperationalEvent::StatusReviewRequired
                        && $record->related_record_type === Payment::class
                        && in_array(data_get($record->diagnostics, 'reason'), ['amount_mismatch', 'missing_tala_reference', 'reference_mismatch'], true))
                    ->schema([$this->reasonField()])
                    ->requiresConfirmation()
                    ->action(function (OperationalEvent $record, array $data): void {
                        $this->service()->confirm($record->id, (string) $data['reason'], $this->actor());
                        Notification::make()->title('PayMongo payment confirmed and posted')->success()->send();
                    }),
                Action::make('reject')
                    ->label('Reject Evidence')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->visible(fn (OperationalEvent $record): bool => $record->channel === OperationalEvent::ChannelWebhook
                        && $record->status === OperationalEvent::StatusReviewRequired
                        && ! in_array(data_get($record->diagnostics, 'reason'), ['refund_or_reversal', 'unknown_refund_payment'], true))
                    ->schema([$this->reasonField()])
                    ->requiresConfirmation()
                    ->action(function (OperationalEvent $record, array $data): void {
                        $this->service()->reject($record->id, (string) $data['reason'], $this->actor());
                        Notification::make()->title('PayMongo evidence rejected')->success()->send();
                    }),
                Action::make('confirmRecovered')
                    ->label('Confirm Recovered Payment')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->visible(fn (OperationalEvent $record): bool => $record->channel === OperationalEvent::ChannelProviderApi
                        && $record->status === OperationalEvent::StatusReviewRequired
                        && data_get($record->diagnostics, 'reason') === 'recovered_paid_without_webhook')
                    ->schema([$this->reasonField()])
                    ->requiresConfirmation()
                    ->modalDescription('Confirm only after the checkout reference, assessment, amount, currency, mode, and provider payment identifiers all match.')
                    ->action(function (OperationalEvent $record, array $data): void {
                        $this->recoveryService()->confirm(
                            $record->id,
                            (string) $data['reason'],
                            $this->actor(),
                        );
                        Notification::make()->title('Recovered PayMongo payment confirmed and posted')->success()->send();
                    }),
                Action::make('rejectRecovered')
                    ->label('Reject Recovered Evidence')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->visible(fn (OperationalEvent $record): bool => $record->channel === OperationalEvent::ChannelProviderApi
                        && $record->status === OperationalEvent::StatusReviewRequired
                        && data_get($record->diagnostics, 'reason') === 'recovered_paid_without_webhook')
                    ->schema([$this->reasonField()])
                    ->requiresConfirmation()
                    ->action(function (OperationalEvent $record, array $data): void {
                        $this->recoveryService()->reject(
                            $record->id,
                            (string) $data['reason'],
                            $this->actor(),
                        );
                        Notification::make()->title('Recovered PayMongo evidence rejected')->success()->send();
                    }),
                Action::make('openSource')
                    ->label('Open Source')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->url(fn (OperationalEvent $record): ?string => $this->sourceUrl($record))
                    ->visible(fn (OperationalEvent $record): bool => $this->sourceUrl($record) !== null),
            ])
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25)
            ->emptyStateHeading('No PayMongo exceptions need Accounting review')
            ->emptyStateDescription('Failed signed webhooks and provider-recovered payments awaiting a decision will appear here.');
    }

    private function reasonField(): Textarea
    {
        return Textarea::make('reason')
            ->label('Accounting reason')
            ->required()
            ->minLength(5)
            ->maxLength(1000)
            ->rows(3);
    }

    private function service(): PayMongoReconciliationService
    {
        return app(PayMongoReconciliationService::class);
    }

    private function recoveryService(): PayMongoCheckoutRecoveryService
    {
        return app(PayMongoCheckoutRecoveryService::class);
    }

    private function reasonLabel(OperationalEvent $event): string
    {
        return match (data_get($event->diagnostics, 'reason')) {
            'recovered_paid_without_webhook' => 'Accounting confirmation required',
            default => str((string) data_get($event->diagnostics, 'reason', 'review_required'))
                ->replace('_', ' ')
                ->headline()
                ->toString(),
        };
    }

    private function nextStepLabel(OperationalEvent $event): string
    {
        if ($event->channel === OperationalEvent::ChannelProviderApi) {
            return 'Confirm the exact provider evidence or reject it with a reason.';
        }

        return match (data_get($event->diagnostics, 'reason')) {
            'unknown_reference' => 'Link the evidence to the correct eligible Payment Attempt.',
            'processing_failed' => 'Review the failure, then reprocess the persisted signed webhook.',
            default => 'Review the evidence and choose the permitted Accounting action.',
        };
    }

    private function actor(): User
    {
        $actor = auth()->user();

        abort_unless($actor instanceof User && $actor->canProcessPayments(), 403);

        return $actor;
    }

    private function sourceAttempt(OperationalEvent $event): ?PaymentAttempt
    {
        if (array_key_exists($event->id, $this->attemptCache)) {
            return $this->attemptCache[$event->id];
        }

        if ($event->related_record_type === PaymentAttempt::class && $event->related_record_id !== null) {
            $attempt = PaymentAttempt::query()->with(['studentProfile.user', 'assessment'])->find($event->related_record_id);

            return $this->attemptCache[$event->id] = $attempt instanceof PaymentAttempt ? $attempt : null;
        }

        if ($event->related_record_type === Payment::class && $event->related_record_id !== null) {
            $payment = Payment::query()->find($event->related_record_id);

            if ($payment instanceof Payment) {
                $attempt = $payment->paymentAttempt()
                    ->with(['studentProfile.user', 'assessment'])
                    ->first();

                return $this->attemptCache[$event->id] = $attempt instanceof PaymentAttempt ? $attempt : null;
            }
        }

        $linkedAttemptId = data_get($event->diagnostics, 'reconciliation.linked_attempt_id');

        if (! is_numeric($linkedAttemptId)) {
            return $this->attemptCache[$event->id] = null;
        }

        $attempt = PaymentAttempt::query()
            ->with(['studentProfile.user', 'assessment'])
            ->find((int) $linkedAttemptId);

        return $this->attemptCache[$event->id] = $attempt instanceof PaymentAttempt ? $attempt : null;
    }

    private function studentLabel(OperationalEvent $event): string
    {
        $attempt = $this->sourceAttempt($event);
        $student = $attempt instanceof PaymentAttempt
            ? StudentProfile::query()->with('user')->find($attempt->student_profile_id)
            : null;

        if (! $student instanceof StudentProfile) {
            return 'Unlinked';
        }

        return collect([$student->student_number, $student->user?->name])->filter()->implode(' / ');
    }

    private function assessmentLabel(OperationalEvent $event): string
    {
        $attempt = $this->sourceAttempt($event);

        return $attempt?->assessment_id !== null ? 'Assessment #'.$attempt->assessment_id : 'Unlinked';
    }

    private function paymentLabel(OperationalEvent $event): string
    {
        if ($event->related_record_type === Payment::class && $event->related_record_id !== null) {
            $payment = Payment::query()->find($event->related_record_id);

            if ($payment instanceof Payment) {
                return $payment->currency.' '.number_format((float) $payment->amount, 2);
            }
        }

        $attempt = $this->sourceAttempt($event);

        return $attempt instanceof PaymentAttempt
            ? $attempt->currency.' '.number_format((float) $attempt->amount, 2)
            : 'Not resolved';
    }

    private function sourceUrl(OperationalEvent $event): ?string
    {
        if ($event->related_record_type === Payment::class && $event->related_record_id !== null) {
            return PaymentResource::getUrl('view', ['record' => $event->related_record_id]);
        }

        $attempt = $this->sourceAttempt($event);

        return $attempt instanceof PaymentAttempt
            ? PaymentAttemptResource::getUrl('view', ['record' => $attempt])
            : null;
    }
}
