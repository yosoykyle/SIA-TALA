<x-filament::section>
    <x-slot name="heading">Official published timetable</x-slot>
    <x-slot name="description">Use the approved version for teaching and enrollment. Reviewing a new candidate does not replace this timetable.</x-slot>
    @if ($selectedVersion)
        <div class="tala-planning-next"><div><h3>Version {{ $selectedVersion->version }} · {{ $selectedVersion->state === 'Published' ? 'Current official timetable' : 'Superseded history' }}</h3><p>{{ $selectedVersion->published_at?->timezone('Asia/Manila')->format('M j, Y · g:i A') }} · {{ $selectedVersion->authority_reference }}</p></div>
            <x-filament::button tag="a" :href="route('timetable.version.print', ['version' => $selectedVersion])" target="_blank" icon="heroicon-o-printer">Print official timetable</x-filament::button>
        </div>
        <div class="mt-5"><x-tala-timetable :presentation="$publishedPresentation" wire:key="published-timetable-{{ $selectedVersion->id }}" /></div>
        <details class="tala-planning-details"><summary>Publication history and authority</summary><div class="mt-4 space-y-3">
            @if ($selectedVersion->publication_reason)<p>{{ $selectedVersion->publication_reason }}</p>@endif
            @foreach ($versions as $version)<p><x-filament::link :href="\App\Filament\Pages\TermPlanningWorkbench::getUrl(['termId' => $term->id, 'viewTab' => 'published', 'selectedVersionId' => $version->id])">Version {{ $version->version }} · {{ $version->state }}</x-filament::link> · {{ $version->published_at?->timezone('Asia/Manila')->format('M j, Y · g:i A') }} · {{ $version->authority_reference }}</p>@endforeach
        </div></details>
    @else
        <h3 class="font-semibold">No official timetable yet</h3><p class="mt-2 text-sm">Review a generated candidate, accept it, then record the approved external sign-off to publish.</p>
        <div class="mt-5"><x-filament::button tag="a" :href="\App\Filament\Pages\TermPlanningWorkbench::getUrl(['termId' => $term->id, 'viewTab' => 'generate'])">Open Generate &amp; Review</x-filament::button></div>
    @endif
</x-filament::section>
