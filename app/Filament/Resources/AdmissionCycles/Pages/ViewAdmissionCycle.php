<?php

namespace App\Filament\Resources\AdmissionCycles\Pages;

use App\Actions\Admissions\AdmissionCycleReadinessService;
use App\Actions\Admissions\ChangeAdmissionCycle;
use App\Actions\Admissions\PublishAdmissionCycle;
use App\Filament\Resources\AdmissionApplications\AdmissionApplicationResource;
use App\Filament\Resources\AdmissionCycles\AdmissionCycleResource;
use App\Models\AdmissionCycle;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Support\Enums\Width;
use Illuminate\Validation\ValidationException;

class ViewAdmissionCycle extends ViewRecord
{
    protected static string $resource = AdmissionCycleResource::class;

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    public function getContentTabLabel(): ?string
    {
        return 'Cycle overview';
    }

    public function getTitle(): string
    {
        return $this->cycle()->label;
    }

    public function getBreadcrumb(): string
    {
        return 'Cycle overview';
    }

    public function getBreadcrumbs(): array
    {
        return [
            ...(AdmissionApplicationResource::canAccess() ? [AdmissionApplicationResource::getUrl() => 'Admissions'] : []),
            AdmissionCycleResource::getUrl() => 'Admission cycles',
            'Cycle overview',
        ];
    }

