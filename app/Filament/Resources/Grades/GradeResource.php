<?php

namespace App\Filament\Resources\Grades;

use App\Filament\Resources\Grades\Pages\ListGrades;
use App\Filament\Resources\Grades\Pages\ViewGrade;
use App\Filament\Resources\Grades\Schemas\GradeInfolist;
use App\Filament\Resources\Grades\Tables\GradesTable;
use App\Models\Grade;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class GradeResource extends Resource
{
    protected static ?string $model = Grade::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Academic Head';

    protected static ?string $navigationLabel = 'Grade Oversight';

    protected static ?int $navigationSort = 10;

    public static function infolist(Schema $schema): Schema
    {
        return GradeInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return GradesTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->with([
                'enrollment.studentProfile.user',
                'enrollmentSubject',
                'subject',
                'term',
                'faculty',
                'finalizedBy',
                'reopenedBy',
            ]);

        $user = auth()->user();

        if (! $user instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->can('view-grade-submission-progress') || $user->can('view-global-records')) {
            return $query;
        }

        return $query->whereRaw('1 = 0');
    }

    public static function canCreate(): bool
    {
        return false;
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
            'index' => ListGrades::route('/'),
            'view' => ViewGrade::route('/{record}'),
        ];
    }
}
