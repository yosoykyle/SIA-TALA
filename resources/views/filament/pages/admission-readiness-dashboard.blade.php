<x-filament-panels::page>
    @php
        $readiness = $this->readiness;
        $summary = $readiness['summary'];
    @endphp

    <div class="space-y-6">
        <div class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
            <label class="flex max-w-md flex-1 flex-col gap-1 text-sm font-medium text-gray-700 dark:text-gray-200">
                <span>Term</span>
                <select
                    wire:model.live="selectedTermId"
                    class="rounded-lg border-gray-300 bg-white text-sm shadow-sm transition focus:border-primary-500 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-900"
                >
                    @foreach ($readiness['terms'] as $term)
                        <option value="{{ $term['id'] }}">{{ $term['label'] }}</option>
                    @endforeach
                </select>
            </label>

            <p class="text-sm text-gray-500 dark:text-gray-400">
                Updated {{ $readiness['generated_at'] }}
            </p>
        </div>

        <div class="grid gap-4 md:grid-cols-4">
            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <p class="text-sm text-gray-500 dark:text-gray-400">Offerings</p>
                <p class="mt-2 text-2xl font-semibold text-gray-950 dark:text-white">{{ $summary['total_offerings'] }}</p>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <p class="text-sm text-gray-500 dark:text-gray-400">Ready</p>
                <p class="mt-2 text-2xl font-semibold text-success-700 dark:text-success-400">{{ $summary['ready_offerings'] }}</p>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <p class="text-sm text-gray-500 dark:text-gray-400">Blocked</p>
                <p class="mt-2 text-2xl font-semibold text-danger-700 dark:text-danger-400">{{ $summary['blocked_offerings'] }}</p>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <p class="text-sm text-gray-500 dark:text-gray-400">Blockers</p>
                <p class="mt-2 text-2xl font-semibold text-warning-700 dark:text-warning-400">{{ $summary['blocker_count'] }}</p>
            </div>
        </div>

        <div class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-800">
                    <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:bg-gray-950 dark:text-gray-400">
                        <tr>
                            <th class="px-4 py-3">Offering</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Policy</th>
                            <th class="px-4 py-3">Capacity</th>
                            <th class="px-4 py-3">Blockers</th>
                            <th class="px-4 py-3">Setup</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($readiness['offerings'] as $offering)
                            <tr class="align-top">
                                <td class="px-4 py-4">
                                    <div class="font-medium text-gray-950 dark:text-white">{{ $offering['label'] }}</div>
                                    <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                        {{ strtoupper((string) $offering['education_level']) }} · {{ $offering['program'] }} · {{ $offering['year_level'] }}
                                    </div>
                                </td>
                                <td class="px-4 py-4">
                                    <span @class([
                                        'inline-flex rounded-md px-2 py-1 text-xs font-medium',
                                        'bg-success-50 text-success-700 ring-1 ring-success-600/20 dark:bg-success-500/10 dark:text-success-300' => $offering['is_ready'],
                                        'bg-danger-50 text-danger-700 ring-1 ring-danger-600/20 dark:bg-danger-500/10 dark:text-danger-300' => ! $offering['is_ready'],
                                    ])>
                                        {{ $offering['is_ready'] ? 'Ready' : 'Blocked' }}
                                    </span>
                                </td>
                                <td class="px-4 py-4 text-gray-700 dark:text-gray-200">
                                    <div>{{ $offering['active_policy_count'] }} active</div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ $offering['document_item_count'] }} items</div>
                                </td>
                                <td class="px-4 py-4">
                                    @forelse ($offering['capacity_plans'] as $plan)
                                        <div class="mb-2 last:mb-0">
                                            <div class="font-medium text-gray-800 dark:text-gray-100">{{ $plan['label'] }}</div>
                                            <div class="text-xs text-gray-500 dark:text-gray-400">
                                                {{ $plan['remaining'] }} of {{ $plan['capacity'] }} seats remaining
                                            </div>
                                        </div>
                                    @empty
                                        <span class="text-gray-500 dark:text-gray-400">No matching plan</span>
                                    @endforelse
                                </td>
                                <td class="px-4 py-4">
                                    @forelse ($offering['blockers'] as $blocker)
                                        <div class="mb-2 last:mb-0">
                                            <div class="font-medium text-gray-900 dark:text-gray-100">{{ $blocker['category'] }}</div>
                                            <div class="text-xs text-gray-600 dark:text-gray-300">{{ $blocker['message'] }}</div>
                                        </div>
                                    @empty
                                        <span class="text-success-700 dark:text-success-400">No blockers</span>
                                    @endforelse
                                </td>
                                <td class="px-4 py-4">
                                    <div class="flex flex-col gap-2">
                                        <a class="text-primary-700 hover:underline dark:text-primary-400" href="{{ \App\Filament\Resources\AdmissionOfferings\AdmissionOfferingResource::getUrl('edit', ['record' => $offering['id']]) }}">Offering</a>
                                        <a class="text-primary-700 hover:underline dark:text-primary-400" href="{{ \App\Filament\Resources\AdmissionRequirementPolicies\AdmissionRequirementPolicyResource::getUrl('index') }}">Policies</a>
                                        <a class="text-primary-700 hover:underline dark:text-primary-400" href="{{ \App\Filament\Resources\AdmissionCapacityPlans\AdmissionCapacityPlanResource::getUrl('index') }}">Capacity</a>
                                        <a class="text-primary-700 hover:underline dark:text-primary-400" href="{{ \App\Filament\Resources\ScheduleGenerationRuns\ScheduleGenerationRunResource::getUrl('index') }}">Schedules</a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td class="px-4 py-6 text-center text-gray-500 dark:text-gray-400" colspan="6">
                                    No admission offerings for this term.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-filament-panels::page>
