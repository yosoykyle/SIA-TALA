@props(['summary', 'inset' => false])

<details {{ $attributes->class(['tala-supporting-details', 'tala-supporting-details--inset' => $inset]) }}>
    <summary>{{ $summary }}</summary>
    <div class="tala-supporting-details__content">{{ $slot }}</div>
</details>
