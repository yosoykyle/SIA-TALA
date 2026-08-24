<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Choose workspace — TALA</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-50 text-slate-950">
    <main class="mx-auto flex min-h-screen max-w-3xl items-center px-4 py-10">
        <section class="w-full" aria-labelledby="chooser-heading">
            <img src="{{ asset('talalogo.png') }}" alt="TALA" class="mb-6 h-12 w-auto">
            <h1 id="chooser-heading" class="text-3xl font-semibold">Choose a workspace</h1>
            <p class="mt-2 text-slate-600">Only workspaces currently authorized for your account are shown.</p>
            <div class="mt-6 grid gap-3 sm:grid-cols-2">
                @foreach ($contexts as $key => $context)
                    <form method="POST" action="{{ route('workspace-chooser.select') }}">
                        @csrf
                        <input type="hidden" name="context" value="{{ $key }}">
                        <button class="min-h-24 w-full rounded-xl border border-slate-300 bg-white p-5 text-left shadow-sm hover:border-blue-600 hover:bg-blue-50 focus:outline-none focus:ring-2 focus:ring-blue-700 focus:ring-offset-2">
                            <span class="block text-lg font-semibold">{{ $context['label'] }}</span>
                            <span class="mt-1 block text-sm text-slate-600">Open this authorized context</span>
                        </button>
                    </form>
                @endforeach
            </div>
        </section>
    </main>
</body>
</html>
