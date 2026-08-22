<?php

namespace App\Filament\Resources\GraduationReviewBatches;

use App\Actions\Graduation\GraduationEligibilitySnapshotService;
use App\Filament\Resources\GraduationReviewBatches\Pages\CreateGraduationReviewBatch;
use App\Filament\Resources\GraduationReviewBatches\Pages\EditGraduationReviewBatch;
use App\Filament\Resources\GraduationReviewBatches\Pages\ListGraduationReviewBatches;
use App\Filament\Resources\GraduationReviewBatches\Pages\ViewGraduationReviewBatch;
use App\Filament\Resources\GraduationReviewBatches\RelationManagers\MembersRelationManager;
use App\Filament\Resources\GraduationReviewBatches\Schemas\GraduationReviewBatchForm;
use App\Filament\Resources\GraduationReviewBatches\Schemas\GraduationReviewBatchInfolist;
use App\Filament\Resources\GraduationReviewBatches\Tables\GraduationReviewBatchesTable;
use App\Models\GraduationReviewBatch;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class GraduationReviewBatchResource extends Resource
{
    protected static ?string $model = GraduationReviewBatch::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-academic-cap';

    protected static string|UnitEnum|null $navigationGroup = 'Student Records';

    protected static ?string $navigationLabel = 'Completion Eligibility Reviews';

    protected static ?string $modelLabel = 'completion eligibility review';

    protected static ?string $pluralModelLabel = 'completion eligibility reviews';

    protected static ?int $navigationSort = 30;

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return GraduationReviewBatchForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return GraduationReviewBatchInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return GraduationReviewBatchesTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withCount([
                'members as active_members_count' => fn (Builder $query): Builder => $query
                    ->where('is_active', true),
                'members as awaiting_evaluation_count' => fn (Builder $query): Builder => $query
                    ->where('is_active', true)
                    ->whereDoesntHave('snapshots'),
                'members as blocked_members_count' => fn (Builder $query): Builder => $query
                    ->where('is_active', true)
                    ->whereHas('latestSnapshot', fn (Builder $snapshotQuery): Builder => $snapshotQuery
                        ->where('result_status', 'like', 'Blocked:%')),
                'members as ready_members_count' => fn (Builder $query): Builder => $query
                    ->where('is_active', true)
                    ->whereHas('latestSnapshot', fn (Builder $snapshotQuery): Builder => $snapshotQuery
                        ->where('result_status', GraduationEligibilitySnapshotService::ResultReadyForRegistrarReview)),
                'members as complete_members_count' => fn (Builder $query): Builder => $query
                    ->where('is_active', true)
                    ->whereHas('latestSnapshot', fn (Builder $snapshotQuery): Builder => $snapshotQuery
                        ->where('result_status', GraduationEligibilitySnapshotService::ResultComplete)),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            MembersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGraduationReviewBatches::route('/'),
            'create' => CreateGraduationReviewBatch::route('/create'),
            'view' => ViewGraduationReviewBatch::route('/{record}'),
            'edit' => EditGraduationReviewBatch::route('/{record}/edit'),
        ];
    }
}
