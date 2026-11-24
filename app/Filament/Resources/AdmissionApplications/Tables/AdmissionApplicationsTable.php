<?php

namespace App\Filament\Resources\AdmissionApplications\Tables;

use App\Actions\Admissions\AdmissionReviewGuard;
use App\Filament\Resources\AdmissionApplications\Pages\ListAdmissionApplications;
use App\Filament\Resources\AdmissionApplications\Pages\ViewAdmissionApplication;
use App\Models\AdmissionApplication;
use App\Models\AdmissionCycle;
use App\Models\AdmissionRequirement;
use App\Models\ApplicationCorrectionRequest;
use App\Models\PreliminaryEvidenceReview;
use App\Models\Program;
use App\Queries\Admissions\AdmissionQueueCounts;
use App\Queries\Admissions\ReadyApplicantProjectionQuery;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class AdmissionApplicationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->extraAttributes(['class' => 'tala-admissions-table'])
            ->searchPlaceholder('Search name, email, reference or exact LRN')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->select($query->getModel()->qualifyColumn('*'))
                ->selectRaw(self::activityExpression($query).' AS last_activity_at'))
            ->columns([
                TextColumn::make('applicant')
                    ->label('Applicant')
                    ->state(fn (AdmissionApplication $record): string => collect([
                        $record->first_name,
                        $record->middle_name,
                        $record->last_name,
                        $record->extension_name,
                    ])->filter()->implode(' '))
                    ->description(fn (AdmissionApplication $record): string => str($record->email)->limit(38)->toString())
                    ->tooltip(fn (AdmissionApplication $record): string => $record->email.' · '.$record->application_reference)
                    ->weight('semibold')
                    ->wrap()
                    ->extraCellAttributes(['style' => 'word-break: break-word;'])
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(function (Builder $query) use ($search): void {
                        $query->where('application_reference', 'like', '%'.$search.'%')
                            ->orWhere('first_name', 'like', '%'.$search.'%')
                            ->orWhere('middle_name', 'like', '%'.$search.'%')
                            ->orWhere('last_name', 'like', '%'.$search.'%')
                            ->orWhere('email', 'like', '%'.$search.'%')
                            ->orWhere('lrn', $search);
                    })),
                TextColumn::make('application_reference')
                    ->label('Reference')
                    ->limit(20)
                    ->tooltip(fn (AdmissionApplication $record): string => $record->application_reference)
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('scope')
                    ->label('Program / cycle')
                    ->state(function (AdmissionApplication $record): string {
                        $program = $record->getRelation('program');

                        return $program instanceof Program ? ($program->code ?: $program->name) : 'Program unavailable';
                    })
                    ->description(function (AdmissionApplication $record): string {
                        $cycle = $record->getRelation('admissionCycle');

                        return AdmissionCycle::studentTypeLabel($record->application_path).' · '.($cycle instanceof AdmissionCycle ? $cycle->code : 'Cycle unavailable');
                    })
                    ->tooltip(fn (AdmissionApplication $record): ?string => $record->program?->name)
                    ->wrap()
                    ->visibleFrom('md'),
                TextColumn::make('application_state')
                    ->label('State')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => str($state)->headline()->toString())
                    ->color(fn (string $state): string => match ($state) {
                        AdmissionApplication::StateAdmitted => 'success',
                        AdmissionApplication::StateNotAdmitted => 'danger',
                        AdmissionApplication::StateWithdrawn => 'gray',
                        AdmissionApplication::StateActionNeeded => 'warning',
                        default => 'info',
                    })
                    ->visibleFrom('md'),
                TextColumn::make('owner_next_action')
                    ->label('Owner / next action')
                    ->state(fn (AdmissionApplication $record): string => match ($record->application_state) {
                        AdmissionApplication::StateDraft => 'Applicant — complete the draft',
                        AdmissionApplication::StateActionNeeded => 'Applicant — respond to correction',
                        AdmissionApplication::StateSubmitted => 'Registrar — '.app(ReadyApplicantProjectionQuery::class)->submittedNextAction($record),
                        AdmissionApplication::StateAdmitted => app(ReadyApplicantProjectionQuery::class)->forApplication($record)['ready']
                            ? app(ReadyApplicantProjectionQuery::class)->responsibleParty($record).' — '.app(ReadyApplicantProjectionQuery::class)->enrollmentNextAction($record)
                            : 'Applicant / Registrar — complete external checks and clearance',
                        AdmissionApplication::StateNotAdmitted,
                        AdmissionApplication::StateWithdrawn => 'No active task — Registrar retains history',
                        default => 'Registrar — review application',
                    })
                    ->description(function (AdmissionApplication $record): ?string {
                        if ($record->application_state === AdmissionApplication::StateActionNeeded) {
                            $request = $record->correctionRequests
                                ->where('state', ApplicationCorrectionRequest::StateActive)
                                ->sortByDesc('sequence')
                                ->first();
                            if ($request instanceof ApplicationCorrectionRequest) {
                                $due = $request->due_at->timezone(config('app.display_timezone'))->format('M j, Y g:i A');

                                return $request->isOverdue() ? "Overdue — {$due}" : "Due {$due}";
                            }
                        }

                        if ($record->last_activity_at) {
                            return 'Updated '.Carbon::parse($record->last_activity_at)->timezone(config('app.display_timezone'))->format('M j, Y · g:i A');
                        }

                        return null;
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'Registrar — Start review of the current submitted application.' => 'Registrar — start review',
                        'Registrar — Review the current required preliminary copies.' => 'Registrar — review preliminary copies',
                        'Registrar — Resolve the current private identity warning.' => 'Registrar — resolve identity warning',
                        'Registrar — Record the admission decision using the current reviewed evidence.' => 'Registrar — record admission decision',
                        'Applicant / Registrar — complete external checks and clearance' => 'Applicant / Registrar — complete checks and clearance',
                        default => $state,
                    })
                    ->wrap()
                    ->wrapHeader()
                    ->extraCellAttributes(['style' => 'word-break: break-word;'])
                    ->visible(fn (mixed $livewire): bool => ! self::isReadyForEnrollmentQueue($livewire)),
                TextColumn::make('enrollment_status')
                    ->label('Enrollment')
                    ->state(fn (AdmissionApplication $record): string => self::enrollmentStatus($record)['label'])
                    ->badge()
                    ->color(fn (AdmissionApplication $record): string => self::enrollmentStatus($record)['color'])
                    ->tooltip(fn (AdmissionApplication $record): string => app(ReadyApplicantProjectionQuery::class)->enrollmentNextAction($record))
                    ->description(fn (AdmissionApplication $record): ?string => $record->last_activity_at
                        ? 'Updated '.Carbon::parse($record->last_activity_at)->timezone(config('app.display_timezone'))->format('M j, Y · g:i A')
                        : null)
                    ->visible(fn (mixed $livewire): bool => self::isReadyForEnrollmentQueue($livewire)),
                TextColumn::make('preliminary_readiness')
                    ->label('Preliminary review')
                    ->state(fn (AdmissionApplication $record): string => self::preliminaryReadiness($record))
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'Current preliminary review complete' => 'Preliminary copies reviewed',
                        'Current preliminary review pending' => 'Preliminary review pending',
                        default => $state,
                    })
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Current preliminary review complete', 'Preliminary copies reviewed' => 'success',
                        'Current preliminary review pending', 'Preliminary review pending' => 'warning',
                        default => 'gray',
                    })
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('correction_status')
                    ->label('Correction due')
                    ->state(function (AdmissionApplication $record): ?string {
                        $request = $record->correctionRequests
                            ->where('state', ApplicationCorrectionRequest::StateActive)
                            ->sortByDesc('sequence')
                            ->first();

                        if (! $request instanceof ApplicationCorrectionRequest) {
                            return null;
                        }

                        $due = $request->due_at->timezone(config('app.display_timezone'))->format('M j, Y g:i A');

                        return $request->isOverdue() ? "Overdue — {$due}" : "Due {$due}";
                    })
                    ->placeholder('—')
                    ->badge()
                    ->color(fn (?string $state): ?string => $state && str_starts_with($state, 'Overdue') ? 'danger' : 'gray')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('last_activity_at')
                    ->label('Last activity')
                    ->dateTime('M j, Y · g:i A')->timezone(config('app.display_timezone'))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('readiness')
                    ->label('Official readiness')
                    ->state(fn (AdmissionApplication $record): string => $record->application_state === AdmissionApplication::StateAdmitted
                        ? (app(ReadyApplicantProjectionQuery::class)->forApplication($record)['ready'] ? 'Ready for enrollment' : 'Awaiting Registrar clearance')
                        : 'Admission decision pending or historical')
                    ->badge()
                    ->color(fn (AdmissionApplication $record): string => $record->application_state === AdmissionApplication::StateAdmitted
                        && app(ReadyApplicantProjectionQuery::class)->forApplication($record)['ready'] ? 'success' : 'gray')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('admissionCycle.closes_at')
                    ->label('Public closing')
                    ->dateTime('M j, Y · g:i A')->timezone(config('app.display_timezone'))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filtersLayout(FiltersLayout::Dropdown)
            ->filtersFormColumns(2)
            ->deferFilters(false)
            ->filtersTriggerAction(fn (Action $action): Action => $action->button()->label('Filter applications')->tooltip('Narrow the selected application queue'))
            ->toggleColumnsTriggerAction(fn (Action $action): Action => $action->button()->label('Columns')->tooltip('Choose supporting application facts'))
            ->filters([
                SelectFilter::make('admission_cycle_id')
                    ->label('Admission cycle')
                    ->relationship('admissionCycle', 'label')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('program_id')
                    ->label('Program')
                    ->relationship('program', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('application_path')
                    ->label('Student type')
                    ->options(AdmissionCycle::studentTypeOptions()),
                SelectFilter::make('application_state')
                    ->label('State')
                    ->options([
                        AdmissionApplication::StateSubmitted => 'Submitted',
                        AdmissionApplication::StateActionNeeded => 'Action needed',
                        AdmissionApplication::StateAdmitted => 'Admitted',
                        AdmissionApplication::StateNotAdmitted => 'Not admitted',
                        AdmissionApplication::StateWithdrawn => 'Withdrawn',
                    ]),
                SelectFilter::make('readiness')
                    ->options(['ready' => 'Ready for enrollment', 'awaiting' => 'Awaiting Registrar clearance'])
                    ->query(function (Builder $query, array $data): Builder {
                        if (blank($data['value'] ?? null)) {
                            return $query;
                        }

                        $readyIds = app(ReadyApplicantProjectionQuery::class)->readyApplicationIds();

                        return $data['value'] === 'ready'
                            ? $query->whereIn('id', $readyIds)
                            : $query->where('application_state', AdmissionApplication::StateAdmitted)->whereNotIn('id', $readyIds);
                    }),
                SelectFilter::make('preliminary_readiness')
                    ->options(['complete' => 'Current preliminary review complete', 'pending' => 'Current preliminary review pending'])
                    ->query(function (Builder $query, array $data): Builder {
                        if (blank($data['value'] ?? null)) {
                            return $query;
                        }

                        $completeIds = (clone $query)->get()->filter(fn (AdmissionApplication $record): bool => self::preliminaryReadiness($record) === 'Current preliminary review complete')->modelKeys();

                        return $data['value'] === 'complete' ? $query->whereIn('id', $completeIds) : $query->whereNotIn('id', $completeIds);
                    }),
                Filter::make('overdue_correction')
                    ->label('Overdue correction')
                    ->query(fn (Builder $query): Builder => $query->whereHas(
                        'correctionRequests',
                        fn (Builder $corrections): Builder => $corrections
                            ->where('state', ApplicationCorrectionRequest::StateActive)
                            ->where('due_at', '<', now(config('app.timezone'))),
                    )),
                Filter::make('submitted_at')
                    ->label('Submission date and time')
                    ->schema([
                        DateTimePicker::make('from')->label('Submitted from'),
                        DateTimePicker::make('until')->label('Submitted until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->where('submitted_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->where('submitted_at', '<=', $date)))
                    ->indicateUsing(fn (array $data): array => array_filter([
                        filled($data['from'] ?? null) ? 'Submitted from '.$data['from'] : null,
                        filled($data['until'] ?? null) ? 'Submitted until '.$data['until'] : null,
                    ])),
                Filter::make('activity_at')
                    ->label('Activity date and time')
                    ->schema([
                        DateTimePicker::make('from')->label('Activity from'),
                        DateTimePicker::make('until')->label('Activity until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereRaw(self::activityExpression($query).' >= ?', [$date]))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereRaw(self::activityExpression($query).' <= ?', [$date])))
                    ->indicateUsing(fn (array $data): array => array_filter([
                        filled($data['from'] ?? null) ? 'Activity from '.$data['from'] : null,
                        filled($data['until'] ?? null) ? 'Activity until '.$data['until'] : null,
                    ])),
            ])
            ->recordActions([
                Action::make('startReview')->label('Start review')->button()->color('primary')->icon('heroicon-o-play')
                    ->visible(fn (AdmissionApplication $record): bool => self::awaitsReviewStart($record))
                    ->requiresConfirmation()
                    ->modalHeading('Start review?')
                    ->modalDescription('Start review of this submitted version. Ordinary Applicant edits will lock; later changes need a named correction. Opening this record or a private copy alone does not start review.')
                    ->modalSubmitActionLabel('Start review')
                    ->schema([Hidden::make('expected_submission_version_id')->default(fn (AdmissionApplication $record): ?int => $record->current_submission_version_id)])
                    ->action(function (AdmissionApplication $record, array $data, ListAdmissionApplications $livewire): void {
                        try {
                            app(AdmissionReviewGuard::class)->start($record, auth()->user(), (int) ($data['expected_submission_version_id'] ?? 0));
                        } catch (ValidationException|AuthorizationException $exception) {
                            Notification::make()->danger()->title('Review was not started')
                                ->body($exception instanceof ValidationException ? collect($exception->errors())->flatten()->first() : $exception->getMessage())
                                ->send();

                            return;
                        }

                        Notification::make()->success()->title('Review started')->send();
                        $livewire->redirect(ViewAdmissionApplication::getUrl(['record' => $record, 'queue' => $livewire->activeTab]));
                    }),
                ViewAction::make()->label('Open')->button()->color('gray')->icon('heroicon-o-arrow-top-right-on-square')
                    ->hidden(fn (AdmissionApplication $record): bool => self::awaitsReviewStart($record))
                    ->url(fn (AdmissionApplication $record, ListAdmissionApplications $livewire): string => ViewAdmissionApplication::getUrl(['record' => $record, 'queue' => $livewire->activeTab])),
            ])
            ->recordUrl(fn (AdmissionApplication $record, ListAdmissionApplications $livewire): string => ViewAdmissionApplication::getUrl(['record' => $record, 'queue' => $livewire->activeTab]))
            ->persistSearchInSession()
            ->persistFiltersInSession()
            ->persistSortInSession()
            ->defaultSort(function (Builder $query): Builder {
                $id = $query->getModel()->qualifyColumn('id');
                $deadline = '(SELECT MIN(due_at) FROM application_correction_requests WHERE admission_application_id = '.$id.' AND state = ?)';

                return $query->orderByRaw($deadline.' IS NULL', [ApplicationCorrectionRequest::StateActive])
                    ->orderByRaw($deadline.' ASC', [ApplicationCorrectionRequest::StateActive])
                    ->orderByRaw(self::activityExpression($query).' ASC')->orderBy('id');
            })
            ->emptyStateHeading('No applications in this queue')
            ->emptyStateDescription('Choose another queue, clear search and filters, or wait for an Applicant submission.')
            ->searchPlaceholder('Find an application')
            ->poll('30s');
    }

    /**
     * A submitted application the Registrar may review whose review has not started yet, matching the record page's Start review action.
     */
    private static function awaitsReviewStart(AdmissionApplication $application): bool
    {
        return (bool) auth()->user()?->can('review', $application) && $application->allowsSubmittedEdit();
    }

    private static function activityExpression(Builder $query): string
    {
        $id = $query->getModel()->qualifyColumn('id');
        $updatedAt = $query->getModel()->qualifyColumn('updated_at');

        $sources = [
            '(SELECT MAX(occurred_at) FROM admission_application_events WHERE admission_application_id = '.$id.')',
            '(SELECT MAX(uploaded_at) FROM document_evidence WHERE admission_application_id = '.$id.')',
            '(SELECT MAX(preliminary_evidence_reviews.reviewed_at) FROM preliminary_evidence_reviews JOIN document_evidence ON document_evidence.id = preliminary_evidence_reviews.document_evidence_id WHERE document_evidence.admission_application_id = '.$id.')',
            '(SELECT MAX(resolved_at) FROM identity_match_reviews WHERE admission_application_id = '.$id.')',
        ];

        return 'GREATEST('.$updatedAt.', '.implode(', ', array_map(fn (string $source): string => 'COALESCE('.$source.', '.$updatedAt.')', $sources)).')';
    }

    private static function preliminaryReadiness(AdmissionApplication $application): string
    {
        if ($application->application_state === AdmissionApplication::StateDraft) {
            return 'Draft — not submitted';
        }

        $set = $application->currentSubmissionVersion?->requirementSet;
        if ($set === null) {
            return 'Submitted requirement version unavailable';
        }

        foreach ($set->requirements->where('due_stage', AdmissionRequirement::DuePreliminaryReview) as $requirement) {
            $evidence = $application->evidenceVersions->where('admission_requirement_id', $requirement->id)->sortByDesc('id')->first();
            $review = $evidence?->preliminaryReviews->first(fn (PreliminaryEvidenceReview $review): bool => $review->successor === null);
            if ($review?->result !== PreliminaryEvidenceReview::ResultAccepted) {
                return 'Current preliminary review pending';
            }
        }

        return 'Current preliminary review complete';
    }

    private static function isReadyForEnrollmentQueue(mixed $livewire): bool
    {
        return $livewire instanceof ListAdmissionApplications && $livewire->activeTab === AdmissionQueueCounts::ReadyForEnrollment;
    }

    /**
     * Ready for enrollment only holds cleared applicants, so the long next-action sentence repeats on every row.
     * This short status says where enrollment stands instead; the full sentence stays in the tooltip and on the case.
     *
     * @return array{label: string, color: string}
     */
    public static function enrollmentStatus(AdmissionApplication $record): array
    {
        $projection = app(ReadyApplicantProjectionQuery::class);

        if ($projection->registrationHasStarted($record)) {
            return ['label' => 'Registration Case started', 'color' => 'info'];
        }

        return match ($projection->enrollmentAvailability($record)['status']) {
            'open' => ['label' => 'Not started', 'color' => 'gray'],
            'upcoming', 'not_configured' => ['label' => 'Enrollment not yet open', 'color' => 'gray'],
            'closed' => ['label' => 'Enrollment closed', 'color' => 'warning'],
            'existing_case' => ['label' => 'Other Registration Case', 'color' => 'warning'],
            default => ['label' => 'Term unavailable', 'color' => 'danger'],
        };
    }
}
