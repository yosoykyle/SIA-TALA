<?php

namespace App\Filament\Resources\ExamAccessAccommodations;

use App\Filament\Resources\ExamAccessAccommodations\Pages\CreateExamAccessAccommodation;
use App\Filament\Resources\ExamAccessAccommodations\Pages\ListExamAccessAccommodations;
use App\Filament\Resources\ExamAccessAccommodations\Pages\ViewExamAccessAccommodation;
use App\Filament\Resources\ExamAccessAccommodations\Schemas\ExamAccessAccommodationForm;
use App\Filament\Resources\ExamAccessAccommodations\Schemas\ExamAccessAccommodationInfolist;
use App\Filament\Resources\ExamAccessAccommodations\Tables\ExamAccessAccommodationsTable;
use App\Models\ExamAccessAccommodation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ExamAccessAccommodationResource extends Resource
{
    protected static ?string $model = ExamAccessAccommodation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Accounting';

    protected static ?string $navigationLabel = 'Exam Access Accommodations';

    protected static ?int $navigationSort = 31;

    public static function form(Schema $schema): Schema
    {
        return ExamAccessAccommodationForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ExamAccessAccommodationInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ExamAccessAccommodationsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExamAccessAccommodations::route('/'),
            'create' => CreateExamAccessAccommodation::route('/create'),
            'view' => ViewExamAccessAccommodation::route('/{record}'),
        ];
    }
}
