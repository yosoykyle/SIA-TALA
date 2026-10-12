<div class="p-2 sm:p-3">
    <x-tala-timetable :presentation="$presentation" focused controlled embedded native-details wire:key="faculty-week-{{ md5(json_encode([$this->termId, $this->tableFilters, $this->tableSearch])) }}" />
</div>
