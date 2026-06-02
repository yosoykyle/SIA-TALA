<?php

namespace App\Filament\Resources\EnrollmentSubjects;

use App\Filament\Resources\EnrollmentSubjects\Pages\ListEnrollmentSubjects;
use App\Filament\Resources\EnrollmentSubjects\Pages\ViewEnrollmentSubject;
use App\Filament\Resources\EnrollmentSubjects\Schemas\EnrollmentSubjectForm;
use App\Filament\Resources\EnrollmentSubjects\Schemas\EnrollmentSubjectInfolist;
use App\Filament\Resources\EnrollmentSubjects\Tables\EnrollmentSubjectsTable;
use App\Models\EnrollmentSubject;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class EnrollmentSubjectResource extends Resource
{
    protected static ?string $model = EnrollmentSubject::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Faculty';

    protected static ?string $navigationLabel = 'Faculty Class Lists';

    protected static ?int $navigationSort = 40;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        if (auth()->user()?->hasRole('academic-head')) {
            return 'Academic Head';
        }

        return 'Faculty';
    }

    public static function form(Schema $schema): Schema
    {
        return EnrollmentSubjectForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return EnrollmentSubjectInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EnrollmentSubjectsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->with([
                'enrollment.studentProfile.user',
                'enrollment.studentProfile.program',
                'enrollment.term',
                'enrollment.section',
                'subject',
                'sectionMeeting',
                'grade',
            ]);

        $user = auth()->user();

        if (! $user instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->hasRole('faculty') && $user->can('view-class-list')) {
            return $query
                ->assignedToFaculty($user)
                ->where('status', 'enrolled')
                ->where('is_dropped', false)
                ->whereHas('enrollment', function (Builder $enrollmentQuery): void {
                    $enrollmentQuery->whereIn('status', ['pre_enrolled', 'officially_enrolled']);
                });
        }

        if ($user->can('view-grade-submission-progress') || $user->can('view-global-records')) {
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
            'index' => ListEnrollmentSubjects::route('/'),
            'view' => ViewEnrollmentSubject::route('/{record}'),
        ];
    }
}
