@if ($usesAuthDesigner)
    <nav class="tala-auth-recovery" aria-label="Access help">
        <x-filament::link :href="route('home')">Return to school home</x-filament::link>
        <x-filament::link :href="route('home', ['modal' => 'support'])">Contact school support</x-filament::link>
    </nav>
@endif