    protected function getHeaderActions(): array
    {
        $actions = [
            EditAction::make()
                ->label('Continue setup')
                ->color(fn (): string => app(AdmissionCycleReadinessService::class)->for($this->cycle())['blockers'] !== [] ? 'primary' : 'gray')
                ->outlined(fn (): bool => app(AdmissionCycleReadinessService::class)->for($this->cycle())['blockers'] === [])
                ->visible(fn (): bool => $this->cycle()->state === AdmissionCycle::StateDraft),
            Action::make('publish')
                ->label('Publish cycle')
                ->disabled(fn (): bool => app(AdmissionCycleReadinessService::class)->for($this->cycle())['blockers'] !== [])
                ->icon('heroicon-o-check-badge')
                ->color('primary')
                ->outlined(fn (): bool => app(AdmissionCycleReadinessService::class)->for($this->cycle())['blockers'] !== [])
                ->modalSubmitActionLabel('Publish cycle')
                ->requiresConfirmation()
                ->modalWidth(Width::Large)
                ->stickyModalHeader()
                ->stickyModalFooter()
                ->modalDescription(fn (): string => "Publishing {$this->cycle()->label} ({$this->cycle()->code}) makes its requirement sets active and opens applications according to its schedule. Publication reruns every readiness check for this cycle; failed sources remain visible with their owner.")
                ->schema($this->authoritySchema(includeReason: false))
                ->visible(fn (): bool => $this->cycle()->state === AdmissionCycle::StateDraft)
                ->action(fn (array $data, Action $action): mixed => $this->runCycleAction(
                    fn (AdmissionCycle $cycle, User $actor): AdmissionCycle => app(PublishAdmissionCycle::class)
                        ->execute($cycle, $actor, (string) $data['authority_reference']),
                    'Admission Cycle published',
                    $action,
                )),
            Action::make('close')
                ->label('Close applications now')
                ->modalDescription(fn (): string => "Closing {$this->cycle()->label} ({$this->cycle()->code}) stops new starts and first submissions for this cycle now. Existing corrections and Registrar reviews continue. Record the reason and approving authority.")
                ->icon('heroicon-o-lock-closed')
                ->color('warning')->outlined()
                ->modalSubmitActionLabel('Close applications now')
                ->requiresConfirmation()
                ->modalWidth(Width::TwoExtraLarge)
                ->stickyModalHeader()
                ->stickyModalFooter()
                ->schema($this->authoritySchema())
                ->visible(fn (): bool => $this->cycle()->state === AdmissionCycle::StatePublished
                    && $this->cycle()->closes_at?->isFuture())
                ->action(fn (array $data, Action $action): mixed => $this->runCycleAction(
                    fn (AdmissionCycle $cycle, User $actor): AdmissionCycle => app(ChangeAdmissionCycle::class)
                        ->close($cycle, $actor, (string) $data['reason'], (string) $data['authority_reference']),
                    'Admission Cycle closed',
                    $action,
                )),
            Action::make('extend')
                ->label('Extend application closing')
                ->modalSubmitActionLabel('Extend application closing')
                ->color('gray')->outlined()
                ->modalDescription(fn (): string => "Extending {$this->cycle()->label} ({$this->cycle()->code}) allows new starts and first submissions for this cycle until the new closing time. Existing applications retain their history.")
                ->icon('heroicon-o-calendar-days')
                ->modalWidth(Width::ThreeExtraLarge)
                ->stickyModalHeader()
                ->stickyModalFooter()
                ->schema($this->dateChangeSchema())
                ->visible(fn (): bool => $this->cycle()->state === AdmissionCycle::StatePublished
                    && $this->cycle()->closes_at?->isFuture())
                ->action(fn (array $data, Action $action): mixed => $this->runCycleAction(
                    fn (AdmissionCycle $cycle, User $actor): AdmissionCycle => app(ChangeAdmissionCycle::class)
                        ->extend(
                            $cycle,
                            $actor,
                            CarbonImmutable::parse((string) $data['closes_at']),
                            (string) $data['reason'],
                            (string) $data['authority_reference'],
                            filled($data['correction_closes_at'] ?? null)
                                ? CarbonImmutable::parse((string) $data['correction_closes_at'])
                                : null,
                        ),
                    'Admission Cycle extended',
                    $action,
                )),
            Action::make('reopen')
                ->label('Reopen applications')
                ->color('primary')
                ->modalSubmitActionLabel('Reopen applications')
                ->icon('heroicon-o-lock-open')
                ->modalWidth(Width::ThreeExtraLarge)
                ->stickyModalHeader()
                ->stickyModalFooter()
                ->modalDescription(fn (): string => "Reopening {$this->cycle()->label} ({$this->cycle()->code}) allows new starts and first submissions for this cycle until the new closing time. Existing applications and history are preserved.")
                ->schema($this->dateChangeSchema())
                ->visible(fn (): bool => $this->cycle()->state === AdmissionCycle::StatePublished
                    && ! $this->cycle()->closes_at?->isFuture())
                ->action(fn (array $data, Action $action): mixed => $this->runCycleAction(
                    fn (AdmissionCycle $cycle, User $actor): AdmissionCycle => app(ChangeAdmissionCycle::class)
                        ->reopen(
                            $cycle,
                            $actor,
                            CarbonImmutable::parse((string) $data['closes_at']),
                            (string) $data['reason'],
                            (string) $data['authority_reference'],
                            filled($data['correction_closes_at'] ?? null)
                                ? CarbonImmutable::parse((string) $data['correction_closes_at'])
                                : null,
                        ),
                    'Admission Cycle reopened',
                    $action,
                )),
            Action::make('extendCorrectionBoundary')
                ->label('Extend correction requests')
                ->modalSubmitActionLabel('Extend correction requests')
                ->color('gray')->outlined()
                ->modalDescription(fn (): string => "Extending the correction boundary for {$this->cycle()->label} ({$this->cycle()->code}) allows the Registrar to issue new correction requests for applicants in this cycle through the new boundary. This leaves the public application window unchanged.")
                ->icon('heroicon-o-arrow-right-circle')
                ->modalWidth(Width::TwoExtraLarge)
                ->stickyModalHeader()
                ->stickyModalFooter()
                ->schema([
                    DateTimePicker::make('correction_closes_at')
                        ->label('New correction requests end')
                        ->displayFormat('M j, Y · g:i A')
                        ->timezone('Asia/Manila')
                        ->prefixIcon('heroicon-o-clock')
                        ->placeholder('Select date and time')
                        ->helperText(fn (): string => 'Current correction boundary: '.($this->cycle()->correction_closes_at?->timezone('Asia/Manila')->format('M j, Y · g:i A') ?? 'Not set').'. The proposed boundary must be strictly after the current boundary.')
                        ->native(false)
                        ->minDate(fn (): ?string => $this->cycle()->correction_closes_at?->copy()->timezone((string) config('app.display_timezone'))->startOfDay()->format('Y-m-d H:i:s'))
                        ->required(),
                    ...$this->authoritySchema(),
                ])
                ->visible(fn (): bool => $this->cycle()->state === AdmissionCycle::StatePublished)
                ->action(fn (array $data, Action $action): mixed => $this->runCycleAction(
                    fn (AdmissionCycle $cycle, User $actor): AdmissionCycle => app(ChangeAdmissionCycle::class)
                        ->extendCorrectionBoundary(
                            $cycle,
                            $actor,
                            CarbonImmutable::parse((string) $data['correction_closes_at']),
                            (string) $data['reason'],
                            (string) $data['authority_reference'],
                        ),
                    'Correction boundary extended',
                    $action,
                )),
            Action::make('setDeadlines')
                ->label('Set admission deadlines')
                ->modalSubmitActionLabel('Save deadlines')
                ->color('gray')->outlined()
                ->icon('heroicon-o-calendar-days')
                ->modalDescription('Physical papers and the enrollment payment must be done by these times, or the application expires until the Registrar extends it. When the admission period ends, unfinished applications close for good and applicants create a new account for a later term. Leave a date blank if the school has not set it.')
                ->modalWidth(Width::ThreeExtraLarge)
                ->stickyModalHeader()
                ->stickyModalFooter()
                ->fillForm(fn (): array => [
                    'requirements_due_at' => $this->cycle()->requirements_due_at,
                    'payment_due_at' => $this->cycle()->payment_due_at,
                    'period_ends_at' => $this->cycle()->period_ends_at,
                ])
                ->schema([
                    Grid::make(['default' => 1, 'md' => 3])->schema([
                        $this->deadlinePicker('requirements_due_at', 'Physical papers due', 'heroicon-o-document-check'),
                        $this->deadlinePicker('payment_due_at', 'Enrollment payment due', 'heroicon-o-banknotes'),
                        $this->deadlinePicker('period_ends_at', 'Admission period ends', 'heroicon-o-lock-closed'),
                    ]),
                    ...$this->authoritySchema(),
                ])
                ->visible(fn (): bool => ! in_array($this->cycle()->state, [AdmissionCycle::StateCancelled, AdmissionCycle::StateDraft], true))
                ->action(fn (array $data, Action $action): mixed => $this->runCycleAction(
                    fn (AdmissionCycle $cycle, User $actor): AdmissionCycle => app(ChangeAdmissionCycle::class)->setDeadlines(
                        $cycle,
                        $actor,
                        filled($data['requirements_due_at'] ?? null) ? CarbonImmutable::parse((string) $data['requirements_due_at']) : null,
                        filled($data['payment_due_at'] ?? null) ? CarbonImmutable::parse((string) $data['payment_due_at']) : null,
                        filled($data['period_ends_at'] ?? null) ? CarbonImmutable::parse((string) $data['period_ends_at']) : null,
                        (string) $data['reason'],
                        (string) $data['authority_reference'],
                    ),
                    'Admission deadlines saved',
                    $action,
                )),
            Action::make('cancel')
                ->label('Cancel cycle')
                ->modalSubmitActionLabel('Cancel cycle')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->outlined()
                ->requiresConfirmation()
                ->modalWidth(Width::TwoExtraLarge)
                ->stickyModalHeader()
                ->stickyModalFooter()
                ->modalDescription(fn (): string => "Cancelling {$this->cycle()->label} ({$this->cycle()->code}) stops new applications and first submissions. Draft inspection and discard, active corrections, Registrar review, decisions and clearance remain available under their existing rules. Every recorded application and authority event is preserved.")
                ->schema($this->authoritySchema())
                ->visible(fn (): bool => $this->cycle()->state === AdmissionCycle::StatePublished)
                ->action(fn (array $data, Action $action): mixed => $this->runCycleAction(
                    fn (AdmissionCycle $cycle, User $actor): AdmissionCycle => app(ChangeAdmissionCycle::class)
                        ->cancel($cycle, $actor, (string) $data['reason'], (string) $data['authority_reference']),
                    'Admission Cycle cancelled',
                    $action,
                )),
            Action::make('discardDraft')
                ->label('Discard draft')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->outlined()
                ->requiresConfirmation()
                ->modalWidth(Width::Large)
                ->stickyModalHeader()
                ->stickyModalFooter()
                ->modalHeading('Discard cycle draft?')
                ->modalDescription(fn (): string => "Permanently removes {$this->cycle()->label} ({$this->cycle()->code}) and any draft requirement sets. Published cycles and cycles with applications or published requirement sets cannot be discarded.")
                ->modalSubmitActionLabel('Discard draft')
                ->visible(fn (): bool => auth()->user()?->can('delete', $this->cycle()) ?? false)
                ->action(function (): void {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User, 403);

                    try {
                        app(ChangeAdmissionCycle::class)->discardDraft($this->cycle(), $actor);
                    } catch (ValidationException $exception) {
                        Notification::make()
                            ->title('Draft cycle cannot be discarded')
                            ->body($exception->validator->errors()->first())
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Draft cycle discarded')
                        ->success()
                        ->send();

                    $this->redirect(AdmissionCycleResource::getUrl('index'));
                }),
        ];

        return [$actions[0], $actions[1], $actions[3], $actions[4], $actions[5], $actions[6], $actions[2], $actions[7], $actions[8]];
    }

