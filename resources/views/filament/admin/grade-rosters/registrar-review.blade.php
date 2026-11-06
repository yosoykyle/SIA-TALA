<div class="space-y-6">
    @if ($roster->state !== \App\Models\GradeRoster::StateReleased)
        <x-filament::section>
            <x-slot name="heading">{{ $roster->state === \App\Models\GradeRoster::StateReturned ? 'Returned grades' : ($version ? 'Submitted grades' : 'Awaiting Faculty submission') }}</x-slot>
            <x-slot name="description">{{ $version ? $reviewRows->count().' students · '.($roster->teachingAssignment?->faculty?->getFilamentName() ?? 'Faculty not recorded').' · Version '.$version->version_number : 'The designated Faculty member records and submits the complete class.' }}</x-slot>
            @if ($roster->state === \App\Models\GradeRoster::StateReturned)
                @php($returnedCount = $version?->returnedRows->whereNull('resolved_at')->count() ?? 0)
                <div class="tala-review-feedback mb-5">
                    <x-filament::icon icon="heroicon-o-arrow-uturn-left" class="size-5" />
                    <div><h3>{{ $returnedCount }} {{ $returnedCount === 1 ? 'result needs' : 'results need' }} Faculty changes</h3><p>{{ $roster->return_reason }}</p><p>Next: {{ $roster->teachingAssignment?->faculty?->getFilamentName() ?? 'The designated Faculty member' }} corrects the marked results and resubmits the complete class.</p></div>
                </div>
            @endif
            @if ($reviewRows->isNotEmpty())
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-gray-200 dark:border-gray-700">
                            <tr><th scope="col" class="px-3 pb-3">Student</th><th scope="col" class="px-3 pb-3">Result</th><th scope="col" class="px-3 pb-3">Remaining work</th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            @foreach ($reviewRows as $entry)
                                @php($student = $entry->rosterRow?->courseEnrollment?->enrollment?->studentProfile)
                                <tr>
                                    <td class="p-3"><p class="font-semibold">{{ collect([$student?->last_name, $student?->first_name, $student?->middle_name])->filter()->implode(', ') }}</p><p class="mt-1 text-xs text-gray-600 dark:text-gray-300">{{ $student?->student_number }}</p>@if ($roster->state === \App\Models\GradeRoster::StateReturned && $version?->returnedRows->whereNull('resolved_at')->contains('grade_roster_row_id', $entry->grade_roster_row_id))<x-filament::badge color="warning" class="mt-2 w-fit">Changes requested</x-filament::badge>@endif</td>
                                    <td class="p-3 text-xl font-semibold tabular-nums">{{ $entry->final_result }}</td>
                                    <td class="p-3">{{ $entry->inc_completion_note ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($roster->state === \App\Models\GradeRoster::StateSubmitted && auth()->user()?->hasRole(\App\Models\User::StaffRoleRegistrar))
                    <div class="mt-5 flex flex-wrap items-center justify-between gap-4 border-t border-gray-200 pt-5 dark:border-gray-700">
                        <p class="max-w-md text-sm text-gray-600 dark:text-gray-300">Release makes this complete class submission visible to students. Any affected enrollment goes to the Registrar for review.</p>
                        <div class="flex flex-wrap items-center gap-3">{{ $getLivewire()->returnAction }} {{ $getLivewire()->postAndReleaseAction }}</div>
                    </div>
                @endif
            @endif
            @if ($roster->return_reason && $roster->state !== \App\Models\GradeRoster::StateReturned)
                <div class="tala-review-feedback mt-4">
                    <x-filament::icon icon="heroicon-o-arrow-uturn-left" class="size-5" />
                    <div><h3>Returned for changes</h3><p>{{ $roster->return_reason }}</p></div>
                </div>
            @endif
        </x-filament::section>
    @endif

    @if ($roster->state !== \App\Models\GradeRoster::StateReleased && ! auth()->user()?->hasRole(\App\Models\User::StaffRoleRegistrar))
        @include('filament.admin.grade-rosters.source-details')
    @endif
</div>
