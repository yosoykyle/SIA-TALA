<?php

namespace App\Filament\Resources\GraduationReviewBatches;

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
use UnitEnum;

class GraduationReviewBatchResource extends Resource
{
    protected static ?string $model = GraduationReviewBatch::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-academic-cap';

    protected static string|UnitEnum|null $navigationGroup = 'Student Records';

    protected static ?string $navigationLabel = 'Graduation Review Batches';

    protected static ?int $navigationSort = 30;

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
