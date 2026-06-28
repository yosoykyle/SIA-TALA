<?php

namespace App\Filament\Resources\ApplicantIntakes;

use App\Filament\Resources\ApplicantIntakes\Pages\ListApplicantIntakes;
use App\Filament\Resources\ApplicantIntakes\Pages\ViewApplicantIntake;
use App\Filament\Resources\ApplicantIntakes\Schemas\ApplicantIntakeInfolist;
use App\Filament\Resources\ApplicantIntakes\Tables\ApplicantIntakesTable;
use App\Filament\Resources\StudentProfiles\RelationManagers\ChecklistItemsRelationManager;
use App\Models\ApplicantIntake;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class ApplicantIntakeResource extends Resource
{
    protected static ?string $model = ApplicantIntake::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Registrar';

    protected static ?string $navigationLabel = 'Applicant Review';

    protected static ?int $navigationSort = 20;

    public static function infolist(Schema $schema): Schema
    {
        return ApplicantIntakeInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ApplicantIntakesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ChecklistItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListApplicantIntakes::route('/'),
            'view' => ViewApplicantIntake::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('status', '!=', ApplicantIntake::StatusDraft);
    }
}
