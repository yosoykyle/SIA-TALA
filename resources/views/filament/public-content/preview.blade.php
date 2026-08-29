<div class="tala-guidance">
    <p><strong>Version {{ $record->version }} · {{ $record->publicationLabel() }}</strong></p>
    <h3>{{ $record->title ?? $record->question }}</h3>
    <p class="tala-content-message">{{ $record->message ?? $record->answer }}</p>
    @if ($record instanceof \App\Models\PublicNotice && $record->link_url)
        <p>{{ $record->link_label }} — {{ $record->link_url }}</p>
    @endif
    <dl class="tala-status-grid">
        <div><dt>Visible from (Asia/Manila)</dt><dd>{{ $record->visible_from?->timezone('Asia/Manila')->format('M j, Y, g:i A') ?? 'On publication' }}</dd></div>
        <div><dt>Visible until (Asia/Manila)</dt><dd>{{ $record->visible_until?->timezone('Asia/Manila')->format('M j, Y, g:i A') ?? 'Until unpublished or superseded' }}</dd></div>
        <div><dt>Responsible owner</dt><dd>System Administration</dd></div>
        <div><dt>Display position</dt><dd>{{ $record->display_order ?? $record->sort_order }}</dd></div>
    </dl>
    <p>Only the currently effective published version appears on the Public Gateway. Drafts stay private.</p>
</div>
