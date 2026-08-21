<x-filament-panels::page>
    <x-filament::section
        heading="Current official class schedule"
        description="This view follows your current official course registrations and their exact Published Timetable version."
        icon="heroicon-o-calendar-days"
    >
        @if ($scheduleRows === [])
            <p class="text-sm text-gray-600 dark:text-gray-300">
                No current official schedule is available. Complete enrollment or ask the Registrar to review the current official registration.
            </p>
        @else
            <div class="overflow-x-auto rounded-xl ring-1 ring-gray-950/10 dark:ring-white/10">
                <table class="w-full text-left text-sm">
                    <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-600 dark:bg-white/5 dark:text-gray-300">
                        <tr>
                            <th class="px-4 py-3">Day and time</th>
                            <th class="px-4 py-3">Course</th>
                            <th class="px-4 py-3">Class</th>
                            <th class="px-4 py-3">Faculty</th>
                            <th class="px-4 py-3">Room / mode</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-950/5 dark:divide-white/10">
                        @foreach ($scheduleRows as $row)
                            <tr>
                                <td class="px-4 py-3">
                                    <span class="font-semibold text-gray-950 dark:text-white">{{ $row['day_label'] }}</span>
                                    <span class="block text-gray-600 dark:text-gray-300">{{ $row['time_label'] }}</span>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="font-semibold text-gray-950 dark:text-white">{{ $row['subject_code'] }}</span>
                                    <span class="block text-gray-600 dark:text-gray-300">{{ $row['subject_description'] }}</span>
                                </td>
                                <td class="px-4 py-3">{{ $row['section_delivery_group_name'] }}</td>
                                <td class="px-4 py-3">{{ $row['faculty_name'] ?: 'Not recorded' }}</td>
                                <td class="px-4 py-3">
                                    <span>{{ $row['room'] ?: 'TBA' }}</span>
                                    <span class="block text-gray-600 dark:text-gray-300">{{ $row['modality_label'] }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
