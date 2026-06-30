<?php

namespace App\Filament\Student\Pages;

use App\Models\GradeRosterRow;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class GradesView extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?string $navigationLabel = 'Grades';

    protected static ?string $title = 'Released Grades';

    protected string $view = 'filament.student.pages.generic-table';

    public function table(Table $table): Table
    {
        return $table
            ->query($this->releasedGradesQuery())
            ->columns([
                TextColumn::make('roster.termOffering.term.label')->label('Term'),
                TextColumn::make('roster.termOffering.curriculumEntry.courseSpecification.course.code')->label('Course Code'),
                TextColumn::make('roster.termOffering.curriculumEntry.courseSpecification.title')->label('Description')->wrap(),
                TextColumn::make('roster.termOffering.curriculumEntry.courseSpecification.credit_units')->label('Units'),
                TextColumn::make('current_outcome_code')->label('Released Grade')
                    ->badge(),
                TextColumn::make('current_outcome_category')->label('Status')->badge(),
                TextColumn::make('released_at')->label('Released')->dateTime(),
            ])
            ->emptyStateHeading('No grades available')
            ->emptyStateDescription('Grades will appear here after posting and release.')
            ->emptyStateIcon('heroicon-o-clipboard-document-list');
    }

    public function releasedGradesQuery(): Builder
    {
        $studentProfileId = auth()->user()?->studentProfile?->id;

        return GradeRosterRow::query()
            ->with([
                'roster.termOffering.term',
                'roster.termOffering.curriculumEntry.courseSpecification.course',
                'courseEnrollment.enrollment.studentProfile',
            ])
            ->whereNotNull('released_at')
            ->whereHas('courseEnrollment.enrollment', fn (Builder $query) => $query->where('student_profile_id', $studentProfileId ?? 0))
            ->orderByDesc('released_at')
            ->orderByDesc('id');
    }
}
