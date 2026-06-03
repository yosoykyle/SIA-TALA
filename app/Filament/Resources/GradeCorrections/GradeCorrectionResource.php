<?php

namespace App\Filament\Resources\GradeCorrections;

use App\Filament\Resources\GradeCorrections\Pages\ListGradeCorrections;
use App\Filament\Resources\GradeCorrections\Pages\ViewGradeCorrection;
use App\Filament\Resources\GradeCorrections\Schemas\GradeCorrectionInfolist;
use App\Filament\Resources\GradeCorrections\Tables\GradeCorrectionsTable;
use App\Models\GradeCorrection;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class GradeCorrectionResource extends Resource
{
    protected static ?string $model = GradeCorrection::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Faculty';

    protected static ?string $navigationLabel = 'Grade Corrections';

    protected static ?int $navigationSort = 41;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        $user = auth()->user();

        if ($user?->hasRole('registrar')) {
            return 'Registrar';
        }

        if ($user?->hasRole('academic-head')) {
            return 'Academic Head';
        }

        return 'Faculty';
    }

    public static function infolist(Schema $schema): Schema
    {
        return GradeCorrectionInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return GradeCorrectionsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->with(['student', 'grade', 'subject', 'term', 'assignedTo', 'creator']);

        $user = auth()->user();

        if (! $user instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->hasRole('faculty') && $user->can('view-class-list')) {
            return $query->visibleToFaculty($user);
        }

        if ($user->can('manage-grade-corrections') || $user->can('view-grade-submission-progress') || $user->can('view-global-records')) {
            return $query;
        }

        return $query->whereRaw('1 = 0');
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
            'index' => ListGradeCorrections::route('/'),
            'view' => ViewGradeCorrection::route('/{record}'),
        ];
    }
}
