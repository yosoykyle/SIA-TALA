<x-tala-access-layout title="Choose workspace" heading-id="chooser-heading" :wide="true">
            <h1 id="chooser-heading" class="text-3xl font-semibold">Choose a workspace</h1>
            <p class="tala-access-muted mt-2">Only workspaces currently authorized for your account are shown.</p>
            <x-tala-context-entry-notice />
            @error('context')
                <p class="tala-access-alert mt-4" role="alert">{{ $message }} Review the current choices or ask a System Administrator to check your access.</p>
            @enderror
            <div class="mt-6 grid gap-3 sm:grid-cols-2">
                @forelse ($contexts as $key => $context)
                    <form method="POST" action="{{ route('workspace-chooser.select') }}">
                        @csrf
                        <input type="hidden" name="context" value="{{ $key }}">
                        <button type="submit" class="tala-workspace-choice">
                            <span class="block text-lg font-semibold">{{ $context['label'] }}</span>
                            <span class="tala-access-muted mt-1 block text-sm">Open this authorized context</span>
                        </button>
                    </form>
                @empty
                    <div class="tala-access-alert" role="status">
                        <p class="font-semibold">No workspace is currently authorized</p>
                        <p>Your current access records provide no available workspace. Ask a System Administrator to review your account. This page cannot grant access.</p>
                    </div>
                @endforelse
            </div>
            <form method="POST" action="{{ route('logout') }}" class="mt-5">
                @csrf
                <button type="submit" class="tala-access-text-button">Sign out</button>
            </form>
</x-tala-access-layout>
