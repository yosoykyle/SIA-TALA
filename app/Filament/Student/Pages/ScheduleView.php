<?php

namespace App\Filament\Student\Pages;

use App\Models\StudentScheduleBinding;
use App\Models\User;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ScheduleView extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationLabel = 'Class Schedule';

    protected static ?string $title = 'Class Schedule';

    protected string $view = 'filament.student.pages.generic-table';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                StudentScheduleBinding::query()
                    ->where('is_active', true)
                    ->whereHas('courseEnrollment.enrollment.studentProfile', function (Builder $query): void {
                        /** @var User $user */
                        $user = auth()->user();
                        $query->where('user_id', $user->id);
                    })
            )
            ->columns([
                TextColumn::make('courseEnrollment.termOffering.curriculumEntry.courseSpecification.course.code')->label('Course Code'),
                TextColumn::make('courseEnrollment.termOffering.curriculumEntry.courseSpecification.title')->label('Description'),
                TextColumn::make('courseEnrollment.units_snapshot')->label('Units'),
                TextColumn::make('sectionMeeting.day_of_week')->label('Day'),
                TextColumn::make('sectionMeeting.starts_at')->label('Starts'),
                TextColumn::make('sectionMeeting.ends_at')->label('Ends'),
                TextColumn::make('sectionMeeting.room.name')->label('Room'),
            ])
            ->emptyStateHeading('No schedule available')
            ->emptyStateDescription('Your class schedule is not available yet.')
            ->emptyStateIcon('heroicon-o-calendar');
    }
}
