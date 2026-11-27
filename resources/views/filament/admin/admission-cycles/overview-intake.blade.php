{{-- Admission cycle overview · Intake (#110): same fact strip and row list as Term Planning's calendar. --}}
<div class="tala-cycle-overview">
    @if ($statusNote)
        <p class="tala-cycle-overview__note">{{ $statusNote }}</p>
    @endif

    <dl class="tala-calendar-facts">
        <div class="tala-calendar-facts__item">
            <dt>Academic Year</dt>
            @if ($academicYear)
                <dd>
                    @if ($academicYearUrl)
                        <a href="{{ $academicYearUrl }}" class="fi-link">{{ $academicYear }}</a>
                    @else
                        {{ $academicYear }}
                    @endif
                </dd>
                @if ($academicYearUrl)
                    <dd class="tala-calendar-facts__note">Calendar in Term Planning</dd>
                @endif
            @else
                <dd class="tala-cycle-overview__missing">Not selected</dd>
            @endif
        </div>
        <div class="tala-calendar-facts__item">
            <dt>Student types</dt>
            <dd @class(['tala-cycle-overview__missing' => $studentTypes === []])>{{ $studentTypes === [] ? 'None enabled' : implode(' · ', $studentTypes) }}</dd>
            <dd class="tala-calendar-facts__note">{{ trans_choice(':count accepting program|:count accepting programs', count($programs)) }}</dd>
        </div>
        <div class="tala-calendar-facts__item">
            <dt>Responsible Registrar</dt>
            <dd @class(['tala-cycle-overview__missing' => blank($registrar)])>{{ $registrar ?: 'Not assigned' }}</dd>
            @if (blank($registrar))
                <dd class="tala-calendar-facts__note">Assign before publication</dd>
            @endif
        </div>
    </dl>

    <section class="tala-cycle-overview__group" aria-labelledby="cycle-programs-heading">
        <h3 id="cycle-programs-heading" class="tala-cycle-overview__group-heading">Accepting programs</h3>
        @if ($programs === [])
            <p class="tala-cycle-overview__missing">Choose accepting programs before publication.</p>
        @else
            <ul class="tala-calendar-admissions__list">
                @foreach ($programs as $program)
                    <li class="tala-calendar-admissions__item">
                        <span class="tala-calendar-admissions__title">{{ $program['name'] }}</span>
                        <span class="tala-cycle-overview__badges">
                            @forelse ($program['types'] as $type)
                                <x-filament::badge :color="$type['color']" size="sm">{{ $type['label'] }}</x-filament::badge>
                            @empty
                                <x-filament::badge color="gray" size="sm">No student type</x-filament::badge>
                            @endforelse
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>
