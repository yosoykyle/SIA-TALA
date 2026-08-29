<?php

namespace App\Actions\PublicContent;

use App\Models\FaqEntry;
use App\Models\PublicNotice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ManagePublicContent
{
    /** @param class-string<PublicNotice>|class-string<FaqEntry> $model */
    public function create(string $model, User $actor, array $data): PublicNotice|FaqEntry
    {
        Gate::forUser($actor)->authorize('create', $model);
        $data = $this->validate($model, $data);

        return DB::transaction(function () use ($model, $actor, $data): PublicNotice|FaqEntry {
            Gate::forUser($actor->fresh())->authorize('create', $model);
            $record = new $model($data);
            $record->created_by = $actor->id;
            $record->updated_by = $actor->id;
            $record->save();

            return $record;
        });
    }

    public function save(PublicNotice|FaqEntry $record, User $actor, array $data, int $expectedRevision): PublicNotice|FaqEntry
    {
        Gate::forUser($actor)->authorize('update', $record);
        $data = $this->validate($record::class, $data);

        return DB::transaction(function () use ($record, $actor, $data, $expectedRevision): PublicNotice|FaqEntry {
            $current = $record->newQuery()->lockForUpdate()->findOrFail($record->id);
            $this->assertCurrent($current, $actor, $expectedRevision);

            if ($current->wasPublished()) {
                if ($record->newQuery()->where('previous_version_id', $current->id)->exists()) {
                    throw ValidationException::withMessages(['revision' => 'A successor version already exists. Nothing changed. Review the latest saved version in Public Content before editing.']);
                }
                $successor = new ($record::class)($data);
                $successor->root_id = $current->rootIdentifier();
                $successor->previous_version_id = $current->id;
                $successor->version = $current->version + 1;
                $successor->created_by = $actor->id;
                $successor->updated_by = $actor->id;
                $successor->save();

                return $successor;
            }

            $current->fill($data);
            $current->revision++;
            $current->updated_by = $actor->id;
            $current->save();

            return $current;
        }, attempts: 5);
    }

    public function publish(PublicNotice|FaqEntry $record, User $actor, int $expectedRevision): PublicNotice|FaqEntry
    {
        Gate::forUser($actor)->authorize('update', $record);

        return DB::transaction(function () use ($record, $actor, $expectedRevision): PublicNotice|FaqEntry {
            // Lock this bounded content collection, including drafts, so competing lineages cannot reserve the same public position.
            $records = $this->lockedRecords($record);
            $current = $records[$record->id] ?? null;
            abort_unless($current instanceof PublicNotice || $current instanceof FaqEntry, 404);
            Gate::forUser($actor->fresh())->authorize('update', $current);

            if ($current->isPublished() && in_array($expectedRevision, [$current->revision, $current->revision - 1], true)) {
                return $current;
            }
            $this->assertCurrent($current, $actor, $expectedRevision);
            if ($current->wasPublished()) {
                throw ValidationException::withMessages(['revision' => 'This version has publication history. Save a successor draft before publishing again.']);
            }
            $this->validate($current::class, $current->only($current::contentFields()));
            $this->assertAvailableOrder($current, $records);

            $current->setAttribute($current::publicationColumn(), $current instanceof PublicNotice ? 'Published' : true);
            $current->ever_published = true;
            $current->published_at = now();
            $current->published_by = $actor->id;
            $current->updated_by = $actor->id;
            $current->revision++;
            $current->save();
            $this->recordPublication($current, $actor, 'published');

            return $current;
        }, attempts: 5);
    }

    public function unpublish(PublicNotice|FaqEntry $record, User $actor, int $expectedRevision): PublicNotice|FaqEntry
    {
        Gate::forUser($actor)->authorize('update', $record);

        return DB::transaction(function () use ($record, $actor, $expectedRevision): PublicNotice|FaqEntry {
            $versions = $this->lockedRecords($record);
            $current = $versions[$record->id] ?? null;
            abort_unless($current instanceof PublicNotice || $current instanceof FaqEntry, 404);
            Gate::forUser($actor->fresh())->authorize('update', $current);
            if (! $current->isPublished() && $current->wasPublished() && $current->revision === $expectedRevision + 1) {
                return $current;
            }
            $this->assertCurrent($current, $actor, $expectedRevision);
            if (collect($versions)->contains(fn ($version): bool => $version->rootIdentifier() === $current->rootIdentifier()
                && $version->version > $current->version && $version->wasPublished())) {
                throw ValidationException::withMessages(['revision' => 'A newer published version exists. Nothing changed. Review that version in Public Content before unpublishing.']);
            }
            if (! $current->isPublished()) {
                throw ValidationException::withMessages(['revision' => 'Only published content can be unpublished. Refresh Public Content to review its current state.']);
            }
            foreach ($versions as $version) {
                if ($version->rootIdentifier() !== $current->rootIdentifier() || ! $version->isPublished()) {
                    continue;
                }
                $version->setAttribute($version::publicationColumn(), $version instanceof PublicNotice ? 'Unpublished' : false);
                $version->ever_published = true;
                $version->revision++;
                $version->updated_by = $actor->id;
                $version->save();
                $this->recordPublication($version, $actor, 'unpublished');
            }

            return $current->fresh();
        }, attempts: 5);
    }

    private function assertCurrent(PublicNotice|FaqEntry $record, User $actor, int $expectedRevision): void
    {
        Gate::forUser($actor->fresh())->authorize('update', $record);
        if ($record->revision !== $expectedRevision) {
            throw ValidationException::withMessages(['revision' => 'This content changed after you opened it. Nothing was saved. Refresh Public Content and review the current version.']);
        }
    }

    public function orderSignature(PublicNotice|FaqEntry $record): string
    {
        return hash('sha256', $record->newQuery()->orderBy('id')->get()
            ->map(fn ($item): array => $item->only(['id', 'revision', 'version', 'root_id', 'state', 'is_published', 'category', 'display_order', 'sort_order', 'visible_from', 'visible_until']))
            ->toJson());
    }

    public function move(PublicNotice|FaqEntry $record, User $actor, string $direction, int $expectedRevision, string $expectedOrder): PublicNotice|FaqEntry
    {
        Gate::forUser($actor)->authorize('update', $record);
        if (! in_array($direction, ['up', 'down'], true)) {
            throw ValidationException::withMessages(['direction' => 'Choose Move up or Move down.']);
        }

        return DB::transaction(function () use ($record, $actor, $direction, $expectedRevision, $expectedOrder): PublicNotice|FaqEntry {
            $records = $this->lockedRecords($record);
            $current = $records[$record->id] ?? null;
            abort_unless($current instanceof PublicNotice || $current instanceof FaqEntry, 404);
            $this->assertCurrent($current, $actor, $expectedRevision);
            if (! hash_equals($this->orderSignature($current), $expectedOrder)) {
                throw ValidationException::withMessages(['revision' => 'Public Content changed after you opened this action. Nothing moved. Refresh and review the current order.']);
            }

            $column = $current instanceof PublicNotice ? 'display_order' : 'sort_order';
            if (! $current->wasPublished()) {
                return $this->save($current, $actor, array_replace($current->only($current::contentFields()), [
                    $column => $current->{$column} + ($direction === 'up' ? -1 : 1),
                ]), $current->revision);
            }

            if ($current->publicationLabel() !== 'Published') {
                throw ValidationException::withMessages(['revision' => 'Only current published content can move immediately. Create or edit its successor draft to change a scheduled or historical version.']);
            }
            $neighbor = $current->newQuery()->effective()
                ->when($current instanceof FaqEntry, fn ($query) => $query->where('category', $current->category))
                ->where($column, $direction === 'up' ? '<' : '>', $current->{$column})
                ->orderBy($column, $direction === 'up' ? 'desc' : 'asc')->orderBy('id')->first();
            if ($neighbor === null) {
                throw ValidationException::withMessages(['revision' => 'This content is already at the edge of its published group. Nothing moved. Edit a successor draft to choose another position.']);
            }

            $reorderedAt = now()->startOfSecond();

            $moved = $this->save($current, $actor, array_replace($current->only($current::contentFields()), [
                $column => $neighbor->{$column}, 'visible_from' => $reorderedAt->toDateTimeString(),
            ]), $current->revision);
            $swapped = $this->save($neighbor, $actor, array_replace($neighbor->only($neighbor::contentFields()), [
                $column => $current->{$column}, 'visible_from' => $reorderedAt->toDateTimeString(),
            ]), $neighbor->revision);

            foreach ([$moved, $swapped] as $successor) {
                $successor->setAttribute($successor::publicationColumn(), $successor instanceof PublicNotice ? 'Published' : true);
                $successor->ever_published = true;
                $successor->published_at = $reorderedAt;
                $successor->published_by = $actor->id;
                $successor->revision++;
                $successor->save();
                $records[$successor->id] = $successor;
                $this->recordPublication($successor, $actor, 'reordered');
            }
            $this->assertAvailableOrder($moved, $records);
            $this->assertAvailableOrder($swapped, $records);

            return $moved;
        }, attempts: 5);
    }

    /** @param class-string<PublicNotice>|class-string<FaqEntry> $model */
    private function validate(string $model, array $data): array
    {
        $rules = $model === PublicNotice::class ? [
            'title' => ['required', 'string', 'max:160'],
            'message' => ['required', 'string', 'max:500'],
            'display_order' => ['required', 'integer', 'min:1'],
            'link_label' => ['nullable', 'required_with:link_url', 'string', 'max:80'],
            'link_url' => ['nullable', 'required_with:link_label', 'string', 'max:2048', 'url:https', function (string $attribute, mixed $value, \Closure $fail): void {
                if (parse_url($value, PHP_URL_USER) !== null || parse_url($value, PHP_URL_PASS) !== null || preg_match('/[\\\\\x00-\x20]/', $value)) {
                    $fail('Use a safe HTTPS link without embedded credentials or control characters.');
                }
            }],
        ] : [
            'question' => ['required', 'string', 'max:160'],
            'answer' => ['required', 'string', 'max:3000'],
            'category' => ['required', Rule::in(array_keys(FaqEntry::categoryOptions()))],
            'sort_order' => ['required', 'integer', 'min:1'],
        ];
        $rules['visible_from'] = ['nullable', 'date'];
        $rules['visible_until'] = ['nullable', 'date', Rule::when(filled($data['visible_from'] ?? null), 'after_or_equal:visible_from')];

        return Validator::make($data, $rules)->validate();
    }

    /** @return array<int, PublicNotice|FaqEntry> */
    private function lockedRecords(PublicNotice|FaqEntry $record): array
    {
        $query = $record instanceof PublicNotice ? PublicNotice::query() : FaqEntry::query();

        return $query->orderBy('id')->lockForUpdate()->get()->keyBy('id')->all();
    }

    /** @param array<int, PublicNotice|FaqEntry> $records */
    private function assertAvailableOrder(PublicNotice|FaqEntry $draft, array $records): void
    {
        $orderColumn = $draft instanceof PublicNotice ? 'display_order' : 'sort_order';
        $start = $draft->visible_from ?? now();
        $end = $draft->visible_until;

        foreach ($records as $other) {
            if (! $other->isPublished() || $other->rootIdentifier() === $draft->rootIdentifier()
                || $other->{$orderColumn} !== $draft->{$orderColumn}
                || ($draft instanceof FaqEntry && $other->category !== $draft->category)) {
                continue;
            }
            $otherStart = $other->visible_from ?? $other->published_at ?? $other->created_at;
            $otherEnd = $other->visible_until;
            foreach ($records as $successor) {
                if ($successor->rootIdentifier() !== $other->rootIdentifier() || $successor->version <= $other->version || ! $successor->wasPublished()) {
                    continue;
                }
                $replacementAt = $successor->visible_from ?? $successor->published_at ?? now();
                if ($otherEnd === null || $replacementAt->lessThan($otherEnd)) {
                    $otherEnd = $replacementAt;
                }
            }
            if (($end === null || $otherStart->lessThan($end)) && ($otherEnd === null || $start->lessThan($otherEnd))) {
                throw ValidationException::withMessages([$orderColumn => 'This position is already published in an overlapping window. Choose another position or have System Administration revise the existing content first.']);
            }
        }
    }

    private function recordPublication(PublicNotice|FaqEntry $record, User $actor, string $event): void
    {
        activity('public_content')->performedOn($record)->causedBy($actor)->event($event)
            ->withProperties(['version' => $record->version, 'revision' => $record->revision, 'visible_from' => $record->visible_from?->toIso8601String(), 'visible_until' => $record->visible_until?->toIso8601String()])
            ->log('Public content '.$event);
    }
}
