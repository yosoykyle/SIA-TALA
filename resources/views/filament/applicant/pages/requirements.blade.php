<x-filament-panels::page>
    @php($intake = $this->intake())

    @if (! $intake)
        <x-filament::section>
            <x-filament::callout type="info" icon="heroicon-m-information-circle">
                Start your application first. Its requirement checklist will appear here after submission.
            </x-filament::callout>
        </x-filament::section>
    @else
        <x-filament::section>
            <x-slot name="heading">Requirement Status and Instructions</x-slot>
            <x-slot name="description">
                Review every required item and the Registrar's latest feedback. A rejected digital file can be replaced only while your application shows Action Required.
            </x-slot>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-zinc-200 text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:border-zinc-800 dark:text-zinc-400">
                            <th class="px-4 py-3">Requirement</th>
                            <th class="px-4 py-3">How to Submit</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Registrar Feedback / Instruction</th>
                            <th class="px-4 py-3">Latest File</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                        @forelse ($intake->checklistItems as $item)
                            @php($latestEvidence = $item->documentEvidence->sortByDesc('uploaded_at')->first())
                            <tr>
                                <td class="px-4 py-3 font-medium text-zinc-950 dark:text-white">
                                    {{ str($item->requirement_type)->replace('_', ' ')->title() }}
                                </td>
                                <td class="px-4 py-3 text-zinc-600 dark:text-zinc-400">
                                    {{ str($item->evidence_method)->replace('_', ' ')->title() }}
                                </td>
                                <td class="px-4 py-3">
                                    <x-filament::badge :color="match ($item->status) {
                                        \App\Models\ChecklistItem::StatusAccepted => 'success',
                                        \App\Models\ChecklistItem::StatusRejected => 'danger',
                                        \App\Models\ChecklistItem::StatusReceivedDigital, \App\Models\ChecklistItem::StatusReceivedPhysical => 'info',
                                        default => 'warning',
                                    }">
                                        {{ str($item->status)->replace('_', ' ')->title() }}
                                    </x-filament::badge>
                                </td>
                                <td class="px-4 py-3 text-zinc-600 dark:text-zinc-400">
                                    {{ $item->waiver_reason ?? $item->undertaking_terms ?? 'No additional instruction.' }}
                                </td>
                                <td class="px-4 py-3 text-zinc-600 dark:text-zinc-400">
                                    {{ $latestEvidence ? basename($latestEvidence->path) : 'No digital file' }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-6 text-center text-zinc-500">No requirements are recorded yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        @if ($intake->status === \App\Models\ApplicantIntake::StatusActionRequired)
            <form wire:submit="replaceEvidence" class="space-y-6">
                {{ $this->form }}

                <x-filament::button
                    type="submit"
                    icon="heroicon-m-arrow-up-tray"
                    wire:loading.attr="disabled"
                    wire:target="replaceEvidence"
                >
                    Submit Corrected Evidence
                </x-filament::button>
            </form>
        @endif
    @endif
</x-filament-panels::page>
