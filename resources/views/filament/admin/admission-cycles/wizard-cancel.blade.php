{{-- #110 item 11: Cancel sits beside Save & continue in the admission cycle wizard footer. --}}
<x-filament::button
    tag="a"
    color="gray"
    outlined
    icon="heroicon-m-x-mark"
    :href="\App\Filament\Resources\AdmissionCycles\AdmissionCycleResource::getUrl('index')"
>
    Cancel
</x-filament::button>
