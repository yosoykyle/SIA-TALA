<div class="space-y-4 text-sm">
    <div class="grid gap-3 sm:grid-cols-2">
        <div>
            <p class="font-medium text-zinc-950 dark:text-white">Applicant identity</p>
            <p class="text-zinc-600 dark:text-zinc-400">
                {{ $intake->first_name }} {{ $intake->middle_name }} {{ $intake->last_name }}<br>
                Born {{ $intake->birth_date?->format('F j, Y') }}
            </p>
        </div>
        <div>
            <p class="font-medium text-zinc-950 dark:text-white">Target student record</p>
            <p class="text-zinc-600 dark:text-zinc-400">
                {{ $intake->program?->name }}<br>
                {{ $intake->term?->label }}
            </p>
        </div>
    </div>

    <x-filament::callout type="info" icon="heroicon-m-information-circle">
        This action activates Student Hub access, carries forward eligible unresolved requirements, and starts the downstream enrollment record. It does not publish a class schedule or complete enrollment.
    </x-filament::callout>

    @if ($intake->admission_category === \App\Models\ApplicantIntake::AdmissionCategoryReturning)
        <div>
            <p class="font-medium text-zinc-950 dark:text-white">Returning-student comparison</p>
            <p class="text-zinc-600 dark:text-zinc-400">
                {{ count($candidates) }} active, unmerged profile(s) match the applicant's first name, last name, and birth date. The Registrar must explicitly choose whether to reuse one.
            </p>
        </div>
    @endif
</div>
