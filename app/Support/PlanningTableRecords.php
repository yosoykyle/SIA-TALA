<?php

namespace App\Support;

use Illuminate\Pagination\LengthAwarePaginator;

class PlanningTableRecords
{
    /** @param array<int|string, array<string, mixed>> $records
     * @param  list<string>  $searchable
     */
    public static function paginate(array $records, array $searchable, ?string $search, ?string $sortColumn, ?string $sortDirection, int $page, int $recordsPerPage): LengthAwarePaginator
    {
        $rows = collect($records);
        if (filled($search)) {
            $needle = mb_strtolower(trim($search));
            $rows = $rows->filter(fn (array $row): bool => collect($searchable)->contains(fn (string $column): bool => str_contains(mb_strtolower(is_array($row[$column] ?? null) ? implode(' ', $row[$column]) : (string) ($row[$column] ?? '')), $needle)));
        }
        if ($sortColumn !== null) {
            $rows = $rows->sortBy($sortColumn, SORT_NATURAL | SORT_FLAG_CASE, $sortDirection === 'desc');
        }

        return new LengthAwarePaginator($rows->forPage($page, $recordsPerPage), $rows->count(), $recordsPerPage, $page);
    }
}
