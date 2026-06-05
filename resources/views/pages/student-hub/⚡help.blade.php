<?php

use App\Models\FaqEntry;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.student')] class extends Component
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

<div>
    <h1 class="text-xl font-semibold text-zinc-900 dark:text-white">Help & FAQ</h1>
    <p class="mb-6 mt-2 text-base text-zinc-500 dark:text-zinc-400">
        Browse published school guidance before requesting staff assistance.
    </p>

    @forelse ($faqGroups as $category => $entries)
        <section class="mb-6 rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <h2 class="mb-4 text-lg font-semibold text-zinc-900 dark:text-white">
                {{ FaqEntry::categoryLabel($category) }}
            </h2>

            <div class="space-y-4">
                @foreach ($entries as $entry)
                    <article class="rounded-lg border border-zinc-100 p-4 dark:border-zinc-800">
                        <h3 class="font-medium text-zinc-900 dark:text-white">{{ $entry['question'] }}</h3>
                        <p class="mt-2 whitespace-pre-line text-sm leading-6 text-zinc-600 dark:text-zinc-300">{{ $entry['answer'] }}</p>
                    </article>
                @endforeach
            </div>
        </section>
    @empty
        <div class="rounded-xl border border-dashed border-zinc-300 bg-white p-6 text-center shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <p class="text-zinc-500 dark:text-zinc-400">No published FAQ entries are available yet.</p>
        </div>
    @endforelse
</div>
