<?php

namespace App\Filament\Components;

use Carbon\CarbonImmutable;
use Filament\Livewire\DatabaseNotifications;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;

/**
 * Shared panel database notifications component that automatically surfaces
 * newly arrived unread database notifications as native Filament floating toasts
 * while preserving the notification in the inbox drawer.
 */
class LiveDatabaseNotifications extends DatabaseNotifications
{
    private const MaxToastsPerPoll = 5;

    private const MaxRememberedIds = 50;

    public function mount(): void
    {
        $user = $this->getUser();

        if (! $user instanceof Model) {
            return;
        }

        $cursorKey = $this->cursorSessionKey($user);

        if (session()->has($cursorKey)) {
            return;
        }

        $now = CarbonImmutable::now();

        $recentIds = $this->getUnreadNotificationsQuery()
            ->where('created_at', '>=', $now->subSeconds(5))
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        session()->put($cursorKey, $now->toIso8601String());
        session()->put($this->toastedIdsSessionKey($user), array_slice($recentIds, -self::MaxRememberedIds));
    }

    public function render(): View
    {
        $this->syncLiveToasts();

        return parent::render();
    }

    private function syncLiveToasts(): void
    {
        $user = $this->getUser();

        if (! $user instanceof Model) {
            return;
        }

        $cursorKey = $this->cursorSessionKey($user);
        $idsKey = $this->toastedIdsSessionKey($user);

        if (! session()->has($cursorKey)) {
            $this->mount();

            return;
        }

        try {
            $cursor = CarbonImmutable::parse((string) session()->get($cursorKey));
        } catch (\Throwable) {
            $cursor = CarbonImmutable::now();
        }

        $replayFloor = CarbonImmutable::now()->subMinutes(2);
        if ($cursor->lessThan($replayFloor)) {
            $cursor = $replayFloor;
            session()->put($cursorKey, $cursor->toIso8601String());
        }

        /** @var array<int, string> $toastedIds */
        $toastedIds = array_values(array_filter(
            (array) session()->get($idsKey, []),
            static fn (mixed $id): bool => is_string($id) && $id !== '',
        ));

        $query = $this->getUnreadNotificationsQuery()
            ->where('created_at', '>=', $cursor)
            ->orderBy('created_at')
            ->limit(self::MaxToastsPerPoll);

        if ($toastedIds !== []) {
            $query->whereNotIn('id', $toastedIds);
        }

        /** @var Collection<int, DatabaseNotification> $fresh */
        $fresh = $query->get();

        if ($fresh->isEmpty()) {
            return;
        }

        $latestCursor = $cursor;

        foreach ($fresh as $record) {
            $recordId = (string) $record->getKey();
            $toastedIds[] = $recordId;

            if ($record->created_at !== null) {
                $createdAt = CarbonImmutable::instance($record->created_at);
                if ($createdAt->greaterThan($latestCursor)) {
                    $latestCursor = $createdAt;
                }
            }

            // Use a non-UUID toast ID so closing or auto-dismissing the floating toast
            // never triggers DatabaseNotifications::removeNotification() on the inbox record.
            Notification::fromDatabase($record)
                ->id('live-toast-'.$recordId)
                ->duration(6000)
                ->send();
        }

        session()->put($cursorKey, $latestCursor->toIso8601String());
        session()->put($idsKey, array_slice(array_values(array_unique($toastedIds)), -self::MaxRememberedIds));
    }

    private function cursorSessionKey(Model|Authenticatable $user): string
    {
        return 'tala.live_notifications_cursor.'.$user->getAuthIdentifier();
    }

    private function toastedIdsSessionKey(Model|Authenticatable $user): string
    {
        return 'tala.live_notifications_toasted_ids.'.$user->getAuthIdentifier();
    }
}
