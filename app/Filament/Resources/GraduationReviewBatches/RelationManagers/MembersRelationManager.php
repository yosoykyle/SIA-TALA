<?php

namespace App\Filament\Resources\GraduationReviewBatches\RelationManagers;

use App\Actions\Graduation\GraduationEligibilitySnapshotService;
use App\Models\GraduationReviewBatch;
use App\Models\GraduationReviewMember;
use App\Models\GraduationSnapshot;
use App\Models\StudentProfile;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'members';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('student_profile_id')
                ->label('Student')
                ->searchable()
                ->getSearchResultsUsing(fn (string $search): array => self::searchableStudentOptions($search))
                ->getOptionLabelUsing(function (mixed $value): ?string {
                    $profile = StudentProfile::query()->find($value);

                    return $profile instanceof StudentProfile ? self::studentOptionLabel($profile) : null;
                })
                ->helperText('Search by student number, first name, or last name.')
                ->required(),
        ]);
    }

    /** @return array<int, string> */
    public static function searchableStudentOptions(string $search): array
    {
        return StudentProfile::query()
            ->where(function ($query) use ($search): void {
                $query->where('student_number', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%");
            })
            ->orderBy('student_number')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (StudentProfile $profile): array => [$profile->id => self::studentOptionLabel($profile)])
            ->all();
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'studentProfile.program',
                'latestSnapshot',
            ]))
            ->columns([
                TextColumn::make('student_name')
                    ->label('Student')
                    ->state(fn (GraduationReviewMember $record): string => self::studentName($record))
                    ->description(fn (GraduationReviewMember $record): string => collect([
                        $record->studentProfile?->student_number,
                        $record->studentProfile?->program?->code,
                    ])->filter()->join(' · '))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas(
                        'studentProfile',
                        fn (Builder $profileQuery): Builder => $profileQuery
                            ->where('student_number', 'like', "%{$search}%")
                            ->orWhere('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%"),
                    ))
                    ->wrap(),
                IconColumn::make('is_active')->boolean()->label('Active'),
                TextColumn::make('latestSnapshot.result_status')
                    ->label('Review result')
                    ->formatStateUsing(fn (?string $state): string => self::resultLabel($state))
                    ->description(fn (GraduationReviewMember $record): ?string => self::resultDescription($record))
                    ->badge()
                    ->placeholder('Awaiting evaluation')
                    ->wrap(),
                TextColumn::make('latestSnapshot.generated_at')
                    ->label('Last evaluated')
                    ->dateTime()
                    ->placeholder('Not evaluated'),
                TextColumn::make('latestSnapshot.made_visible_at')
                    ->label('Shared with student')
                    ->dateTime()
                    ->placeholder('Not shared'),
            ])
            ->defaultSort('added_at', 'desc')
            ->headerActions([
                CreateAction::make()
                    ->label('Add student to review')
                    ->visible(fn (): bool => $this->reviewIsOpen())
                    ->mutateFormDataUsing(fn (array $data): array => [
                        ...$data,
                        'added_by' => auth()->id(),
                        'added_at' => now(),
                        'is_active' => true,
                    ])
                    ->after(function (GraduationReviewMember $record): void {
                        activity()
                            ->performedOn($record)
                            ->causedBy(auth()->user())
                            ->event('graduation_review_member_added')
                            ->withProperties([
                                'graduation_review_batch_id' => $record->graduation_review_batch_id,
                                'student_profile_id' => $record->student_profile_id,
                            ])
                            ->log('Graduation Review member added');
                    }),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('refreshSnapshot')
                        ->label('Refresh eligibility review')
                        ->authorize('refreshSnapshot')
                        ->requiresConfirmation()
                        ->modalHeading('Refresh this eligibility review?')
                        ->modalDescription('This creates a new immutable eligibility snapshot from the latest authoritative records. The existing review history is retained.')
                        ->modalSubmitActionLabel('Refresh review')
                        ->action(function (GraduationReviewMember $record): void {
                            app(GraduationEligibilitySnapshotService::class)->generate($record, auth()->user());
                            Notification::make()->title('Eligibility review refreshed')->success()->send();
                        }),
                    Action::make('makeVisible')
                        ->label('Share review with student')
                        ->authorize(fn (GraduationReviewMember $record): bool => $this->canUpdateLatestSnapshotVisibility($record))
                        ->modalHeading('Share this eligibility review with the student?')
                        ->modalDescription('The student will see the result, evidence summary, next step, and offices to contact. This does not confer a degree.')
                        ->modalSubmitActionLabel('Share review')
                        ->schema([
                            Textarea::make('visibility_reason')
                                ->label('Reason for sharing')
                                ->required()
                                ->maxLength(2000),
                        ])
                        ->action(function (array $data, GraduationReviewMember $record): void {
                            $snapshot = $this->latestSnapshot($record);
                            Gate::authorize('updateVisibility', $snapshot);
                            $visibleBefore = $snapshot->made_visible_at !== null;

                            $snapshot->update([
                                'made_visible_by' => auth()->id(),
                                'made_visible_at' => now(),
                                'visibility_reason' => $data['visibility_reason'],
                            ]);

                            activity()
                                ->performedOn($snapshot)
                                ->causedBy(auth()->user())
                                ->event('graduation_snapshot_visibility_changed')
                                ->withProperties([
                                    'graduation_review_member_id' => $snapshot->graduation_review_member_id,
                                    'visibility_reason' => $data['visibility_reason'],
                                    'visible_before' => $visibleBefore,
                                    'visible_after' => true,
                                ])
                                ->log('Graduation Eligibility Snapshot made visible to student');

                            Notification::make()->title('Eligibility review shared with student')->success()->send();
                        }),
                    Action::make('hideVisible')
                        ->label('Stop sharing with student')
                        ->authorize(fn (GraduationReviewMember $record): bool => $this->canUpdateLatestSnapshotVisibility($record))
                        ->requiresConfirmation()
                        ->modalHeading('Stop sharing this review?')
                        ->modalDescription('The student will no longer see this eligibility review. The staff audit record remains available.')
                        ->modalSubmitActionLabel('Stop sharing')
                        ->visible(fn (GraduationReviewMember $record): bool => $record->latestSnapshot?->made_visible_at !== null)
                        ->action(function (GraduationReviewMember $record): void {
                            $snapshot = $this->latestSnapshot($record);
                            Gate::authorize('updateVisibility', $snapshot);
                            $visibilityReason = 'Hidden by Registrar.';

                            $snapshot->update([
                                'made_visible_by' => auth()->id(),
                                'made_visible_at' => null,
                                'visibility_reason' => $visibilityReason,
                            ]);

                            activity()
                                ->performedOn($snapshot)
                                ->causedBy(auth()->user())
                                ->event('graduation_snapshot_visibility_changed')
                                ->withProperties([
                                    'graduation_review_member_id' => $snapshot->graduation_review_member_id,
                                    'visibility_reason' => $visibilityReason,
                                    'visible_before' => true,
                                    'visible_after' => false,
                                ])
                                ->log('Graduation Eligibility Snapshot hidden from student');

                            Notification::make()->title('Eligibility review is no longer shared')->success()->send();
                        }),
                    DeleteAction::make()
                        ->label('Remove from review list')
                        ->modalHeading('Remove this student from the review list?')
                        ->modalDescription('The student is marked inactive in this review. Existing snapshots remain in the audit history.')
                        ->modalSubmitActionLabel('Remove student')
                        ->action(function (GraduationReviewMember $record): bool {
                            $updated = $record->update(['is_active' => false]);

                            activity()
                                ->performedOn($record)
                                ->causedBy(auth()->user())
                                ->event('graduation_review_member_removed')
                                ->withProperties([
                                    'graduation_review_batch_id' => $record->graduation_review_batch_id,
                                    'student_profile_id' => $record->student_profile_id,
                                    'is_active_after' => false,
                                ])
                                ->log('Graduation Review member removed');

                            return $updated;
                        }),
                ])->tooltip('Completion review actions'),
            ])
            ->stackedOnMobile()
            ->toolbarActions([
                BulkAction::make('refreshSelectedSnapshots')
                    ->label('Refresh selected eligibility reviews')
                    ->authorize(fn (): bool => $this->reviewIsOpen()
                        && (auth()->user()?->can('refreshAnySnapshot', GraduationReviewMember::class) ?? false))
                    ->authorizeIndividualRecords('refreshSnapshot')
                    ->requiresConfirmation()
                    ->modalHeading('Refresh selected eligibility reviews?')
                    ->modalDescription('This creates a new immutable snapshot for every authorized active student selected. Existing review history is retained.')
                    ->modalSubmitActionLabel('Refresh selected reviews')
                    ->action(function (Collection $records): void {
                        $records
                            ->filter(fn (Model $record): bool => $record instanceof GraduationReviewMember && $record->is_active)
                            ->each(function (GraduationReviewMember $record): void {
                                Gate::authorize('refreshSnapshot', $record);

                                app(GraduationEligibilitySnapshotService::class)->generate($record, auth()->user());
                            });
                        Notification::make()->title('Selected eligibility reviews refreshed')->success()->send();
                    })
                    ->deselectRecordsAfterCompletion(),
            ]);
    }

    private function latestSnapshot(GraduationReviewMember $member): GraduationSnapshot
    {
        return $member->snapshots()->latest('version')->firstOrFail();
    }

    private function canUpdateLatestSnapshotVisibility(GraduationReviewMember $member): bool
    {
        $snapshot = $member->latestSnapshot;

        if (! $snapshot instanceof GraduationSnapshot) {
            return false;
        }

        return auth()->user()?->can('updateVisibility', $snapshot) ?? false;
    }

    private function reviewIsOpen(): bool
    {
        $batch = $this->getOwnerRecord();

        return $batch instanceof GraduationReviewBatch
            && $batch->state === GraduationReviewBatch::StateOpen;
    }

    private static function studentName(GraduationReviewMember $member): string
    {
        $profile = $member->studentProfile;

        if (! $profile instanceof StudentProfile) {
            return 'Student record unavailable';
        }

        return collect([$profile->first_name, $profile->middle_name, $profile->last_name])
            ->filter()
            ->join(' ');
    }

    private static function resultLabel(?string $resultStatus): string
    {
        return match ($resultStatus) {
            GraduationEligibilitySnapshotService::ResultComplete => 'Requirements complete',
            GraduationEligibilitySnapshotService::ResultReadyForRegistrarReview => 'Ready for Registrar review',
            GraduationEligibilitySnapshotService::ResultBlockedMissingRequirement => 'Missing requirement',
            GraduationEligibilitySnapshotService::ResultBlockedFailedRequirement => 'Failed requirement',
            GraduationEligibilitySnapshotService::ResultBlockedPendingGrade => 'Pending grade',
            GraduationEligibilitySnapshotService::ResultBlockedInc => 'Incomplete grade',
            GraduationEligibilitySnapshotService::ResultBlockedHoldOrClearance => 'Hold or clearance block',
            GraduationEligibilitySnapshotService::ResultBlockedCurrentEnrollmentNotFinalized => 'Current enrollment in progress',
            null, '' => 'Awaiting evaluation',
            default => str($resultStatus)->headline()->toString(),
        };
    }

    private static function resultDescription(GraduationReviewMember $member): ?string
    {
        $snapshot = $member->latestSnapshot;

        if (! $snapshot instanceof GraduationSnapshot) {
            return null;
        }

        $remainingUnits = (float) data_get($snapshot->evaluation_snapshot, 'student_projection.remaining_units', 0);
        $blocker = data_get($snapshot->evaluation_snapshot, 'blocker_groups.0.label');

        return collect([
            $remainingUnits > 0 ? number_format($remainingUnits, 1).' units remaining' : null,
            is_string($blocker) ? $blocker : null,
        ])->filter()->join(' · ') ?: null;
    }

    private static function studentOptionLabel(StudentProfile $profile): string
    {
        return $profile->student_number.' — '.$profile->last_name.', '.$profile->first_name;
    }
}
