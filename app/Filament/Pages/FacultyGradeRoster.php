<?php

namespace App\Filament\Pages;

use App\Actions\Grades\SaveGradeRosterPeriodEquivalent;
use App\Actions\Grades\SubmitGradeRoster;
use App\Models\GradeRoster;
use App\Models\GradeRosterRow;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class FacultyGradeRoster extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static string|\UnitEnum|null $navigationGroup = 'Faculty';

    protected static ?string $navigationLabel = 'Grade Roster';

    protected static ?string $title = 'Grade Roster';

    protected string $view = 'filament.student.pages.generic-table';

    public ?int $rosterId = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole(User::StaffRoleFaculty) ?? false;
    }

    public function mount(): void
    {
        $this->rosterId = GradeRoster::query()
            ->where('faculty_user_id', auth()->id())
            ->whereIn('state', [GradeRoster::StateDraft, GradeRoster::StateReturned, GradeRoster::StateLateNotSubmitted])
            ->orderByDesc('id')
            ->value('id');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->rowsQuery())
            ->columns([
                TextColumn::make('courseEnrollment.enrollment.studentProfile.student_number')
                    ->label('Student No.')
                    ->searchable(),
                TextColumn::make('courseEnrollment.enrollment.studentProfile.last_name')
                    ->label('Last Name')
                    ->searchable(),
                TextInputColumn::make('prelim_equivalent')
                    ->label('Prelim')
                    ->type('number')
                    ->rules(['nullable', 'numeric', 'min:0', 'max:100'])
                    ->updateStateUsing(fn (GradeRosterRow $record, mixed $state) => app(SaveGradeRosterPeriodEquivalent::class)->execute($record, 'prelim', $state, auth()->user())),
                TextInputColumn::make('midterm_equivalent')
                    ->label('Midterm')
                    ->type('number')
                    ->rules(['nullable', 'numeric', 'min:0', 'max:100'])
                    ->updateStateUsing(fn (GradeRosterRow $record, mixed $state) => app(SaveGradeRosterPeriodEquivalent::class)->execute($record, 'midterm', $state, auth()->user())),
                TextInputColumn::make('final_equivalent')
                    ->label('Final')
                    ->type('number')
                    ->rules(['nullable', 'numeric', 'min:0', 'max:100'])
                    ->updateStateUsing(fn (GradeRosterRow $record, mixed $state) => app(SaveGradeRosterPeriodEquivalent::class)->execute($record, 'final', $state, auth()->user())),
                TextColumn::make('computed_average')->label('Average'),
                TextColumn::make('current_outcome_code')->label('Outcome')->badge(),
            ])
            ->headerActions([
                Action::make('submit')
                    ->requiresConfirmation()
                    ->visible(fn (): bool => $this->rosterId !== null)
                    ->action(function (): void {
                        app(SubmitGradeRoster::class)->execute(GradeRoster::findOrFail($this->rosterId), auth()->user());
                        Notification::make()->title('Grade roster submitted')->success()->send();
                    }),
            ])
            ->emptyStateHeading('No active grade roster')
            ->emptyStateDescription('Assigned draft or returned grade rosters appear here during an encoding window.');
    }

    /**
     * @return Builder<GradeRosterRow>
     */
    private function rowsQuery(): Builder
    {
        return GradeRosterRow::query()
            ->with(['courseEnrollment.enrollment.studentProfile'])
            ->whereHas('roster', fn (Builder $query) => $query->where('faculty_user_id', auth()->id()))
            ->when($this->rosterId !== null, fn (Builder $query) => $query->where('grade_roster_id', $this->rosterId))
            ->whereRaw($this->rosterId === null ? '1 = 0' : '1 = 1');
    }
}
