<?php

namespace App\Filament\Resources\GradeRosters\Concerns;

use App\Models\GradeRosterRow;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Locked;

trait InteractsWithResultHistory
{
    public int $resultHistoryPage = 1;

    #[Locked]
    public ?int $resultHistoryRowId = null;

    public function gotoResultHistoryPage(int $page): void
    {
        $this->resultHistoryPage = max(1, $page);
    }

    public function resultHistoryFor(int $rowId): ?LengthAwarePaginator
    {
        $row = $this->getTableRecord((string) $rowId);
        if (! $row instanceof GradeRosterRow) {
            return null;
        }

        if ($this->resultHistoryRowId !== $row->id) {
            $this->resultHistoryPage = 1;
            $this->resultHistoryRowId = $row->id;
        }

        $query = $row->outcomeEvents()->whereNotNull('released_at')->with('recorder')->orderByDesc('id');
        $history = $query->paginate(10, pageName: 'resultHistoryPage', page: $this->resultHistoryPage);
        if ($history->currentPage() > $history->lastPage()) {
            $history = $query->paginate(10, pageName: 'resultHistoryPage', page: $history->lastPage());
        }
        $this->resultHistoryPage = $history->currentPage();

        return $history;
    }
}
