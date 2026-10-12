<?php

namespace App\Actions\Calendar;

use App\Models\Term;
use App\Models\TermCalendarPackage;

class TermTeachingGrid
{
    public function activePackage(Term $term): ?TermCalendarPackage
    {
        return TermCalendarPackage::query()->where('term_id', $term->id)
            ->where('state', TermCalendarPackage::StateActive)->with('teachingGridRows')->first();
    }

    /** @return list<array{day_of_week:int,starts_at:string,ends_at:string}> */
    public function rows(Term $term): array
    {
        $package = $this->activePackage($term);
        if ($package) {
            return $package->teachingGridRows->sortBy('day_of_week')->map(fn ($row): array => [
                'day_of_week' => (int) $row->day_of_week,
                'starts_at' => substr((string) $row->starts_at, 0, 8),
                'ends_at' => substr((string) $row->ends_at, 0, 8),
            ])->values()->all();
        }

        return [];
    }

    public function slotMinutes(Term $term): int
    {
        return 30;
    }
}
