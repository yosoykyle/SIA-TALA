@php
    $status = (string) ($record['status'] ?? 'Recorded');
    $statusColor = match ($status) {
        'Recorded' => 'success',
        'Pending' => 'info',
        'Attention', 'Needs attention' => 'warning',
        default => 'gray',
    };
    $source = (string) ($record['source'] ?? 'Not recorded');
    $referenceId = (string) ($record['reference_id'] ?? 'Not recorded');
    $occurredAt = (string) ($record['occurred_at_display'] ?? 'Not recorded');
    $actorLabel = (string) ($record['actor_label'] ?? $record['actor'] ?? 'System');
    $actorRole = (string) ($record['actor_role_display'] ?? $record['actor_role'] ?? 'System');
    $affectedRecord = (string) ($record['affected_record'] ?? 'None');
    $summary = (string) ($record['summary'] ?? 'Not recorded');
@endphp

<div class="space-y-4">
    <div class="tala-task-surface rounded-xl p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0 space-y-2">
                <div class="flex flex-wrap items-center gap-2">
                    <x-filament::badge color="gray" size="sm">
                        {{ $source }}
                    </x-filament::badge>
                    <x-filament::badge :color="$statusColor" size="sm">
                        {{ $status }}
                    </x-filament::badge>
                </div>

                <h3 class="text-base font-semibold" style="color: var(--tala-text);">
                    {{ $record['type'] ?? 'Audit event' }}
                </h3>
            </div>

            <div class="shrink-0 text-left sm:text-right">
                <p class="text-[11px] font-medium uppercase tracking-wide" style="color: var(--tala-muted);">
                    Reference ID
                </p>
                <code
                    class="mt-1 inline-block rounded border px-2 py-0.5 font-mono text-xs tabular-nums"
                    style="border-color: var(--tala-edge); background: var(--tala-surface-subtle); color: var(--tala-text);"
                >
                    {{ $referenceId }}
                </code>
            </div>
        </div>
    </div>

    <dl class="tala-profile-inset grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div class="min-w-0">
            <dt class="text-xs font-medium" style="color: var(--tala-muted);">
                When (Asia/Manila)
            </dt>
            <dd class="mt-0.5 text-sm font-semibold tabular-nums" style="color: var(--tala-text);">
                {{ $occurredAt }}
            </dd>
        </div>

        <div class="min-w-0">
            <dt class="text-xs font-medium" style="color: var(--tala-muted);">
                Category
            </dt>
            <dd class="mt-0.5 text-sm font-semibold" style="color: var(--tala-text);">
                {{ $source }}
            </dd>
        </div>

        <div class="min-w-0">
            <dt class="text-xs font-medium" style="color: var(--tala-muted);">
                Who
            </dt>
            <dd class="mt-0.5 text-sm font-semibold break-words" style="color: var(--tala-text);">
                {{ $actorLabel }}
            </dd>
        </div>

        <div class="min-w-0">
            <dt class="text-xs font-medium" style="color: var(--tala-muted);">
                Role
            </dt>
            <dd class="mt-0.5 text-sm font-semibold" style="color: var(--tala-text);">
                {{ $actorRole }}
            </dd>
        </div>

        <div class="min-w-0 sm:col-span-2">
            <dt class="text-xs font-medium" style="color: var(--tala-muted);">
                Target record
            </dt>
            <dd class="mt-0.5 text-sm font-semibold break-words" style="color: var(--tala-text);">
                {{ $affectedRecord }}
            </dd>
        </div>
    </dl>

    <div
        class="rounded-xl border p-4"
        style="border-color: var(--tala-edge); background: var(--tala-surface-subtle);"
    >
        <p class="text-xs font-medium uppercase tracking-wide" style="color: var(--tala-muted);">
            Summary
        </p>
        <p class="mt-1.5 text-sm leading-relaxed break-words" style="color: var(--tala-text);">
            {{ $summary }}
        </p>
    </div>

    <p class="px-1 text-xs" style="color: var(--tala-muted);">
        Passwords, tokens, MFA secrets, and raw payloads are never recorded.
    </p>
</div>
