<x-tala-access-layout title="Choose workspace" heading-id="chooser-heading" :wide="true">
            <h1 id="chooser-heading" class="text-3xl font-semibold">Choose a workspace</h1>
            <p class="tala-access-muted mt-2">Only workspaces currently authorized for your account are shown.</p>
            <x-tala-context-entry-notice />
            @error('context')
                <p class="tala-access-alert mt-4" role="alert">{{ $message }} Review the current choices or ask a System Administrator to check your access.</p>
            @enderror
            @if (!empty($contexts))
                <div class="mt-6 grid gap-3 sm:grid-cols-2">
                    @foreach ($contexts as $key => $context)
                        <form method="POST" action="{{ route('workspace-chooser.select') }}">
                            @csrf
                            <input type="hidden" name="context" value="{{ $key }}">
                            <button type="submit" class="tala-workspace-choice">
                                <span class="block text-lg font-semibold">{{ $context['label'] }}</span>
                                <span class="tala-access-muted mt-1 block text-sm">Open this authorized context</span>
                            </button>
                        </form>
                    @endforeach
                </div>
            @else
                <div class="tala-workspace-empty-panel mt-6" role="status">
                    <h2 class="text-lg font-semibold">No workspace is currently authorized</h2>
                    <p class="mt-2 text-sm leading-relaxed">Your current account records provide no active workspace assignment. Ask a System Administrator to review your account access. System Administration manages account roles and workspace access; this selection screen cannot grant access permissions.</p>
                    <p class="mt-3 text-sm tala-access-muted">To request access, contact System Administration or use school support below. You may also sign out and switch accounts.</p>
                </div>
            @endif
            <form method="POST" action="{{ route('logout') }}" class="mt-5">
                @csrf
                <button type="submit" class="tala-access-text-button">Sign out</button>
            </form>
</x-tala-access-layout>
