@if ($notice = session()->pull(\App\Actions\Authentication\WorkspaceContextResolver::EntryNoticeSessionKey))
    <x-tala-access-alert variant="warning" title="Authorized workspace loaded" class="mb-4" role="status">
        <p>{{ $notice }}</p>
    </x-tala-access-alert>
@endif
