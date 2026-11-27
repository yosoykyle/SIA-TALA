<?php

namespace App\Filament\Support;

use App\Models\OfficialCredentialResult;
use Filament\Forms\Components\CheckboxList;
use Illuminate\Support\HtmlString;

/**
 * Checkbox fields for the admission paper checklist (#103 Scope 7): one checkbox per paper,
 * ticked papers become Passed and unticked ones stay To be followed.
 */
class AdmissionPaperFields
{
    /**
     * @param  array<string, array{label: string, items: list<array<string, mixed>>}>  $categories
     * @return list<CheckboxList>
     */
    public static function make(array $categories, bool $onlyFollowed = false): array
    {
        return collect($categories)
            ->map(fn (array $category): array => [
                ...$category,
                'items' => $onlyFollowed
                    ? array_values(array_filter($category['items'], fn (array $item): bool => $item['result'] !== OfficialCredentialResult::ResultVerified))
                    : $category['items'],
            ])
            ->filter(fn (array $category): bool => $category['items'] !== [])
            ->map(fn (array $category, string $key): CheckboxList => CheckboxList::make('checklist_'.$key)
                ->label($category['label'])
                ->options(collect($category['items'])->mapWithKeys(fn (array $item): array => [
                    $item['id'] => new HtmlString(e($item['label']).' <span class="tala-paper-status tala-paper-status--'.$item['tone'].'">'.e($item['status']).'</span>'),
                ])->all())
                ->descriptions(collect($category['items'])->mapWithKeys(fn (array $item): array => [$item['id'] => $item['due']])->all())
                ->default(collect($category['items'])->where('result', OfficialCredentialResult::ResultVerified)->pluck('id')->all())
                ->allowHtml()
                ->extraAttributes(['class' => 'tala-paper-checklist']))->values()->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<int>
     */
    public static function checkedIds(array $data): array
    {
        return collect($data)
            ->filter(fn (mixed $value, string $key): bool => str_starts_with($key, 'checklist_'))
            ->flatten()
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }
}
