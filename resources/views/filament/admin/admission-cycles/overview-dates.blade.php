{{-- Admission cycle overview · Application dates (#110): two fact strips in Term Planning's style. --}}
<div class="tala-cycle-overview">
    @foreach ($groups as $group)
        <section class="tala-cycle-overview__group" aria-label="{{ $group['heading'] }}">
            <h3 class="tala-cycle-overview__group-heading">{{ $group['heading'] }}</h3>
            <dl class="tala-calendar-facts">
                @foreach ($group['dates'] as $date)
                    <div class="tala-calendar-facts__item">
                        <dt>{{ $date['label'] }}</dt>
                        @if ($date['day'])
                            <dd>{{ $date['day'] }}</dd>
                            <dd class="tala-calendar-facts__note">{{ $date['time'] }} · Philippine time</dd>
                        @else
                            <dd class="tala-cycle-overview__missing">Not set</dd>
                            @if ($date['required'])
                                <dd class="tala-calendar-facts__note">Required before publication</dd>
                            @endif
                        @endif
                    </div>
                @endforeach
            </dl>
        </section>
    @endforeach
</div>
