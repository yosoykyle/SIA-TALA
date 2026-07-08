<?php

namespace App\Filament\Resources\DisposalReviews\Tables;

use App\Actions\StudentLifecycle\HoldEvaluationService;
use App\Enums\RetentionCategory;
use App\Models\DisposalReview;
use App\Models\Hold;
use App\Models\StudentProfile;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * TAL-92E: disposal-candidate table + `reviewDisposal` confirmation action.
 *
 * Owning contract: PRD §13.7.4 rule 8 ("disposal is held when a record is
 * under institutional, legal, audit, or active workflow hold" — not
 * optional) and §13.8 "Retention/disposal review" row. Built on the exact
 * shape of `App\Filament\Pages\IntegrationStatus::sendTestEmail` (accepted
 * TAL-92D template for a policy-gated confirmation action that writes one
 * audit-relevant record and shows a notification), adapted: no email,
 * writes one `DisposalReview` row + one `activity_log` entry instead of an
 * `OperationalEvent`.
 *
 * V1 candidate scope (this slice): Short-Operational-category candidates
 * only, starting with rejected duplicate profiles — i.e. `StudentProfile`
 * rows with `archived_at` set (see `DisposalReviewResource::getEloquentQuery()`).
 * Permanent and Archive-After-Review record types are excluded.
 */
class DisposalReviewsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('student_number')
                    ->label('Student Number')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('last_name')
                    ->label('Student')
                    ->formatStateUsing(fn (StudentProfile $record): string => $record->last_name.', '.$record->first_name)
                    ->searchable(['last_name', 'first_name']),
                TextColumn::make('archived_at')
                    ->label('Archived At')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('retention_category')
                    ->label('Retention Category')
                    ->state(fn (): string => RetentionCategory::ShortOperational->label())
                    ->badge()
                    ->color('warning'),
                TextColumn::make('hold_status')
                    ->label('Hold Status')
                    ->state(fn (StudentProfile $record): string => self::hasActiveBlockingHold($record) ? 'On Hold' : 'Clear')
                    ->badge()
                    ->color(fn (StudentProfile $record): string => self::hasActiveBlockingHold($record) ? 'danger' : 'success'),
                TextColumn::make('latest_decision')
                    ->label('Last Review Decision')
                    ->state(function (StudentProfile $record): string {
                        $review = self::latestReview($record);

                        return $review === null ? 'Not Yet Reviewed' : $review->decision;
                    })
                    ->badge()
                    ->color(function (StudentProfile $record): string {
                        $review = self::latestReview($record);

                        return match ($review?->decision) {
                            DisposalReview::DecisionClearedForDisposal => 'success',
                            DisposalReview::DecisionBlockedByHold => 'danger',
                            DisposalReview::DecisionRetained => 'warning',
                            default => 'gray',
                        };
                    }),
            ])
            ->filters([
                SelectFilter::make('retention_category')
                    ->options([RetentionCategory::ShortOperational->value => RetentionCategory::ShortOperational->label()])
                    ->query(function ($query, array $data) {
                        // V1 candidate scope is Short-Operational only (see class docblock),
                        // so selecting the only option is a no-op filter that still proves
                        // the control is wired; a future category expansion adds a real branch here.
                        return $query->when(
                            ($data['value'] ?? null) === RetentionCategory::ShortOperational->value,
                            fn ($query) => $query->whereNotNull('archived_at'),
                        );
                    }),
                SelectFilter::make('decision')
                    ->label('Review Decision')
                    ->options(DisposalReview::decisionOptions())
                    ->query(function ($query, array $data) {
                        $value = $data['value'] ?? null;

                        if (blank($value)) {
                            return $query;
                        }

                        return $query->whereIn('id', DisposalReview::query()
                            ->where('decision', $value)
                            ->pluck('student_profile_id'));
                    }),
            ])
            ->recordActions([
                Action::make('reviewDisposal')
                    ->label('Review Disposal')
                    ->icon('heroicon-o-archive-box-x-mark')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->schema([
                        Checkbox::make('attestation')
                            ->label('I confirm this record is not under institutional, legal, audit, or active-workflow hold')
                            ->accepted()
                            ->required(),
                        Textarea::make('reason')
                            ->label('Reason')
                            ->required()
                            ->maxLength(1000),
                    ])
                    ->modalHeading('Review Disposal Candidate')
                    ->modalSubmitActionLabel('Submit Review')
                    ->authorize('create')
                    ->action(fn (StudentProfile $record, array $data) => self::reviewDisposal($record, $data))
                    ->visible(fn (StudentProfile $record): bool => self::latestReview($record)?->decision !== DisposalReview::DecisionClearedForDisposal),
            ])
            ->toolbarActions([])
            ->defaultSort('archived_at', 'desc');
    }

    private static function hasActiveBlockingHold(StudentProfile $record): bool
    {
        return app(HoldEvaluationService::class)->hasActiveBlockingHold($record, [
            Hold::BlockingEnrollment,
            Hold::BlockingCorPrint,
            Hold::BlockingClearance,
            Hold::BlockingRecordRelease,
            Hold::BlockingGraduationEligibility,
            Hold::BlockingReactivation,
            Hold::BlockingAdvisoryOnly,
        ]);
    }

    private static function latestReview(StudentProfile $record): ?DisposalReview
    {
        return DisposalReview::query()
            ->where('student_profile_id', $record->id)
            ->latest('reviewed_at')
            ->first();
    }

    private static function reviewDisposal(StudentProfile $record, array $data): void
    {
        /** @var User $actor */
        $actor = Auth::user();

        $hasActiveHold = self::hasActiveBlockingHold($record);
        $attested = (bool) ($data['attestation'] ?? false);
        $reason = (string) ($data['reason'] ?? '');

        $decision = $hasActiveHold
            ? DisposalReview::DecisionBlockedByHold
            : ($attested ? DisposalReview::DecisionClearedForDisposal : DisposalReview::DecisionRetained);

        $review = DisposalReview::query()->create([
            'student_profile_id' => $record->id,
            'retention_category' => RetentionCategory::ShortOperational->value,
            'hold_check_result' => $hasActiveHold,
            'legal_audit_attestation' => $attested,
            'decision' => $decision,
            'reason' => $reason,
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
        ]);

        activity()
            ->performedOn($review)
            ->causedBy($actor)
            ->event('disposal_reviewed')
            ->withProperties([
                'student_profile_id' => $record->id,
                'decision' => $decision,
                'hold_check_result' => $hasActiveHold,
                'legal_audit_attestation' => $attested,
                'reason' => $reason,
            ])
            ->log('Disposal review recorded');

        if ($hasActiveHold) {
            Notification::make()
                ->title('Disposal blocked')
                ->body('This record is under an active blocking hold and cannot be cleared for disposal. The attempt was logged.')
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title('Disposal review recorded')
            ->body('Decision: '.(DisposalReview::decisionOptions()[$decision] ?? $decision))
            ->success()
            ->send();
    }
}
