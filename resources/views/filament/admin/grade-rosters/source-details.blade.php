<x-supporting-details summary="Class submission history" :open="$getLivewire()->submissionHistoryOpen">
    @include('filament.admin.grade-rosters.submission-history')
</x-supporting-details>
<x-supporting-details summary="Teaching assignment and notification delivery">
    @include('filament.admin.grade-rosters.class-delivery')
</x-supporting-details>
