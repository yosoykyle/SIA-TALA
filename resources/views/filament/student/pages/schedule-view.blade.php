<x-filament-panels::page>
    @assets<link rel="stylesheet" href="{{ asset('css/tala-enrollment.css').'?v='.filemtime(public_path('css/tala-enrollment.css')) }}">@endassets
    <div class="learner-enrollment student-schedule-reading">
        @if ($schedulePresentation && $schedulePresentation['meetings'] !== [])
            <x-enrollment-schedule :presentation="$schedulePresentation" source-key="student-schedule" />
        @else
            <section class="student-read-empty">
                <h2>{{ $hasOfficialEnrollment ? 'Class meetings are not available yet' : 'Your class schedule starts with official enrollment' }}</h2>
                <p>{{ $hasOfficialEnrollment ? 'Ask the Registrar about the published meetings for your registered classes.' : 'Your weekly schedule appears here after your enrollment is official.' }}</p>
                <x-filament::button tag="a" color="gray" :href="\App\Filament\Student\Pages\Enrollment::getUrl(panel: 'student')">View enrollment</x-filament::button>
            </section>
        @endif
    </div>
</x-filament-panels::page>
