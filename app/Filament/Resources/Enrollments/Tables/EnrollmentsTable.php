<?php

namespace App\Filament\Resources\Enrollments\Tables;

use App\Actions\Enrollment\EnrollmentGateReviewSummary;
use App\Actions\Enrollment\EnrollmentPlacementService;
use App\Models\Enrollment;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Throwable;

class EnrollmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['studentProfile.program', 'term', 'gateResults']))
            ->columns([
                TextColumn::make('studentProfile.student_number')
                    ->label('Student No.')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('studentProfile.last_name')
                    ->label('Student')
                    ->state(fn (Enrollment $record): string => collect([
                        $record->studentProfile?->last_name,
                        $record->studentProfile?->first_name,
                    ])->filter()->implode(', '))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('studentProfile.program.code')
                    ->label('Program')
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('term.label')
                    ->label('Term')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->colors([
                        'gray' => 'not_started',
                        'warning' => ['pending_review', 'pending_payment'],
                        'info' => 'capacity_pending',
                        'success' => 'ready_for_official_enrollment',
                        'primary' => 'officially_enrolled',
                        'danger' => ['cancelled', 'dropped', 'withdrawn'],
                    ])
                    ->searchable(),
                TextColumn::make('student_type')
                    ->label('Type')
                    ->badge()
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('gate_review')
                    ->label('Gate Review')
                    ->state(fn (Enrollment $record): string => app(EnrollmentGateReviewSummary::class)->compactStatus($record))
                    ->description(fn (Enrollment $record): string => app(EnrollmentGateReviewSummary::class)->compactResponsibleOffice($record))
                    ->badge()
                    ->color(fn (Enrollment $record): string => app(EnrollmentGateReviewSummary::class)->compactStatusColor($record))
                    ->wrap(),
                TextColumn::make('registered_at')
                    ->dateTime()
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'not_started' => 'Not Started',
                        'pending_review' => 'Pending Review',
                        'capacity_pending' => 'Capacity Pending',
                        'pending_payment' => 'Payment Pending',
                        'ready_for_official_enrollment' => 'Ready for Official Enrollment',
                        'officially_enrolled' => 'Officially Enrolled',
                        'cancelled' => 'Cancelled',
                        'dropped' => 'Dropped',
                        'withdrawn' => 'Withdrawn',
                    ]),
                SelectFilter::make('student_type')
                    ->options([
                        'new' => 'New/Freshmen',
                        'transferee' => 'Transferee',
                        'regular' => 'Regular',
                        'irregular' => 'Irregular',
                        'returnee' => 'Returnee',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
                self::confirmPlacementAction(),
                self::cancelPlacementAction(),
            ])
            ->toolbarActions([]);
    }

    public static function confirmPlacementAction(): Action
    {
        return Action::make('confirmPlacement')
            ->label('Confirm / Replace Placement')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->schema([
                Placeholder::make('proposal_summary')
                    ->label('Student proposal')
                    ->content(fn (?Enrollment $record): string => $record instanceof Enrollment
                        ? $record->courseEnrollments()
                            ->whereNotNull('proposed_section_id')
                            ->count().' proposed subject section(s) will be confirmed together.'
                        : 'No proposal available.')
                    ->visible(fn (?Enrollment $record): bool => $record instanceof Enrollment
                        && $record->student_type === 'irregular'
                        && $record->courseEnrollments()->whereNotNull('proposed_section_id')->exists()),
                Select::make('cohort_code')
                    ->label('Published logical cohort')
                    ->options(fn (?Enrollment $record): array => $record instanceof Enrollment
                        ? app(EnrollmentPlacementService::class)->regularCohortOptions($record)
                        : [])
                    ->required()
                    ->searchable()
                    ->native(false)
                    ->helperText('Every eligible published subject in this cohort is confirmed atomically.')
                    ->visible(fn (?Enrollment $record): bool => $record instanceof Enrollment
                        && $record->student_type !== 'irregular'),
                Select::make('section_id')
                    ->label('Replacement published section')
                    ->options(fn (?Enrollment $record): array => $record instanceof Enrollment
                        ? app(EnrollmentPlacementService::class)->replacementOptions($record)
                        : [])
                    ->required()
                    ->searchable()
                    ->native(false)
                    ->helperText('Use this only to replace one already-confirmed irregular subject placement.')
                    ->visible(fn (?Enrollment $record): bool => $record instanceof Enrollment
                        && $record->student_type === 'irregular'
                        && $record->courseEnrollments()->whereNotNull('proposed_section_id')->doesntExist()),
            ])
            ->modalHeading('Confirm enrollment placement')
            ->modalSubmitActionLabel('Confirm Placement')
            ->visible(function (Enrollment $record): bool {
                $actorMayConfirm = auth()->user()?->can('confirmPlacement', $record) ?? false;
                $service = app(EnrollmentPlacementService::class);

                if (! $actorMayConfirm || ! $service->placementIsMutable($record)) {
                    return false;
                }

                if ($record->student_type !== 'irregular') {
                    return $service->regularCohortOptions($record) !== [];
                }

                return $record->courseEnrollments()->whereNotNull('proposed_section_id')->exists()
                    || $service->replacementOptions($record) !== [];
            })
            ->action(function (Enrollment $record, array $data): void {
                $actor = auth()->user();

                if (! $actor instanceof User) {
                    return;
                }

                try {
                    $service = app(EnrollmentPlacementService::class);
                    $hasProposal = $record->courseEnrollments()->whereNotNull('proposed_section_id')->exists();

                    if ($record->student_type === 'irregular' && $hasProposal) {
                        $summary = $service->confirmComplete($record, $actor);
                    } elseif ($record->student_type === 'irregular') {
                        $summary = $service->replace($record, (int) $data['section_id'], $actor);
                    } else {
                        $summary = $service->confirmRegularCohort($record, (string) $data['cohort_code'], $actor);
                    }

                    Notification::make()
                        ->title($summary['already_confirmed'] ? 'Placement already confirmed' : 'Placement confirmed')
                        ->body('Seat reservation and published schedule bindings are recorded.')
                        ->success()
                        ->send();
                } catch (Throwable $exception) {
                    Notification::make()
                        ->title('Placement confirmation failed')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    public static function cancelPlacementAction(): Action
    {
        return Action::make('cancelPlacement')
            ->label('Cancel Enrollment')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->requiresConfirmation()
            ->schema([
                Textarea::make('reason')
                    ->label('Cancellation reason')
                    ->required()
                    ->maxLength(2000),
            ])
            ->visible(fn (Enrollment $record): bool => ! in_array(
                $record->status,
                ['officially_enrolled', 'cancelled', 'dropped', 'withdrawn'],
                true,
            ) && (auth()->user()?->can('confirmPlacement', $record) ?? false))
            ->action(function (Enrollment $record, array $data): void {
                $actor = auth()->user();

                if (! $actor instanceof User) {
                    return;
                }

                try {
                    app(EnrollmentPlacementService::class)->cancel(
                        $record,
                        $actor,
                        (string) $data['reason'],
                    );
                    Notification::make()
                        ->title('Enrollment cancelled')
                        ->body('Pending reservations and schedule bindings were released.')
                        ->success()
                        ->send();
                } catch (Throwable $exception) {
                    Notification::make()
                        ->title('Enrollment cancellation failed')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }
}
