<?php

namespace App\Filament\Resources\ApplicantIntakes;

use App\Filament\Resources\ApplicantIntakes\Pages\CreateApplicantIntake;
use App\Filament\Resources\ApplicantIntakes\Pages\EditApplicantIntake;
use App\Filament\Resources\ApplicantIntakes\Pages\ListApplicantIntakes;
use App\Filament\Resources\ApplicantIntakes\Pages\ViewApplicantIntake;
use App\Filament\Resources\ApplicantIntakes\Schemas\ApplicantIntakeForm;
use App\Filament\Resources\ApplicantIntakes\Schemas\ApplicantIntakeInfolist;
use App\Filament\Resources\ApplicantIntakes\Tables\ApplicantIntakesTable;
use App\Models\ApplicantIntake;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ApplicantIntakeResource extends Resource
{
    protected static ?string $model = ApplicantIntake::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return ApplicantIntakeForm::configure($schema);
    }

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
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListApplicantIntakes::route('/'),
            'create' => CreateApplicantIntake::route('/create'),
            'view' => ViewApplicantIntake::route('/{record}'),
            'edit' => EditApplicantIntake::route('/{record}/edit'),
        ];
    }
}
