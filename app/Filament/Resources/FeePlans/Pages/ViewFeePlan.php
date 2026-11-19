<?php

namespace App\Filament\Resources\FeePlans\Pages;

use App\Actions\Finance\AccountingDisplay;
use App\Actions\Finance\CreateSuccessorFeePlan;
use App\Actions\Finance\PublishFeePlan;
use App\Actions\Finance\UpdateFeePlanDraft;
use App\Filament\Resources\Enrollments\EnrollmentResource;
use App\Filament\Resources\FeePlans\FeePlanResource;
use App\Filament\Resources\FeePlans\Schemas\FeePlanForm;
use App\Models\Assessment;
use App\Models\FeePlan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section as SchemaSection;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View as SchemaView;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ViewFeePlan extends ViewRecord
{
    protected static string $resource = FeePlanResource::class;

    private bool $draftReadinessResolved = false;

    private ?string $draftReadinessCache = null;

    public function draftReadiness(): ?string
    {
        $record = $this->getRecord();

        if ($record->state !== FeePlan::StateDraft) {
            return null;
        }

        if (! $this->draftReadinessResolved) {
            $this->draftReadinessResolved = true;
            $this->draftReadinessCache = app(PublishFeePlan::class)->readiness($record);
        }

        return $this->draftReadinessCache;
    }

    public function getTitle(): string
    {
        return $this->getRecord()->program?->name ?? 'Fee plan';
    }

    public function getSubheading(): ?string
    {
        $plan = $this->getRecord();
        $status = AccountingDisplay::feePlanStatusLabel($plan->state);
        $termLabel = collect([$plan->term?->academicYear?->label, $plan->term?->label])->filter()->implode(' · ');
        $date = match ($plan->state) {
            FeePlan::StatePublished => $plan->published_at,
            FeePlan::StateDraft => $plan->updated_at ?? $plan->created_at,
            default => $plan->published_at ?? $plan->updated_at,
        };
        $datePrefix = match ($plan->state) {
            FeePlan::StatePublished => 'Updated ',
            FeePlan::StateDraft => 'Saved ',
            default => 'Published ',
        };
        $dateLabel = $date !== null ? $datePrefix.AccountingDisplay::dateTime($date, 'M j, Y') : null;

        return collect([
            $termLabel !== '' ? $termLabel : null,
            $status,
            $dateLabel,
        ])->filter()->implode(' · ');
    }

    /** @return array<string|int, string> */
    public function getBreadcrumbs(): array
    {
        return [
            EnrollmentResource::getUrl('index') => 'Overview',
            FeePlanResource::getUrl('index') => 'Fee plans',
            $this->getTitle(),
        ];
    }

    private function reviewedPlanData(): array
    {
        return DB::transaction(function (): array {
            $plan = FeePlan::query()->with(['program', 'term', 'charges', 'obligations'])->whereKey($this->getRecord()->id)->lockForUpdate()->firstOrFail();

            return [
                'reviewed_content_fingerprint' => $plan->contentFingerprint(),
                'charges' => $plan->charges->map->only(['code', 'label', 'category', 'amount'])->all(),
                'obligations' => $plan->obligations->map->only(['code', 'label', 'purpose', 'amount', 'due_at', 'required_for_enrollment'])->all(),
                'reviewed_plan' => [
                    'program' => $plan->program?->name,
                    'term' => $plan->term?->label,
                    'version' => $plan->version,
                    'authority_reference' => $plan->authority_reference,
                    'total' => $plan->charges->sum('amount'),
                    'enrollment_payment' => $plan->obligations->where('required_for_enrollment', true)->sum('amount'),
                    'payment_count' => $plan->obligations->count(),
                    'charges' => $plan->charges->map->only(['label', 'amount'])->all(),
                    'payments' => $plan->obligations->map(fn ($payment): array => ['label' => $payment->label, 'amount' => $payment->amount, 'due_at' => $payment->due_at?->toIso8601String(), 'required_for_enrollment' => $payment->required_for_enrollment])->all(),
                    'predecessor_version' => $plan->supersedes?->version,
                    'published_at' => $plan->published_at?->toIso8601String(),
                    'retained_accounts' => $plan->supersedes_fee_plan_id === null ? 0 : Assessment::query()->where('fee_plan_id', $plan->supersedes_fee_plan_id)->distinct()->count('term_account_id'),
                ],
            ];
        });
    }

    public function getRecord(): FeePlan
    {
        $record = parent::getRecord();
        abort_unless($record instanceof FeePlan, 404);

        return $record->loadMissing(['program', 'term.academicYear', 'charges', 'obligations', 'supersedes', 'publisher']);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('editDraft')
                ->label('Edit draft')->icon(Heroicon::PencilSquare)->color('gray')
                ->visible(fn (): bool => $this->getRecord()->state === FeePlan::StateDraft || collect($this->mountedActions ?? [])->contains('name', 'editDraft'))
                ->modalHeading('Edit draft')
                ->modalDescription('Changes stay in this draft until it is approved and published.')->extraModalWindowAttributes(['autofocus' => true, 'tabindex' => '-1'])
                ->modalWidth(Width::FiveExtraLarge)->stickyModalHeader()->stickyModalFooter()->modalFooterActionsAlignment(Alignment::End)->modalSubmitActionLabel('Save draft')
                ->fillForm(fn (): array => $this->reviewedPlanData())
                ->schema([
                    Hidden::make('reviewed_plan'),
                    SchemaView::make('filament.admin.fee-plans.edit-context')->viewData(fn (Get $get): array => ['reviewed' => $get('reviewed_plan')]),
                    ...FeePlanForm::components(),
                ])
                ->action(function (array $data): void {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User, 403);
                    $rows = FeePlanForm::rows($data);
                    try {
                        app(UpdateFeePlanDraft::class)->execute($this->getRecord(), $rows['charges'], $rows['obligations'], $actor, expectedContentFingerprint: (string) $data['reviewed_content_fingerprint']);
                    } catch (ValidationException $exception) {
                        Notification::make()->title('Draft was not saved')->body(collect($exception->errors())->flatten()->first())->danger()->send();
                        throw $exception;
                    }
                    $this->draftReadinessResolved = false;
                    $this->draftReadinessCache = null;
                    $this->redirect(FeePlanResource::getUrl('view', ['record' => $this->getRecord()]));
                }),
            Action::make('publish')
                ->label('Publish plan')->icon(Heroicon::Check)
                ->visible(fn (): bool => $this->getRecord()->state === FeePlan::StateDraft || collect($this->mountedActions ?? [])->contains('name', 'publish'))
                ->disabled(fn (): bool => ! collect($this->mountedActions ?? [])->contains('name', 'publish') && $this->draftReadiness() !== null)
                ->modalHeading('Publish current draft')->modalDescription(null)->modalIcon(null)
                ->modalWidth(Width::FourExtraLarge)->extraModalWindowAttributes(['autofocus' => true, 'tabindex' => '-1'])->stickyModalHeader()->stickyModalFooter()->modalFooterActionsAlignment(Alignment::End)->modalSubmitActionLabel('Publish plan')
                ->fillForm(fn (): array => $this->reviewedPlanData())
                ->schema([
                    Hidden::make('reviewed_content_fingerprint')->required(),
                    Hidden::make('reviewed_plan'),
                    SchemaView::make('filament.admin.fee-plans.publish-confirmation')->viewData(fn (Get $get): array => ['reviewed' => $get('reviewed_plan')]),
                    SchemaSection::make('Approval details')
                        ->description('TALA automatically records who published this plan and when.')
                        ->contained(false)
                        ->extraAttributes(['class' => 'accounting-finance-form-section'])
                        ->gridContainer()
                        ->columns(['default' => 1, '@sm' => 2])
                        ->schema([
                            TextInput::make('authority_reference')
                                ->label('Approval note or memo (optional)')
                                ->placeholder('e.g., Memo 2026-014 or Board approval')
                                ->maxLength(255),
                            DatePicker::make('authority_date')->label('Approval date (optional)'),
                            TextInput::make('no_payment_required_authority_reference')
                                ->label('Approval memo or reference for zero enrollment payment')
                                ->placeholder('e.g., Memo 2026-014 or approving office')
                                ->helperText('Required when the enrollment payment is PHP 0.00.')
                                ->visible(fn (Get $get): bool => (float) ($get('reviewed_plan.enrollment_payment') ?? -1) === 0.0)
                                ->required(fn (Get $get): bool => (float) ($get('reviewed_plan.enrollment_payment') ?? -1) === 0.0)
                                ->maxLength(255)
                                ->columnSpanFull(),
                        ])
                        ->columnSpanFull(),
                ])
                ->action(function (array $data): void {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User, 403);
                    try {
                        $authorityReference = filled($data['authority_reference'] ?? null) ? (string) $data['authority_reference'] : null;
                        $authorityDate = filled($data['authority_date'] ?? null) ? CarbonImmutable::parse($data['authority_date'], config('app.timezone')) : null;
                        app(PublishFeePlan::class)->execute($this->getRecord(), $actor, $authorityReference, $authorityDate, $data['no_payment_required_authority_reference'] ?? null, expectedContentFingerprint: (string) $data['reviewed_content_fingerprint']);
                    } catch (ValidationException $exception) {
                        Notification::make()->title('Plan was not published')->body(collect($exception->errors())->flatten()->first())->danger()->send();
                        throw $exception;
                    }
                    $this->redirect(FeePlanResource::getUrl('view', ['record' => $this->getRecord()]));
                }),
            Action::make('createSuccessor')
                ->label('Edit plan')->icon(Heroicon::PencilSquare)
                ->visible(fn (): bool => $this->getRecord()->state === FeePlan::StatePublished || collect($this->mountedActions ?? [])->contains('name', 'createSuccessor'))
                ->modalHeading('Prepare an updated plan')->modalDescription(null)->modalIcon(null)
                ->modalWidth(Width::FourExtraLarge)->stickyModalHeader()->stickyModalFooter()->modalFooterActionsAlignment(Alignment::End)->modalSubmitActionLabel('Create draft')
                ->fillForm(fn (): array => $this->reviewedPlanData())
                ->schema([
                    Hidden::make('reviewed_content_fingerprint')->required(),
                    Hidden::make('reviewed_plan'),
                    SchemaView::make('filament.admin.fee-plans.successor-confirmation')->viewData(fn (Get $get): array => ['reviewed' => $get('reviewed_plan')]),
                ])
                ->action(function (array $data): void {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User, 403);
                    try {
                        $successor = app(CreateSuccessorFeePlan::class)->execute($this->getRecord(), $actor, expectedContentFingerprint: (string) $data['reviewed_content_fingerprint']);
                    } catch (ValidationException $exception) {
                        Notification::make()->title('Draft was not created')->body(collect($exception->errors())->flatten()->first())->danger()->send();
                        throw $exception;
                    }
                    $this->redirect(FeePlanResource::getUrl('view', ['record' => $successor]));
                }),
        ];
    }
}
