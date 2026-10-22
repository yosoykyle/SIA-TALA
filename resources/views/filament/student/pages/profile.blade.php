<x-filament-panels::page>
    @assets<link rel="stylesheet" href="{{ asset('css/tala-enrollment.css').'?v='.filemtime(public_path('css/tala-enrollment.css')) }}">@endassets
    @php
        $identity = collect($this->profileSections[0]['items'])->pluck('value', 'label');
        $studies = $this->profileSections[1];
        $program = collect($studies['items'])->firstWhere('label', 'Program')['value'];
        $student = auth()->user()->studentProfile;
    @endphp
    <div class="student-information student-profile-page">
        <section class="student-profile-identity" aria-label="Student identity">
            <div class="student-profile-avatar" aria-hidden="true">@if (auth()->user()->avatar_path ?? null)<img src="{{ auth()->user()->getFilamentAvatarUrl() }}" alt="">@else{{ str($student?->first_name)->substr(0, 1) }}{{ str($student?->last_name)->substr(0, 1) }}@endif</div>
            <div><h2>{{ $identity['Name'] }}</h2><p>Student {{ $identity['Student number'] }}</p><p>{{ $program }}</p><div class="student-profile-photo-action">{{ $this->changePhotoAction }}</div></div>
        </section>
        <div class="student-profile-columns">
            @foreach (array_slice($this->profileSections, 1, 2) as $section)
                @php($visibleItems = array_filter($section['items'], fn (array $item): bool => ! in_array($item['value'], ['Unavailable', 'Not recorded'], true) && ! ($section['heading'] === 'Current studies' && $item['label'] === 'Program')))
                <section class="student-profile-section"><h2>{{ $section['heading'] === 'Current studies' ? 'Your studies' : $section['heading'] }}</h2>
                    @if ($section['description'] ?? null)<p class="student-profile-study-note">{{ $section['description'] }}</p>@endif
                    @if ($visibleItems !== [])<dl>@foreach ($visibleItems as $item)<div><dt>{{ $item['label'] }}</dt><dd>{{ $item['value'] }}</dd></div>@endforeach</dl>@endif
                    @if ($section['heading'] === 'Current studies')<div class="student-profile-photo-action"><x-filament::button tag="a" color="gray" :href="\App\Filament\Student\Pages\Enrollment::getUrl(panel: 'student')">View enrollment</x-filament::button></div>
                    @else<p class="student-information-guidance">For corrections, contact the Registrar.</p>@endif
                </section>
            @endforeach
        </div>
        <section class="student-profile-school"><h2>School record</h2><dl>@foreach ($this->profileSections[3]['items'] as $item)<div><dt>{{ $item['label'] }}</dt><dd>{{ $item['value'] }}</dd></div>@endforeach</dl></section>
        @if ($correctionHistory !== [])
            <details class="student-profile-corrections"><summary>Recorded corrections</summary><ol>@foreach ($correctionHistory as $event)<li><strong>{{ $event['event'] }}</strong><p>{{ $event['effective_at'] }} · {{ $event['reason'] }}</p></li>@endforeach</ol></details>
        @endif
    </div>
</x-filament-panels::page>