    private function deadlinePicker(string $name, string $label, string $icon): DateTimePicker
    {
        return DateTimePicker::make($name)
            ->label($label)
            ->displayFormat('M j, Y · g:i A')
            ->timezone('Asia/Manila')
            ->prefixIcon($icon)
            ->placeholder('Not set')
            ->native(false);
    }

    /** @return list<TextInput|Textarea> */
    private function authoritySchema(bool $includeReason = true): array
    {
        $schema = [
            TextInput::make('authority_reference')
                ->label('Approval memo or reference')
                ->placeholder('e.g., Memo 2026-014 or approving office')
                ->helperText('Enter the school memo, approved calendar, or decision identifier authorizing this publication or change. TALA records your account and time automatically.')
                ->required()
                ->maxLength(255),
        ];

        if ($includeReason) {
            $schema[] = Textarea::make('reason')->placeholder('Explain why this change is needed')->required()->maxLength(1000);
        }

        return $schema;
    }

    /** @return list<Grid|TextInput|Textarea> */
    private function dateChangeSchema(): array
    {
        return [
            Grid::make(['default' => 1, 'md' => 2])
                ->schema([
                    DateTimePicker::make('closes_at')
                        ->label('New public closing')
                        ->displayFormat('M j, Y · g:i A')
                        ->timezone('Asia/Manila')
                        ->prefixIcon('heroicon-o-calendar')
                        ->placeholder('Select date and time')
                        ->helperText(fn (): string => 'Current public closing: '.($this->cycle()->closes_at?->timezone('Asia/Manila')->format('M j, Y · g:i A') ?? 'Not set').'.')
                        ->native(false)
                        ->minDate(fn (): string => now((string) config('app.display_timezone'))->startOfDay()->format('Y-m-d H:i:s'))
                        ->required(),
                    DateTimePicker::make('correction_closes_at')
                        ->label('New correction requests end (if needed)')
                        ->displayFormat('M j, Y · g:i A')
                        ->timezone('Asia/Manila')
                        ->prefixIcon('heroicon-o-clock')
                        ->placeholder('Select date and time')
                        ->helperText(fn (): string => 'Current correction boundary: '.($this->cycle()->correction_closes_at?->timezone('Asia/Manila')->format('M j, Y · g:i A') ?? 'Not set').'. Required only when the new public closing would pass the current correction boundary.')
                        ->native(false),
                ]),
            ...$this->authoritySchema(),
        ];
    }

    private function cycle(): AdmissionCycle
    {
        $record = $this->getRecord();
        abort_unless($record instanceof AdmissionCycle, 404);

        return $record;
    }

    /** @param callable(AdmissionCycle, User): AdmissionCycle $operation */
    private function runCycleAction(callable $operation, string $successTitle, ?Action $action = null): mixed
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        try {
            $operation($this->cycle(), $actor);
            Notification::make()->title($successTitle)->success()->send();
            $this->refreshFormData(['state', 'opens_at', 'closes_at', 'correction_closes_at', 'requirements_due_at', 'payment_due_at', 'period_ends_at']);
        } catch (ValidationException $exception) {
            Notification::make()
                ->title('Admission Cycle action blocked')
                ->body($exception->validator->errors()->first())
                ->danger()
                ->send();

            $action?->halt();
        }

        return null;
    }
}
