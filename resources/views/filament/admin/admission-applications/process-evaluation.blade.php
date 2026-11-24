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
                $remarkId = 'process-remark-'.$step['key'];
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

                    @if (filled($step['blocker']))
                        <p class="tala-process-blocker">{{ $step['blocker'] }}</p>
                    @endif

                    @if ($isActive && $canNotify)
                        <div class="tala-process-remark">
                            <label for="{{ $remarkId }}" class="tala-process-remark-label">Remarks for the Applicant</label>
                            <x-filament::input.wrapper class="fi-fo-textarea">
                                <textarea
                                    id="{{ $remarkId }}"
                                    rows="2"
                                    maxlength="1500"
                                    wire:model="processRemarks.{{ $step['key'] }}"
                                    placeholder="What is blocking this step, or what should the Applicant do next?"
                                ></textarea>
                            </x-filament::input.wrapper>
                            <div class="tala-process-actions">
                                @if ($step['action'])
                                    <x-filament::button size="sm" wire:click="mountAction('{{ $step['action'] }}')">
                                        {{ $step['action_label'] }}
                                    </x-filament::button>
                                @endif
                                <x-filament::button
                                    size="sm"
                                    color="warning"
                                    outlined
                                    icon="heroicon-m-paper-airplane"
                                    wire:click="mountAction('sendProcessRemark', { step: '{{ $step['key'] }}' })"
                                >
                                    Notify Applicant
                                </x-filament::button>
                            </div>
                        </div>
                    @elseif ($isActive && $step['action'])
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
