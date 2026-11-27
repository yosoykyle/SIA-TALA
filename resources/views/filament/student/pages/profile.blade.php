<x-filament-panels::page>
    @assets
        <link rel="stylesheet" href="{{ asset('css/tala-enrollment.css').'?v='.filemtime(public_path('css/tala-enrollment.css')) }}">
    @endassets
    @php
        [$identitySection, $studies, $contact, $school] = $this->profileSections;
        $identity = collect($identitySection['items'])->pluck('value', 'label');
        $studyFacts = collect($studies['items'])->pluck('value', 'label');
        $termFacts = $studyFacts->only(['Term', 'School year'])->reject(fn ($value) => in_array($value, ['Unavailable', 'Not recorded'], true));
        $contactFacts = collect($contact['items'])->reject(fn ($item) => in_array($item['value'], ['Unavailable', 'Not recorded'], true));
        $schoolFacts = collect($school['items'])->pluck('value', 'label');
        $year = $studyFacts['Year level'];
        $status = $schoolFacts['Student status'];
        $student = auth()->user()->studentProfile;
    @endphp

    <div class="student-information student-profile-page">
        @include('filament.components.student-identity', [
            'identityName' => $identity['Name'],
            'identityProgram' => $studyFacts['Program'],
            'identityNumber' => $identity['Student number'],
            'identityPhotoAction' => $this->changePhotoAction,
            'identityStatus' => $status,
            'identityShowNumberLabel' => true,
        ])

        <div class="student-profile-columns">
            <section class="student-profile-section student-profile-studies tala-task-surface" aria-labelledby="student-profile-studies">
                <h2 class="student-profile-section-heading" id="student-profile-studies">
                    <x-filament::icon icon="heroicon-o-academic-cap" class="student-overview-task-icon" aria-hidden="true" />
                    Your studies
                </h2>

                @unless (in_array($year, ['Unavailable', 'Not recorded'], true))
                    <p class="student-profile-year"><span class="sr-only">Year level: </span>{{ $year }}</p>
                @endunless
                @if ($termFacts->isNotEmpty())
                    <dl class="student-profile-term">
                        @foreach ($termFacts as $label => $value)
                            <div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
                        @endforeach
                    </dl>
                @endif

                <p class="student-profile-note">{{ $studies['description'] }}</p>
                <x-filament::button tag="a" icon="heroicon-m-arrow-right" icon-position="after"
                    :href="\App\Filament\Student\Pages\Enrollment::getUrl(panel: 'student')">
                    View enrollment
                </x-filament::button>
            </section>

            <section class="student-profile-section tala-task-surface" aria-labelledby="student-profile-school">
                <h2 class="student-profile-section-heading" id="student-profile-school">
                    <x-filament::icon icon="heroicon-o-document-text" class="student-overview-task-icon" aria-hidden="true" />
                    School record
                </h2>
                <dl class="student-profile-record">
                    @foreach ($schoolFacts->except('Student status') as $label => $value)
                        <div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
                    @endforeach
                </dl>
            </section>
        </div>

        <section class="student-profile-section student-profile-contact tala-task-surface" aria-labelledby="student-profile-contact">
            <h2 class="student-profile-section-heading" id="student-profile-contact">
                <x-filament::icon icon="heroicon-o-envelope" class="student-overview-task-icon" aria-hidden="true" />
                Contact details
            </h2>
            @if ($contactFacts->isNotEmpty())
                <dl>
                    @foreach ($contactFacts as $item)
                        <div><dt>{{ $item['label'] }}</dt><dd>{{ $item['value'] }}</dd></div>
                    @endforeach
                </dl>
            @else
                <p class="student-profile-note">No contact details are recorded.</p>
            @endif
            <p class="student-profile-note">For corrections, contact the Registrar.</p>
        </section>

        @if ($lifecycleChanges->isNotEmpty() || $changeUnavailable)
            <x-record-history id="school-record-changes" class="student-profile-lifecycle-changes" heading="School record changes" heading-tag="h2" description="Applied changes to your school record. Contact the Registrar about corrections." :paginator="$lifecycleChanges" :has-entries="$lifecycleChanges->isNotEmpty()" label="changes" show-count>
                @if ($changeUnavailable)<li class="student-school-change-unavailable" role="status">This change is unavailable. Your own applied changes are shown below.</li>@endif
                @foreach ($lifecycleChanges as $change)
                    <x-record-history-item :heading="$change->studentLabel()" heading-tag="h3" icon="heroicon-o-arrow-path" :id="'school-change-'.$change->id" :class="$this->change === $change->id ? 'student-school-change-target' : ''" :wire:key="'school-change-'.$change->id">
                        <p>{{ collect([$change->term?->label, $change->term?->academicYear?->label])->filter()->implode(' · ') }}</p>
                        <p>Effective <time datetime="{{ $change->effective_on?->toDateString() }}">{{ $change->effective_on?->format('M j, Y') }}</time></p>
                    </x-record-history-item>
                @endforeach
                <x-slot:empty>@if ($changeUnavailable)This change is unavailable. @endif No applied changes. School record changes appear here after the Registrar applies them.</x-slot:empty>
            </x-record-history>
        @endif

        @if ($correctionHistory !== [])
            <details class="student-profile-section student-profile-corrections tala-task-surface">
                <summary>Recorded corrections</summary>
                <ol>
                    @foreach ($correctionHistory as $event)
                        <li><strong>{{ $event['event'] }}</strong><p>{{ $event['effective_at'] }} · {{ $event['reason'] }}</p></li>
                    @endforeach
                </ol>
            </details>
        @endif
    </div>
</x-filament-panels::page>
