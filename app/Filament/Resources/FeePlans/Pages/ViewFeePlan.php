<?php

namespace App\Filament\Resources\FeePlans\Pages;

use App\Actions\Finance\CreateSuccessorFeePlan;
use App\Actions\Finance\PublishFeePlan;
use App\Actions\Finance\UpdateFeePlanDraft;
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
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View as SchemaView;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ViewFeePlan extends ViewRecord
{
    protected static string $resource = FeePlanResource::class;

    public function getTitle(): string
    {
        return $this->getRecord()->program?->name ?? 'Fee plan';
    }

    public function getSubheading(): ?string
    {
        $plan = $this->getRecord();
        $status = match ($plan->state) {
            FeePlan::StatePublished => 'Current plan',
            FeePlan::StateDraft => 'Draft',
            default => 'Superseded plan',
        };
        $date = match ($plan->state) {
            FeePlan::StatePublished => $plan->published_at,
            FeePlan::StateDraft => $plan->created_at,
            default => $plan->updated_at,
        };

        return $plan->term?->label.' · '.$status.' · '.($date?->timezone(config('app.display_timezone'))->format('j M Y') ?? 'Date unavailable');
    }

    public function getBreadcrumbs(): array
    {
        return [FeePlanResource::getUrl('index') => 'Fee Plans'];
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

        return $record;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('editDraft')
                ->label('Edit draft')->color('gray')
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
                    $this->redirect(FeePlanResource::getUrl('view', ['record' => $this->getRecord()]));
                }),
            Action::make('publish')
                ->label('Publish plan')
                ->visible(fn (): bool => $this->getRecord()->state === FeePlan::StateDraft || collect($this->mountedActions ?? [])->contains('name', 'publish'))
                ->disabled(fn (): bool => ! collect($this->mountedActions ?? [])->contains('name', 'publish') && app(PublishFeePlan::class)->readiness($this->getRecord()) !== null)
                ->modalHeading('Publish current draft')->modalDescription(null)->modalIcon(null)
                ->modalWidth(Width::FourExtraLarge)->extraModalWindowAttributes(['autofocus' => true, 'tabindex' => '-1'])->stickyModalHeader()->stickyModalFooter()->modalFooterActionsAlignment(Alignment::End)->modalSubmitActionLabel('Publish plan')
                ->fillForm(fn (): array => $this->reviewedPlanData())
                ->schema([
                    Hidden::make('reviewed_content_fingerprint')->required(),
                    Hidden::make('reviewed_plan'),
                    SchemaView::make('filament.admin.fee-plans.publish-confirmation')->viewData(fn (Get $get): array => ['reviewed' => $get('reviewed_plan')]),
                    Grid::make(2)->schema([
                        TextInput::make('authority_reference')->label('Approval reference')->required()->maxLength(255),
                        DatePicker::make('authority_date')->label('Approval date')->required(),
                    ])->columnSpanFull(),
                    TextInput::make('no_payment_required_authority_reference')->label('Zero-payment approval reference')
                        ->helperText('Record the institutional approval for requiring no enrollment payment.')
                        ->visible(fn (Get $get): bool => (float) ($get('reviewed_plan.enrollment_payment') ?? -1) === 0.0)
                        ->required(fn (Get $get): bool => (float) ($get('reviewed_plan.enrollment_payment') ?? -1) === 0.0)
                        ->maxLength(255),
                ])
                ->action(function (array $data): void {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User, 403);
                    try {
                        app(PublishFeePlan::class)->execute($this->getRecord(), $actor, $data['authority_reference'], CarbonImmutable::parse($data['authority_date'], config('app.timezone')), $data['no_payment_required_authority_reference'] ?? null, expectedContentFingerprint: (string) $data['reviewed_content_fingerprint']);
                    } catch (ValidationException $exception) {
                        Notification::make()->title('Plan was not published')->body(collect($exception->errors())->flatten()->first())->danger()->send();
                        throw $exception;
                    }
                    $this->redirect(FeePlanResource::getUrl('view', ['record' => $this->getRecord()]));
                }),
            Action::make('createSuccessor')
                ->label('Create draft')
                ->visible(fn (): bool => $this->getRecord()->state === FeePlan::StatePublished || collect($this->mountedActions ?? [])->contains('name', 'createSuccessor'))
                ->modalHeading('Create draft from current plan')->modalDescription(null)->modalIcon(null)
                ->modalWidth(Width::TwoExtraLarge)->modalFooterActionsAlignment(Alignment::End)->modalSubmitActionLabel('Create draft')
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
