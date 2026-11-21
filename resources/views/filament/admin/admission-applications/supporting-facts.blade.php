<div class="tala-supporting-facts">
    <div class="tala-facts-groups">
        <section class="tala-facts-group tala-task-surface" aria-labelledby="tala-facts-review">
            <h4 id="tala-facts-review"><x-filament::icon icon="heroicon-o-magnifying-glass" />Review</h4>
            <dl>
                <div>
                    <dt>Review started</dt>
                    @if ($review['started_by'] !== null)
                        <dd>{{ $review['started_by'] }}<small>{{ $review['started_at'] }}</small></dd>
                    @else
                        <dd>{{ $review['started_note'] }}</dd>
                    @endif
                </div>
                @if ($review['reviewing'] !== null)
                    <div><dt>Reviewing</dt><dd>{{ $review['reviewing'] }}</dd></div>
                @endif
                <div><dt>Form received</dt><dd>{{ $review['received'] }}</dd></div>
                <div><dt>Public closing</dt><dd>{{ $review['closing'] }}</dd></div>
                @if ($review['correction_due'] !== null)
                    <div><dt>Active correction due</dt><dd>{{ $review['correction_due'] }}</dd></div>
                @endif
            </dl>
        </section>

        <section class="tala-facts-group tala-task-surface" aria-labelledby="tala-facts-decision">
            <h4 id="tala-facts-decision"><x-filament::icon icon="heroicon-o-scale" />Decision</h4>
            <dl>
                <div><dt>Admission decision</dt><dd><x-filament::badge :color="$decision['color']">{{ $decision['label'] }}</x-filament::badge></dd></div>
                @if ($decision['decided_by'] !== null)
                    <div><dt>Decided by</dt><dd>{{ $decision['decided_by'] }}<small>{{ $decision['decided_at'] }}</small></dd></div>
                    <div><dt>Decided on</dt><dd>{{ $decision['source'] }}</dd></div>
                @endif
            </dl>
        </section>

        @if ($clearance !== null)
            <section class="tala-facts-group tala-task-surface" aria-labelledby="tala-facts-clearance">
                <h4 id="tala-facts-clearance"><x-filament::icon icon="heroicon-o-check-circle" />Clearance and readiness</h4>
                <dl>
                    <div><dt>Registrar clearance</dt><dd><x-filament::badge :color="$clearance['cleared'] ? 'success' : 'warning'">{{ $clearance['result'] }}</x-filament::badge></dd></div>
                    <div>
                        <dt>Enrollment readiness</dt>
                        <dd>
                            <x-filament::badge :color="$clearance['ready'] ? 'success' : 'warning'">{{ $clearance['ready'] ? 'Ready' : 'Not ready' }}</x-filament::badge>
                            <small>{{ $clearance['readiness_note'] }}</small>
                        </dd>
                    </div>
                </dl>
            </section>
        @endif
    </div>
    <p class="tala-facts-note">Times shown in {{ $timezone }}.</p>
</div>
