<?php

namespace App\Filament\Resources\Enrollments\Tables;

use App\Actions\Finance\AccountingDisplay;
use App\Actions\Finance\IndividualAssessmentSources;
use App\Actions\Finance\TermAccountProjection;
use App\Actions\StudentLifecycle\LifecycleAccountingReview;
use App\Filament\Resources\Enrollments\EnrollmentResource;
use App\Filament\Resources\Enrollments\Pages\ListEnrollments;
use App\Models\Assessment;
use App\Models\Enrollment;
use App\Models\Program;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;

class StudentAccountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->deferFilters(false)
            ->extraAttributes(fn ($livewire): array => [
                'class' => 'tala-data-table accounting-records-table'
                    .($livewire instanceof ListEnrollments && $livewire->accountingTermGateActive() ? ' tala-table--gated' : ''),
            ])
            ->modifyQueryUsing(fn (Builder $query, $livewire): Builder => $query->with([
                'credentialUser', 'admissionApplication.program', 'studentProfile.program', 'term',
                'currentProposalVersion.items.reservation', 'currentProposalVersion.confirmation', 'currentCorVersion',
                'termAccount.enrollment', 'termAccount.assessments.obligations.paymentAllocations.payment',
                'termAccount.assessments.obligations.coverages',
            ])->where(fn (Builder $scope): Builder => $scope->whereHas('termAccount')->orWhere('canonical_outcome', Enrollment::OutcomeInProgress)
                ->orWhereIn('id', $livewire instanceof ListEnrollments
                    ? $livewire->accountingPendingLifecycleIds()
                    : app(LifecycleAccountingReview::class)->pendingEnrollmentIds()))
                ->when($livewire instanceof ListEnrollments && ($livewire->activeTab === 'overview' || $livewire->accountingTermGateActive()), fn (Builder $gated): Builder => $gated->whereRaw('1 = 0')))
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('credentialUser.name')->label('Student')
                    ->description(fn (Enrollment $record): string => AccountingDisplay::learnerIdentifier($record))
                    ->weight('semibold')->sortable()->wrap(),
                TextColumn::make('program')->label('Program / term')
                    ->state(fn (Enrollment $record): string => AccountingDisplay::learnerProgram($record))
                    ->description(fn (Enrollment $record): string => $record->term?->label ?? 'Term unavailable')->wrap(),
                ViewColumn::make('account_status')->label('Account status')->view('filament.admin.enrollments.accounting-record-row'),
            ])
            ->searchable([fn (Builder $query, string $search): Builder => $query->where(function (Builder $query) use ($search): void {
                $query->where('case_reference', 'like', "%{$search}%")
                    ->orWhereHas('credentialUser', fn (Builder $person): Builder => $person->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('studentProfile', fn (Builder $profile): Builder => $profile->where('student_number', 'like', "%{$search}%"))
                    ->orWhereHas('admissionApplication', fn (Builder $application): Builder => $application->where('application_reference', 'like', "%{$search}%"));
            })])
            ->searchPlaceholder('Name or student number')
            ->filters([
                SelectFilter::make('term')->relationship('term', 'label')->searchable()->preload(),
                SelectFilter::make('program')->options(fn (): array => Program::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->preload()
                    ->query(fn (Builder $query, array $data): Builder => $query->when($data['value'] ?? null,
                        fn (Builder $query, string $programId): Builder => $query->where(fn (Builder $scope): Builder => $scope
                            ->whereHas('admissionApplication', fn (Builder $application): Builder => $application->where('program_id', $programId))
                            ->orWhereHas('studentProfile', fn (Builder $profile): Builder => $profile->where('program_id', $programId))),
                    )),
            ])
            ->filtersTriggerAction(fn (Action $action): Action => $action->button()->label('Filters')->color('gray'))
            ->recordUrl(null)->recordAction(null)
            ->recordActions([])->toolbarActions([])
            ->emptyState(fn (Table $table, $livewire) => ! self::hasSearchOrFilters($livewire)
                    ? view('filament.components.accounting-table-empty-state', ['table' => $table])
                    : null)
            ->emptyStateHeading(fn ($livewire): string => self::hasSearchOrFilters($livewire) ? 'No matching accounts' : match ($livewire->activeTab) {
                'assessment_required' => 'No assessments waiting for review',
                'payment_proof' => 'No receipts waiting for review',
                'student_changes' => 'No student status changes waiting for review',
                default => 'No account records found',
            })
            ->emptyStateDescription(fn ($livewire): string => self::hasSearchOrFilters($livewire)
                ? 'Try another name or number, or clear the search and filters.'
                : match ($livewire->activeTab) {
                    'assessment_required' => 'Subjects without charges, changed subjects and earlier payments appear here when they need review.',
                    'payment_proof' => 'Submitted receipts appear here. Existing payments remain in account records.',
                    'student_changes' => 'Recorded student status changes appear here until Accounting records the review result.',
                    default => 'No accounts match this view yet. Try another term or open Overview to check pending work.',
                })
            ->emptyStateActions([
                Action::make('openAccountRecords')
                    ->label(fn ($livewire): string => $livewire->activeTab === 'accounts' ? 'Back to Overview' : 'All student accounts')
                    ->color('gray')
                    ->url(fn ($livewire): string => $livewire->activeTab === 'accounts'
                        ? EnrollmentResource::getUrl('index')
                        : EnrollmentResource::getUrl('index', ['tab' => 'accounts'])),
            ]);
    }

    private static function hasSearchOrFilters(ListEnrollments $livewire): bool
    {
        return filled($livewire->getTableSearch())
            || collect(Arr::dot($livewire->tableFilters ?? []))->contains(fn ($value): bool => filled($value));
    }

    /** @return array{status:string,detail:?string,color:string,action:string,program:string} */
    public static function rowData(Enrollment $case, ?string $context): array
    {
        $assessment = $case->termAccount?->assessments->where('state', Assessment::StateActive)->sortByDesc('version')->first();
        $position = $case->termAccount !== null ? app(TermAccountProjection::class)->forAccount($case->termAccount, useLoadedRelations: true) : null;
        $sources = app(IndividualAssessmentSources::class)->forEnrollment($case, useLoadedRelations: true);
        $status = match (true) {
            $sources['course_drop'] !== null => 'Course Drop needs assessment',
            $position['reconciliation_required'] ?? false => 'Earlier payments need matching',
            $sources['adjustment'] && (int) $assessment?->source_proposal_version_id !== (int) $case->current_proposal_version_id => 'Changed subjects need assessment',
            $assessment === null => 'Assessment needed',
            $position === null || $position['state'] === 'Unavailable' => 'Assessment needs review',
            (float) $position['current_due'] > 0 => AccountingDisplay::money($position['current_due']).' due now',
            default => 'No payment due now',
        };
        $detail = $position !== null && $position['state'] !== 'Unavailable' && (float) $position['remaining_balance'] > (float) $position['current_due']
            ? 'Later term fees: '.AccountingDisplay::money((float) $position['remaining_balance'] - (float) $position['current_due']) : null;
        if ($context === 'payment_proof') {
            $status = 'Receipt to review';
        } elseif ($context === 'student_changes') {
            $status = 'Accounting review pending';
            $detail = $case->termAccount === null ? 'Account unavailable · ask Accounting and Registrar to restore the source.' : null;
        }

        return [
            'status' => $status, 'detail' => $detail, 'color' => match (true) {
                $status === 'No payment due now' => 'success',
                str_starts_with($status, 'PHP ') => 'gray',
                $status === 'Assessment needs review' => 'danger',
                default => 'warning',
            },
            'action' => match ($context) {
                'assessment_required' => 'Review assessment',
                'payment_proof' => 'Review receipts',
                'student_changes' => 'Review changes',
                default => 'Open account',
            },
            'program' => AccountingDisplay::learnerProgram($case),
        ];
    }
}
