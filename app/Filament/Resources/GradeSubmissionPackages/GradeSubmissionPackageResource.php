<?php

namespace App\Filament\Resources\GradeSubmissionPackages;

use App\Filament\Resources\GradeSubmissionPackages\Pages\ListGradeSubmissionPackages;
use App\Filament\Resources\GradeSubmissionPackages\Pages\ViewGradeSubmissionPackage;
use App\Filament\Resources\GradeSubmissionPackages\Schemas\GradeSubmissionPackageInfolist;
use App\Filament\Resources\GradeSubmissionPackages\Tables\GradeSubmissionPackagesTable;
use App\Models\GradeSubmissionPackage;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class GradeSubmissionPackageResource extends Resource
{
    protected static ?string $model = GradeSubmissionPackage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Registrar';

    protected static ?string $navigationLabel = 'Grade Submissions';

    protected static ?int $navigationSort = 42;

    public static function infolist(Schema $schema): Schema
    {
        return GradeSubmissionPackageInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return GradeSubmissionPackagesTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->with([
                'term',
                'section',
                'subject',
                'faculty',
                'submittedBy',
                'registrarReviewer',
                'items.grade',
                'items.enrollment.studentProfile.user',
            ]);

        $user = auth()->user();

        if (! $user instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->can('verify-grade-submissions') || $user->can('view-grade-submission-progress') || $user->can('view-global-records')) {
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
            'index' => ListGradeSubmissionPackages::route('/'),
            'view' => ViewGradeSubmissionPackage::route('/{record}'),
        ];
    }
}
