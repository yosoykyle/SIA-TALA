<div class="tala-record-history">
    <h4 class="tala-history-sub">Admission decisions</h4>
    @if (count($decisions) === 0)
        <p class="tala-history-empty">No admission decision recorded. Review the current application before recording an outcome.</p>
    @else
        <ul class="tala-history-rows">
            @foreach ($decisions as $decision)
                <li>
                    <div class="tala-history-line">
                        <x-filament::badge :color="$decision['color']">{{ $decision['label'] }}</x-filament::badge>
                        <span class="tala-history-meta">{{ $decision['by'] }} · {{ $decision['at'] }}</span>
                        @if ($decision['superseded'])
                            <x-filament::badge color="gray">Superseded</x-filament::badge>
                        @endif
                        @if ($decision['earlier_source'])
                            <x-filament::badge color="warning">Earlier submission</x-filament::badge>
                        @endif
                    </div>
                    @if ($decision['replaces'] !== null || $decision['approval_reference'] !== null)
                        <p class="tala-history-extra">
                            @if ($decision['replaces'] !== null)<b>Replaces</b> {{ $decision['replaces'] }}.@endif
                            @if ($decision['approval_reference'] !== null)<b>Approval reference:</b> {{ $decision['approval_reference'] }}@endif
                        </p>
                    @endif
                    @if (filled($decision['basis']))
                        <p class="tala-history-extra"><b>Basis:</b> {{ $decision['basis'] }}</p>
                    @endif
                    @if ($decision['told_to_applicant'] !== null)
                        <p class="tala-history-extra"><b>Told to Applicant:</b> {{ $decision['told_to_applicant'] }}</p>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif

    <h4 class="tala-history-sub">Registrar enrollment clearance</h4>
    @if (count($clearances) === 0)
        <p class="tala-history-empty">No enrollment clearance recorded. Clearance follows an Admitted decision and the school's external checks.</p>
    @else
        <ul class="tala-history-rows">
            @foreach ($clearances as $clearance)
                <li>
                    <div class="tala-history-line">
                        <x-filament::badge :color="$clearance['color']">{{ $clearance['label'] }}</x-filament::badge>
                        <span class="tala-history-meta">{{ $clearance['by'] }} · {{ $clearance['at'] }}</span>
                        @if ($clearance['superseded'])
                            <x-filament::badge color="gray">Superseded</x-filament::badge>
                        @endif
                        @if ($clearance['earlier_source'])
                            <x-filament::badge color="warning">Earlier submission</x-filament::badge>
                        @endif
                    </div>
                    @if ($clearance['replaces'] !== null || $clearance['approval_reference'] !== null)
                        <p class="tala-history-extra">
                            @if ($clearance['replaces'] !== null)<b>Replaces</b> {{ $clearance['replaces'] }}.@endif
                            @if ($clearance['approval_reference'] !== null)<b>Supporting approval reference:</b> {{ $clearance['approval_reference'] }}@endif
                        </p>
                    @endif
                    @if (filled($clearance['basis']))
                        <p class="tala-history-extra"><b>Basis:</b> {{ $clearance['basis'] }}</p>
                    @endif
                    <p class="tala-history-extra"><b>Applicant instruction:</b> {{ $clearance['instruction'] }}</p>
                </li>
            @endforeach
        </ul>
    @endif
</div>
