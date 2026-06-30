<x-filament-panels::page>
    @if (! $this->snapshot || ! $this->projection)
        <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <h2 class="text-base font-semibold text-gray-950 dark:text-white">No completion review is visible yet</h2>
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">Your Registrar-visible completion review will appear here after it is released to the Student Hub.</p>
        </div>
    @else
        <div class="space-y-6">
            <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h2 class="text-base font-semibold text-gray-950 dark:text-white">Completion Review Status</h2>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Snapshot version {{ $this->snapshot->version }} generated {{ optional($this->snapshot->generated_at)->format('M d, Y g:i A') }}.</p>
                    </div>
                    <span class="inline-flex w-fit rounded-md bg-warning-50 px-2 py-1 text-sm font-medium text-warning-700 dark:bg-warning-400/10 dark:text-warning-300">
                        {{ $this->projection['result_status'] ?? $this->snapshot->result_status }}
                    </span>
                </div>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Remaining Requirements</h3>
                    <ul class="mt-3 space-y-2 text-sm text-gray-700 dark:text-gray-300">
                        @forelse (($this->projection['remaining_requirements'] ?? []) as $requirement)
                            <li>{{ $requirement }}</li>
                        @empty
                            <li>No remaining requirements listed.</li>
                        @endforelse
                    </ul>
                </div>

                <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Pending Grade or INC</h3>
                    <ul class="mt-3 space-y-2 text-sm text-gray-700 dark:text-gray-300">
                        @foreach (($this->projection['pending_grade_blockers'] ?? []) as $requirement)
                            <li>Pending Grade: {{ $requirement }}</li>
                        @endforeach
                        @foreach (($this->projection['inc_blockers'] ?? []) as $requirement)
                            <li>INC: {{ $requirement }}</li>
                        @endforeach
                        @if (blank($this->projection['pending_grade_blockers'] ?? []) && blank($this->projection['inc_blockers'] ?? []))
                            <li>No pending grade or INC blockers listed.</li>
                        @endif
                    </ul>
                </div>
            </div>

            <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Holds and Clearance</h3>
                <ul class="mt-3 space-y-2 text-sm text-gray-700 dark:text-gray-300">
                    @forelse (($this->projection['hold_or_clearance_labels'] ?? []) as $label)
                        <li>{{ $label }}</li>
                    @empty
                        <li>No hold or clearance blockers listed.</li>
                    @endforelse
                </ul>
                <dl class="mt-5 grid gap-3 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="font-medium text-gray-950 dark:text-white">Required Action</dt>
                        <dd class="mt-1 text-gray-700 dark:text-gray-300">{{ $this->projection['required_action'] ?? 'Please contact the Registrar' }}</dd>
                    </div>
                    <div>
                        <dt class="font-medium text-gray-950 dark:text-white">Office to Contact</dt>
                        <dd class="mt-1 text-gray-700 dark:text-gray-300">{{ $this->projection['office_to_contact'] ?? 'Registrar Office' }}</dd>
                    </div>
                </dl>
            </div>
        </div>
    @endif
</x-filament-panels::page>
