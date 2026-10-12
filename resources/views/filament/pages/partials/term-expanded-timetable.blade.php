@if ($presentation)<x-tala-timetable-master :presentation="$presentation" :groups="$groups" expanded />@else<p>No timetable is available for this selected version.</p>@endif
