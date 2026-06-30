<?php

namespace App\Filament\Resources\GraduationReviewBatches\RelationManagers;

use App\Actions\Graduation\GraduationEligibilitySnapshotService;
use App\Models\GraduationReviewMember;
use App\Models\GraduationSnapshot;
use App\Models\StudentProfile;
use Filament\Actions\Action;
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
                ->options(fn (): array => StudentProfile::query()
                    ->orderBy('student_number')
                    ->limit(100)
                    ->get()
                    ->mapWithKeys(fn (StudentProfile $profile): array => [$profile->id => $profile->student_number.' - '.$profile->last_name.', '.$profile->first_name])
                    ->all())
                ->searchable()
                ->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('studentProfile.student_number')->label('Student No.')->searchable(),
                TextColumn::make('studentProfile.last_name')->label('Last Name')->searchable(),
                TextColumn::make('studentProfile.program.code')->label('Program'),
                IconColumn::make('is_active')->boolean()->label('Active'),
                TextColumn::make('latestSnapshot.result_status')->label('Latest Result')->badge()->placeholder('No snapshot'),
                TextColumn::make('latestSnapshot.version')->label('Version')->placeholder('-'),
                TextColumn::make('latestSnapshot.generated_at')->label('Generated')->dateTime()->placeholder('-'),
                TextColumn::make('latestSnapshot.made_visible_at')->label('Visible Since')->dateTime()->placeholder('Staff only'),
            ])
            ->defaultSort('added_at', 'desc')
            ->headerActions([
                CreateAction::make()
                    ->mutateFormDataUsing(fn (array $data): array => [
                        ...$data,
                        'added_by' => auth()->id(),
                        'added_at' => now(),
                        'is_active' => true,
                    ]),
            ])
            ->recordActions([
                Action::make('refreshSnapshot')
                    ->label('Refresh Snapshot')
                    ->authorize('refreshSnapshot')
                    ->action(function (GraduationReviewMember $record): void {
                        app(GraduationEligibilitySnapshotService::class)->generate($record, auth()->user());
                        Notification::make()->title('Snapshot refreshed')->success()->send();
                    }),
                Action::make('makeVisible')
                    ->label('Expose to Student')
                    ->authorize(fn (GraduationReviewMember $record): bool => $this->canUpdateLatestSnapshotVisibility($record))
                    ->schema([
                        Textarea::make('visibility_reason')
                            ->required()
                            ->maxLength(2000),
                    ])
                    ->action(function (array $data, GraduationReviewMember $record): void {
                        $snapshot = $this->latestSnapshot($record);
                        Gate::authorize('updateVisibility', $snapshot);

                        $snapshot->update([
                            'made_visible_by' => auth()->id(),
                            'made_visible_at' => now(),
                            'visibility_reason' => $data['visibility_reason'],
                        ]);
                        Notification::make()->title('Snapshot visibility updated')->success()->send();
                    }),
                Action::make('hideVisible')
                    ->label('Hide from Student')
                    ->authorize(fn (GraduationReviewMember $record): bool => $this->canUpdateLatestSnapshotVisibility($record))
                    ->requiresConfirmation()
                    ->visible(fn (GraduationReviewMember $record): bool => $record->latestSnapshot?->made_visible_at !== null)
                    ->action(function (GraduationReviewMember $record): void {
                        $snapshot = $this->latestSnapshot($record);
                        Gate::authorize('updateVisibility', $snapshot);

                        $snapshot->update([
                            'made_visible_by' => auth()->id(),
                            'made_visible_at' => null,
                            'visibility_reason' => 'Hidden by Registrar.',
                        ]);
                        Notification::make()->title('Snapshot hidden from Student Hub')->success()->send();
                    }),
                DeleteAction::make()
                    ->action(fn (GraduationReviewMember $record): bool => $record->update(['is_active' => false])),
            ])
            ->toolbarActions([
                BulkAction::make('refreshSelectedSnapshots')
                    ->label('Refresh Selected Snapshots')
                    ->authorize(fn (): bool => auth()->user()?->can('refreshAnySnapshot', GraduationReviewMember::class) ?? false)
                    ->authorizeIndividualRecords('refreshSnapshot')
                    ->action(function (Collection $records): void {
                        $records
                            ->filter(fn (Model $record): bool => $record instanceof GraduationReviewMember && $record->is_active)
                            ->each(function (GraduationReviewMember $record): void {
                                Gate::authorize('refreshSnapshot', $record);

                                app(GraduationEligibilitySnapshotService::class)->generate($record, auth()->user());
                            });
                        Notification::make()->title('Selected snapshots refreshed')->success()->send();
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
}
