<?php

namespace App\Filament\Student\Pages;

use App\Models\StudentLifecycleChange;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LifecycleView extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-path-rounded-square';

    protected static ?string $navigationLabel = 'School record changes';

    protected static ?string $title = 'School record changes';

    protected ?string $subheading = 'The Registrar records approved changes to your school record. Contact the Registrar about corrections.';

    protected string $view = 'filament.student.pages.generic-table';

    /** @return array<string|int, string> */
    public function getBreadcrumbs(): array
    {
        return [Dashboard::getUrl(panel: 'student') => 'Overview', $this->getTitle()];
    }

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
                TextColumn::make('type')
                    ->label('Change')
                    ->weight(FontWeight::SemiBold)
                    ->formatStateUsing(fn (string $state): string => StudentLifecycleChange::typeOptions()[$state] ?? str($state)->headline()->toString()),
                TextColumn::make('term.label')->label('Term'),
                TextColumn::make('effective_on')->label('Effective date')->date(),
            ])->defaultSort('effective_on', 'desc')
            ->stackedOnMobile()
            ->emptyStateHeading('No changes recorded')
            ->emptyStateDescription('Approved school record changes will appear here.')
            ->emptyStateActions([
                Action::make('viewEnrollment')->label('View enrollment')->color('gray')
                    ->url(Enrollment::getUrl(panel: 'student')),
            ]);
    }
}
