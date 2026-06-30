<?php

namespace App\Filament\Student\Pages;

use App\Models\StudentLifecycleChange;
use App\Models\User;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LifecycleView extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-path-rounded-square';

    protected static ?string $navigationLabel = 'Academic Status';

    protected static ?string $title = 'Progression & Lifecycle';

    protected string $view = 'filament.student.pages.generic-table';

    public function table(Table $table): Table
    {
        return $table->query(StudentLifecycleChange::query()
            ->whereHas('studentProfile', function (Builder $query): void {
                /** @var User $user */
                $user = auth()->user();
                $query->where('user_id', $user->id);
            })
            ->where('state', StudentLifecycleChange::StateApplied))
            ->columns([
                TextColumn::make('type')->badge()->formatStateUsing(fn (string $state): string => str($state)->headline()->toString()),
                TextColumn::make('term.label')->label('Term'),
                TextColumn::make('effective_on')->date(),
                TextColumn::make('state')->badge(),
                TextColumn::make('reason')->wrap(),
            ])->defaultSort('effective_on', 'desc')
            ->emptyStateHeading('No recorded lifecycle changes');
    }
}
