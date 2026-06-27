<?php

namespace App\Filament\Student\Pages;

use App\Models\User;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

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
                // Empty query for the shell
                User::query()->where('id', 0)
            )
            ->columns([
                TextColumn::make('subject_code')->label('Course Code'),
                TextColumn::make('description')->label('Description'),
                TextColumn::make('units')->label('Units'),
                TextColumn::make('schedule')->label('Schedule Details'),
            ])
            ->emptyStateHeading('No schedule available')
            ->emptyStateDescription('Your class schedule is not available yet.')
            ->emptyStateIcon('heroicon-o-calendar');
    }
}
