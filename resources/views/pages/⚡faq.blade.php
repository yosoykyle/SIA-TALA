<?php

use App\Models\FaqEntry;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.public')] class extends Component
{
    /**
     * @var array<string, list<array{question: string, answer: string}>>
     */
    public array $faqGroups = [];

    public function mount(): void
    {
        $this->faqGroups = FaqEntry::query()
            ->where('is_published', true)
            ->orderBy('category')
            ->orderBy('sort_order')
            ->orderBy('question')
            ->get(['question', 'answer', 'category'])
            ->groupBy('category')
            ->map(fn ($entries): array => $entries
                ->map(fn (FaqEntry $entry): array => [
                    'question' => $entry->question,
                    'answer' => $entry->answer,
                ])
                ->values()
                ->all())
            ->all();
    }
};
?>

<main class="relative isolate min-h-dvh overflow-hidden">
    <div class="absolute inset-0 -z-10 bg-[radial-gradient(circle_at_top_left,rgba(14,165,233,0.32),transparent_34%),linear-gradient(135deg,#020617_0%,#0f172a_48%,#1e293b_100%)]"></div>
    <div class="absolute right-0 top-0 -z-10 h-64 w-64 rounded-full bg-amber-300/20 blur-3xl"></div>

    <section class="mx-auto flex w-full max-w-6xl flex-col gap-10 px-6 py-10 sm:px-8 lg:py-16">
        <nav class="flex items-center justify-between gap-4">
            <a href="{{ route('home') }}" class="text-lg font-bold tracking-tight text-white">T.A.L.A.</a>
            <a href="{{ route('home') }}" class="rounded-full border border-white/15 px-4 py-2 text-sm font-medium text-slate-200 transition hover:border-sky-300 hover:text-white focus:outline-none focus:ring-2 focus:ring-sky-300">
                Back to Home
            </a>
        </nav>

        <header class="grid gap-8 lg:grid-cols-[1.1fr_0.9fr] lg:items-end">
            <div class="max-w-3xl">
                <p class="mb-4 inline-flex rounded-full border border-sky-300/30 bg-sky-300/10 px-3 py-1 text-sm font-medium text-sky-100">
                    Public Help Center
                </p>
                <h1 class="text-balance text-4xl font-black tracking-tight text-white sm:text-5xl lg:text-6xl">
                    Answers before you line up, email, or wait for office hours.
                </h1>
                <p class="mt-5 max-w-2xl text-base leading-8 text-slate-300 sm:text-lg">
                    Browse published guidance for admissions, payments, grades, accounts, and technical support.
                </p>
            </div>
            <div class="rounded-3xl border border-white/10 bg-white/10 p-6 shadow-2xl shadow-slate-950/40 backdrop-blur">
                <p class="text-sm font-semibold uppercase tracking-[0.3em] text-amber-200">Read-only scope</p>
                <p class="mt-3 text-sm leading-6 text-slate-200">
                    FAQ content is curated by the System Super Admin. Public users can only view published entries.
                </p>
            </div>
        </header>

        @forelse ($faqGroups as $category => $entries)
            <section class="rounded-3xl border border-white/10 bg-white/95 p-5 text-slate-950 shadow-2xl shadow-slate-950/20 sm:p-7">
                <div class="mb-5 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <h2 class="text-2xl font-black tracking-tight">{{ FaqEntry::categoryLabel($category) }}</h2>
                    <span class="rounded-full bg-slate-100 px-3 py-1 text-sm font-semibold text-slate-600">
                        {{ count($entries) }} {{ count($entries) === 1 ? 'entry' : 'entries' }}
                    </span>
                </div>

                <div class="grid gap-4">
                    @foreach ($entries as $index => $entry)
                        <article class="rounded-2xl border border-slate-200 bg-slate-50 p-5">
                            <h3 class="flex gap-3 text-lg font-bold text-slate-950">
                                <span class="mt-1 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-sky-100 text-xs font-black text-sky-700">
                                    {{ $index + 1 }}
                                </span>
                                <span>{{ $entry['question'] }}</span>
                            </h3>
                            <p class="mt-3 whitespace-pre-line pl-9 text-sm leading-7 text-slate-700">{{ $entry['answer'] }}</p>
                        </article>
                    @endforeach
                </div>
            </section>
        @empty
            <section class="rounded-3xl border border-dashed border-white/25 bg-white/10 p-8 text-center shadow-2xl shadow-slate-950/30">
                <h2 class="text-2xl font-black text-white">No published FAQ entries yet</h2>
                <p class="mx-auto mt-3 max-w-xl text-sm leading-7 text-slate-300">
                    The help center will show curated entries after the System Super Admin publishes them.
                </p>
            </section>
        @endforelse
    </section>
</main>
