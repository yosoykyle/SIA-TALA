@php
    $status = $record->publicationLabel();
    $isNotice = $record instanceof \App\Models\PublicNotice;
    $heading = filled($record->title ?? $record->question ?? null)
        ? (string) ($record->title ?? $record->question)
        : ($isNotice ? 'Untitled announcement' : 'Untitled question');
    $body = (string) ($record->message ?? $record->answer ?? '');
    $categoryLabel = ! $isNotice && filled($record->category ?? null)
        ? \App\Models\FaqEntry::categoryLabel($record->category)
        : null;
    $displayDate = ($record->published_at ?? $record->updated_at ?? now())
        ->timezone('Asia/Manila')
        ->format('M j, Y');
    $fromText = $record->visible_from?->timezone('Asia/Manila')->format('M j, Y, g:i A') ?? 'On publication';
    $untilText = $record->visible_until?->timezone('Asia/Manila')->format('M j, Y, g:i A') ?? 'Until unpublished';
@endphp

<div class="tala-lp-preview space-y-3">
    @if ($isNotice)
        <article class="tala-lp-bento" aria-label="Landing page announcement preview">
            <div class="tala-lp-bento__body">
                <div class="tala-lp-bento__header">
                    <h3 class="tala-lp-bento__title">School announcements</h3>
                    <span class="tala-lp-status-pill">
                        <span class="tala-lp-status-pill__dot" aria-hidden="true"></span>
                        <span>1 active</span>
                    </span>
                </div>

                <div class="tala-lp-notice-row">
                    <div class="tala-lp-notice-row__head">
                        <h4 class="tala-lp-notice-row__title tala-content-heading">{{ $heading }}</h4>
                        <time class="tala-lp-notice-row__date">{{ $displayDate }}</time>
                    </div>

                    @if (filled($body))
                        <p class="tala-lp-notice-row__message tala-content-message">{{ $body }}</p>
                    @else
                        <p class="tala-lp-notice-row__message italic opacity-70">Announcement message preview appears here.</p>
                    @endif

                    @if (filled($record->link_url ?? null))
                        <div class="tala-lp-notice-row__actions">
                            <a
                                class="tala-lp-notice-row__link tala-content-link"
                                href="{{ $record->link_url }}"
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                <span>{{ filled($record->link_label ?? null) ? $record->link_label : 'Learn more' }}</span>
                                <x-filament::icon icon="heroicon-o-arrow-top-right-on-square" class="h-4 w-4 shrink-0" aria-hidden="true" />
                            </a>
                        </div>
                    @endif
                </div>
            </div>

            <div class="tala-lp-bento__footer">
                <x-filament::icon icon="heroicon-o-megaphone" class="h-4 w-4 shrink-0" aria-hidden="true" />
                <span>Official advisories from Servitech</span>
            </div>
        </article>
    @else
        <div
            class="tala-lp-faq-item"
            x-data="{ open: true }"
            aria-label="Landing page FAQ preview"
        >
            <button
                type="button"
                class="tala-lp-faq-trigger"
                x-bind:class="{ 'is-open': open }"
                x-bind:aria-expanded="open.toString()"
                x-on:click="open = ! open"
            >
                <span class="tala-lp-faq-question">
                    @if ($categoryLabel)
                        <span class="tala-lp-faq-category">{{ $categoryLabel }}</span>
                    @endif
                    <span class="tala-lp-faq-heading tala-content-heading">{{ $heading }}</span>
                </span>
                <x-filament::icon
                    icon="heroicon-m-chevron-down"
                    class="tala-lp-faq-chevron h-5 w-5 shrink-0"
                    x-bind:class="{ 'is-open': open }"
                    aria-hidden="true"
                />
            </button>

            <div class="tala-lp-faq-body tala-content-message" x-show="open" x-transition.opacity.duration.150ms>
                @if (filled($body))
                    {{ $body }}
                @else
                    <span class="italic opacity-70">FAQ answer preview appears here.</span>
                @endif
            </div>
        </div>
    @endif

    <p class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 px-0.5 text-xs tabular-nums" style="color: var(--tala-muted);">
        <span>Status: <strong class="font-medium" style="color: var(--tala-text);">{{ $status }}</strong></span>
        <span>{{ $fromText }} to {{ $untilText }} (Asia/Manila)</span>
    </p>
</div>
