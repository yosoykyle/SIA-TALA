<?php

namespace App\Filament\Student\Pages;

use App\Models\Grade;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

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
            ->query(
                // Empty query for the shell
                Grade::query()->where('id', 0)
            )
            ->columns([
                TextColumn::make('subject_code')->label('Course Code'),
                TextColumn::make('description')->label('Description'),
                TextColumn::make('units')->label('Units'),
                TextColumn::make('midterm_grade')->label('Midterm'),
                TextColumn::make('final_grade')->label('Final'),
                TextColumn::make('remarks')->label('Remarks')
                    ->badge(),
            ])
            ->emptyStateHeading('No grades available')
            ->emptyStateDescription('Grades for the current term have not been released yet.')
            ->emptyStateIcon('heroicon-o-clipboard-document-list');
    }
}
