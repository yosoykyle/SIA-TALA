<?php

namespace App\Actions\Applicants;

use App\Models\AdmissionOffering;
use App\Models\AdmissionRequirementPolicy;
use App\Models\DocumentRequirementItem;
use Illuminate\Support\Collection;

class AdmissionRequirementResolution
{
    /**
     * @param  Collection<int, DocumentRequirementItem>  $items
     */
    public function __construct(
        public AdmissionOffering $offering,
        public AdmissionRequirementPolicy $policy,
        public Collection $items,
    ) {}

    /**
     * @return list<string>
     */
    public function documentKeys(): array
    {
        return $this->items
            ->pluck('key')
            ->filter()
            ->values()
            ->all();
    }
}
