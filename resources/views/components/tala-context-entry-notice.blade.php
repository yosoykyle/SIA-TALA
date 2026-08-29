@if ($notice = session()->pull(\App\Actions\Authentication\WorkspaceContextResolver::EntryNoticeSessionKey))
    <p class="tala-access-alert" role="status">{{ $notice }}</p>
@endif
