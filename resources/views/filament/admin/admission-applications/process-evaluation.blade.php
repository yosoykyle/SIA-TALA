@php
    $stateBadge = [
        'done' => ['Done', 'success'],
        'current' => ['In progress', 'warning'],
        'stopped' => ['Stopped', 'danger'],
        'expired' => ['Expired', 'warning'],
        'upcoming' => ['Up next', 'gray'],
    ];
@endphp
<div class="tala-process">
    <ol class="tala-process-steps" aria-label="Admission process, in order">
        @foreach ($steps as $step)
            @php
                [$badgeLabel, $badgeColor] = $stateBadge[$step['state']];
                $badgeLabel = $step['state'] === 'stopped' ? $step['stop_label'] : $badgeLabel;
                $isActive = in_array($step['state'], ['current', 'expired'], true);
            @endphp
            <li @class(['tala-process-step', 'is-'.$step['state']]) wire:key="process-step-{{ $step['key'] }}">
                <span class="tala-process-mark" aria-hidden="true">
                    @if ($step['state'] === 'done')
                        <x-filament::icon icon="heroicon-m-check" />
                    @elseif ($step['state'] === 'stopped')
                        <x-filament::icon icon="heroicon-m-x-mark" />
                    @elseif ($step['state'] === 'expired')
                        <x-filament::icon icon="heroicon-m-clock" />
                    @else
                        {{ $loop->iteration }}
                    @endif
                </span>

                <div class="tala-process-body">
                    <div class="tala-process-head">
                        <h3 class="tala-process-title">{{ $step['label'] }}</h3>
                        <x-filament::badge :color="$badgeColor" size="sm">{{ $badgeLabel }}</x-filament::badge>
                    </div>
                    <p class="tala-process-summary">{{ $step['summary'] }}</p>

                    @if (($step['documents'] ?? []) !== [])
                        <ul class="tala-process-documents" aria-label="Submitted documents for {{ $step['label'] }}">
                            @foreach ($step['documents'] as $document)
                                <li><span>{{ $document['label'] }}</span><x-filament::badge :color="$document['color']" size="sm">{{ $document['status'] }}</x-filament::badge></li>
                            @endforeach
                        </ul>
                    @endif

                    @if (filled($step['blocker']))
                        <p class="tala-process-blocker">{{ $step['blocker'] }}</p>
                    @endif

                    @if (($isActive || $step['persistent_action']) && ($step['choices'] ?? []) !== [])
                        <div class="tala-process-actions">
                            @foreach ($step['choices'] as $choice)
                                <x-filament::button size="sm" :color="$choice['color']" :icon="$choice['icon']" wire:click="mountAction('{{ $choice['action'] }}')">
                                    {{ $choice['label'] }}
                                </x-filament::button>
                            @endforeach
                        </div>
                    @elseif (($isActive || $step['persistent_action']) && $step['action'])
                        <div class="tala-process-actions">
                            <x-filament::button size="sm" wire:click="mountAction('{{ $step['action'] }}')">
                                {{ $step['action_label'] }}
                            </x-filament::button>
                        </div>
                    @endif

                    @if ($step['remarks'] !== [])
                        <ul class="tala-process-sent" aria-label="Remarks sent for {{ $step['label'] }}, newest first">
                            @foreach ($step['remarks'] as $remark)
                                <li>
                                    <p>{{ $remark['remark'] }}</p>
                                    <span>Sent by {{ $remark['by'] }} · {{ $remark['at'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </li>
        @endforeach
    </ol>
</div>
