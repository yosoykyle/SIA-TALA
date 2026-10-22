<?php

namespace App\Filament\Resources\Enrollments\Tables;

use App\Actions\Finance\IndividualAssessmentSources;
use App\Actions\Finance\TermAccountProjection;
use App\Filament\Resources\Enrollments\EnrollmentResource;
use App\Models\Assessment;
use App\Models\Enrollment;
use App\Models\Program;
use Filament\Actions\Action;
use Filament\Tables\Columns\Layout\View as RecordView;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StudentAccountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->extraAttributes(['class' => 'accounting-records-table'])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'credentialUser', 'admissionApplication.program', 'studentProfile.program', 'term',
                'currentProposalVersion.items.reservation', 'termAccount.assessments',
            ])->where(fn (Builder $scope): Builder => $scope->whereHas('termAccount')->orWhere('canonical_outcome', Enrollment::OutcomeInProgress)))
            ->defaultSort('updated_at', 'desc')
            ->columns([RecordView::make('filament.admin.enrollments.accounting-record-row')])
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
                    ->query(fn (Builder $query, array $data): Builder => $query->when($data['value'] ?? null,
                        fn (Builder $query, string $programId): Builder => $query->where(fn (Builder $scope): Builder => $scope
                            ->whereHas('admissionApplication', fn (Builder $application): Builder => $application->where('program_id', $programId))
                            ->orWhereHas('studentProfile', fn (Builder $profile): Builder => $profile->where('program_id', $programId))),
                    )),
            ])
            ->recordUrl(null)->recordAction(null)->recordActions([])->toolbarActions([])
            ->emptyStateHeading(fn ($livewire): string => filled($livewire->getTableSearch()) ? 'No matching accounts' : match ($livewire->activeTab) {
                'assessment_required' => 'No assessments waiting for review',
                'payment_proof' => 'No payment proof waiting for review',
                'payment_exceptions' => 'No online payment issues to review',
                default => 'No account records found',
            })
            ->emptyStateDescription(fn ($livewire): string => filled($livewire->getTableSearch())
                ? 'Try another name or number, or clear the search and filters.'
                : match ($livewire->activeTab) {
                    'assessment_required' => 'Unassessed subjects, recorded changes and retained payments appear here when they need review.',
                    'payment_proof' => 'Submitted proof appears here. Existing payments remain in account records.',
                    'payment_exceptions' => 'Failed or unmatched online payment records appear here. Payment history remains in the account.',
                    default => 'Accounts appear when enrollment begins. Choose another task from Student accounts.',
                })
            ->emptyStateActions([Action::make('openAccountRecords')->label('Open account records')->color('gray')
                ->url(EnrollmentResource::getUrl('index', ['tab' => 'accounts']))]);
    }

    /** @return array{status:string,detail:?string,color:string,action:string,program:string} */
    public static function rowData(Enrollment $case, ?string $context): array
    {
        $assessment = $case->termAccount?->assessments->where('state', Assessment::StateActive)->sortByDesc('version')->first();
        $position = $case->termAccount !== null ? app(TermAccountProjection::class)->forAccount($case->termAccount) : null;
        $sources = app(IndividualAssessmentSources::class)->forEnrollment($case);
        $status = match (true) {
            $sources['course_drop'] !== null => 'Course Drop needs assessment',
            $position['reconciliation_required'] ?? false => 'Retained payments need review',
            $sources['adjustment'] && (int) $assessment?->source_proposal_version_id !== (int) $case->current_proposal_version_id => 'Changed subjects need assessment',
            $assessment === null => 'Assessment needed',
            $position === null || $position['state'] === 'Unavailable' => 'Assessment needs review',
            (float) $position['current_due'] > 0 => 'PHP '.number_format((float) $position['current_due'], 2).' due now',
            default => 'No payment due now',
        };
        $detail = $position !== null && $position['state'] !== 'Unavailable' && (float) $position['remaining_balance'] > (float) $position['current_due']
            ? 'Later term fees: PHP '.number_format((float) $position['remaining_balance'] - (float) $position['current_due'], 2) : null;
        if ($context === 'payment_proof') {
            $status = 'Payment proof awaiting review';
        } elseif ($context === 'payment_exceptions') {
            $status = 'Online payment needs review';
        }

        return [
            'status' => $status, 'detail' => $detail, 'color' => $status === 'No payment due now' ? 'success' : 'warning',
            'action' => match ($context) {
                'assessment_required' => 'Review assessment', 'payment_proof' => 'Review proof',
                'payment_exceptions' => 'Review online payment', default => 'Open account',
            },
            'program' => $case->studentProfile?->program?->name ?? $case->admissionApplication?->program?->name ?? 'Program unavailable',
        ];
    }
}
