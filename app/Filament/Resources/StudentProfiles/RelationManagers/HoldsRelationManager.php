<?php

namespace App\Filament\Resources\StudentProfiles\RelationManagers;

use App\Actions\StudentLifecycle\ResolveHold;
use App\Models\Hold;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class HoldsRelationManager extends RelationManager
{
    protected static bool $isLazy = false;

    protected static string $relationship = 'holds';

    protected static ?string $title = 'Recorded restrictions';

    public function table(Table $table): Table
    {
        return $table->heading('Recorded restrictions')
            ->description('Existing restrictions remain in the record. New document, academic and student status concerns are handled in their owning workflows.')
            ->emptyStateHeading('No restrictions recorded')
            ->emptyStateIcon('heroicon-o-shield-check')
            ->emptyStateDescription('Review the document checklist above for outstanding requirements.')
            ->recordTitleAttribute('reason')->columns([
                TextColumn::make('hold_type')->label('Concern')->formatStateUsing(fn (string $state): string => self::holdTypeOptions()[$state] ?? str($state)->headline()->toString())
                    ->description(fn (Hold $record): string => $record->studentFacingOfficeLabel())->sortable(),
                TextColumn::make('blocking_level')->label('Effect')->formatStateUsing(fn (string $state): string => str($state)->headline()->toString())->wrap()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('reason')->label('Details')->limit(70)->wrap()->searchable(),
                TextColumn::make('effective_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('expires_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])->filters([
                SelectFilter::make('status')->options([
                    Hold::StatusActive => 'Active', Hold::StatusResolved => 'Resolved',
                    Hold::StatusWaived => 'Waived', Hold::StatusExpired => 'Expired',
                ]),
                SelectFilter::make('hold_type')->options(self::holdTypeOptions()),
            ])->recordActions([
                Action::make('resolve')->label('Mark resolved')->authorize('resolve')->visible(fn (Hold $record): bool => $record->status === Hold::StatusActive)
                    ->modalHeading('Resolve restriction')->modalSubmitActionLabel('Mark resolved')
                    ->schema([Textarea::make('evidence')->label('How was this resolved?')
                        ->helperText('Describe the completed requirement or confirmation from the responsible office.')->required()])
                    ->action(function (Hold $record, array $data): void {
                        app(ResolveHold::class)->execute($record, auth()->user(), $data['evidence']);
                        Notification::make()->title('Hold resolved')->success()->send();
                    }),
            ])->defaultSort('created_at', 'desc');
    }

    /** @return array<string,string> */
    private static function holdTypeOptions(): array
    {
        return [
            Hold::TypeFinancial => 'Financial', Hold::TypeDocumentary => 'Documentary',
            Hold::TypeBehavioral => 'Behavioral', Hold::TypeDisciplinary => 'Disciplinary',
            Hold::TypeAcademicDeficit => 'Academic Deficit', Hold::TypePrerequisite => 'Prerequisite',
            Hold::TypeEnrollment => 'Enrollment', Hold::TypeCorDownload => 'COR Download',
            Hold::TypeClearance => 'Clearance', Hold::TypeGraduationEligibility => 'Graduation Eligibility',
            Hold::TypeReactivation => 'Reactivation', Hold::TypeTransferOut => 'Transfer Out',
            Hold::TypeRecordRelease => 'Record Release',
        ];
    }
}
