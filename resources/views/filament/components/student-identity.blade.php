<section class="student-profile-identity student-identity-panel tala-task-surface" aria-label="Student identity">
    @include('filament.components.learner-journey-art', ['artClass' => 'student-identity-art', 'artAlignment' => 'xMaxYMax meet'])
    <div class="student-profile-avatar" aria-hidden="true">@if (auth()->user()->avatar_path ?? null)<img src="{{ auth()->user()->getFilamentAvatarUrl() }}" alt="">@else{{ str($student?->first_name)->substr(0, 1) }}{{ str($student?->last_name)->substr(0, 1) }}@endif</div>
    <div class="student-identity-content">
        <h2>{{ $identityName }}</h2>
        <p class="student-identity-program">{{ $identityProgram }}</p>
        <dl class="student-identity-facts">
            <div><dt>Student number</dt><dd>{{ $identityNumber }}</dd></div>
            @if (filled($identityStudies ?? null))
                <div><dt>Current studies</dt><dd>{{ $identityStudies }}</dd></div>
            @endif
        </dl>
        @if (isset($identityPhotoAction))<div class="student-profile-photo-action">{{ $identityPhotoAction }}</div>@endif
    </div>
</section>
