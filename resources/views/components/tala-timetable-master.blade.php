@props(['presentation', 'groups', 'expanded' => false, 'nativeDetails' => false])

<section {{ $attributes->class(['tala-timetable-master']) }} aria-label="Whole-Term timetable by student section">

    <div class="tala-timetable-master-grid"><table><caption>Whole-Term timetable · {{ count($groups) }} student groups in this view. Shared classes appear in each linked group.</caption><thead><tr><th scope="col">Program · student section</th>@foreach ($presentation['days'] as $day)<th scope="col">{{ $day['label'] }}</th>@endforeach</tr></thead><tbody>

        @foreach ($groups as $group)<tr wire:key="master-group-{{ $group['id'] }}"><th scope="row"><span>{{ $group['program'] }}</span><strong>{{ $group['label'] }}</strong></th>@foreach ($presentation['days'] as $day)<td>@foreach (collect($group['meetings'])->where('day', $day['number']) as $meeting)@if ($nativeDetails)<button type="button" class="tala-timetable-master-meeting" wire:click="mountAction('viewMeeting', {}, { table: true, recordKey: '{{ $meeting['id'] }}' })"><strong>{{ $meeting['courseTitle'] ?: $meeting['courseCode'] }}</strong><span>{{ $meeting['time'] }}</span><span>{{ $meeting['room'] }}</span></button>@else<details class="tala-timetable-master-meeting"><summary><strong>{{ $meeting['time'] }}</strong>{{ $meeting['courseCode'] }}</summary><span>{{ $meeting['courseTitle'] }}</span><span>{{ $meeting['faculty'] }} · {{ $meeting['room'] }}</span><span>{{ $meeting['class'] }} · {{ $meeting['component'] }}</span></details>@endif@endforeach</td>@endforeach</tr>@endforeach

    </tbody></table></div>

    <div class="tala-timetable-master-mobile">@foreach ($groups as $group)<x-filament::section wire:key="master-mobile-group-{{ $group['id'] }}"><x-slot name="heading">{{ $group['label'] }}</x-slot><x-slot name="description">{{ $group['program'] }}</x-slot><ul class="space-y-3 text-sm">@foreach ($group['meetings'] as $meeting)<li>@if ($nativeDetails)<button type="button" class="tala-timetable-master-meeting" wire:click="mountAction('viewMeeting', {}, { table: true, recordKey: '{{ $meeting['id'] }}' })"><strong>{{ $meeting['courseTitle'] ?: $meeting['courseCode'] }}</strong><span>{{ $meeting['dayLabel'] }} · {{ $meeting['time'] }}</span><span>{{ $meeting['room'] }}</span></button>@else<strong>{{ $meeting['dayLabel'] }} {{ $meeting['time'] }} · {{ $meeting['courseTitle'] ?: $meeting['courseCode'] }}</strong><span class="block">{{ $meeting['faculty'] }} · {{ $meeting['room'] }}</span><span class="block">{{ $meeting['class'] }}</span>@endif</li>@endforeach</ul></x-filament::section>@endforeach</div>

    @if (! $groups)<p class="text-sm">No student groups match this program. Choose All programs to see the whole Term.</p>@endif

</section>
