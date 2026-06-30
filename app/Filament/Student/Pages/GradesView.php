<?php

namespace App\Filament\Student\Pages;

use App\Models\Grade;
use App\Models\User;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

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
            ->query($this->emptyGradesScaffoldQuery())
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
            ->emptyStateDescription('Grades will appear here after posting and release.')
            ->emptyStateIcon('heroicon-o-clipboard-document-list');
    }

    public function emptyGradesScaffoldQuery(): Builder
    {
        if (Schema::hasTable((new Grade)->getTable())) {
            return Grade::query()->whereRaw('1 = 0');
        }

        return User::query()->whereRaw('1 = 0');
    }
}
