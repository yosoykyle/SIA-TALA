<x-tala-navigation label="Faculty scheduling tasks" compact>
    <x-filament::tabs.item tag="a" :href="\App\Filament\Pages\FacultySchedule::getUrl(['termId' => $term?->id])" :active="$this instanceof \App\Filament\Pages\FacultySchedule">My Schedule</x-filament::tabs.item>
    <x-filament::tabs.item tag="a" :href="\App\Filament\Pages\MyAvailability::getUrl(['termId' => $term?->id])" :active="$this instanceof \App\Filament\Pages\MyAvailability">My Availability</x-filament::tabs.item>
</x-tala-navigation>
