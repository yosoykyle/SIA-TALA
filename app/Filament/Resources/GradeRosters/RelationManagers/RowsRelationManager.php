<?php

namespace App\Filament\Resources\GradeRosters\RelationManagers;

use App\Filament\Resources\GradeRosters\Concerns\InteractsWithResultHistory;
use App\Filament\Resources\GradeRosters\Tables\ReleasedResultsTable;
use App\Models\GradeRoster;
use App\Models\GradeRosterRow;
use App\Models\User;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;

class RowsRelationManager extends RelationManager
{
    use InteractsWithResultHistory;

    protected static bool $isLazy = false;

    protected static string $relationship = 'rows';

    protected static ?string $title = 'Released results';

    protected string $view = 'filament.admin.grade-rosters.result-inspector';

    public ?int $selectedResultId = null;

    #[Locked]
    public ?int $initialResultId = null;

    public function mount(): void
    {
        parent::mount();

        $requested = request()->integer('result');
        $this->initialResultId = $requested > 0 ? $requested : null;
        $this->selectedResultId = $this->initialResultId;
    }

    public function selectResult(int $recordId): void
    {
        if ($this->getTableRecord((string) $recordId) instanceof GradeRosterRow) {
            $this->selectedResultId = $recordId;
        }
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $records = $this->getTableRecords();
        $visible = $records instanceof Collection ? $records : $records->getCollection();
        $selected = $visible->firstWhere('id', $this->selectedResultId) ?? $visible->first();

        return [
            'records' => $records,
            'selected' => $selected,
            'history' => $selected ? $this->resultHistoryFor($selected->id) : null,
            'followUp' => $selected ? ReleasedResultsTable::followUp($selected) : null,
        ];
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof GradeRoster
            && $ownerRecord->state === GradeRoster::StateReleased
            && (auth()->user()?->hasAnyRole([User::StaffRoleRegistrar, User::StaffRoleAcademicHead]) ?? false);
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return ReleasedResultsTable::configure($table)
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->whereNotNull('released_at'))
            ->defaultSort(fn (Builder $query): Builder => $query
                ->when($this->initialResultId, fn (Builder $rows): Builder => $rows
                    ->orderByRaw('CASE WHEN grade_roster_rows.id = ? THEN 0 ELSE 1 END', [$this->initialResultId]))
                ->orderBy('grade_roster_rows.id'))
            ->defaultPaginationPageOption(10)
            ->paginationPageOptions([10, 25, 50]);
    }
}
