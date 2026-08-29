<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use LogicException;

trait HasPublicContentVersions
{
    protected static function bootHasPublicContentVersions(): void
    {
        static::updating(function (self $record): void {
            $original = $record->newInstance();
            $original->setRawAttributes($record->getRawOriginal());
            if ($original->wasPublished() && $record->isDirty(static::contentFields())) {
                throw new LogicException('Published content is immutable. Save a successor draft.');
            }
        });

        static::deleting(function (self $record): void {
            if ($record->wasPublished() || static::query()->where('previous_version_id', $record->id)->exists()) {
                throw new LogicException('Published or referenced content must be retained.');
            }
        });
    }

    public function wasPublished(): bool
    {
        return (bool) $this->ever_published || $this->isPublished();
    }

    public function isPublished(): bool
    {
        return $this->getAttribute(static::publicationColumn()) === (static::publicationColumn() === 'state' ? 'Published' : true);
    }

    public function rootIdentifier(): int
    {
        return (int) ($this->root_id ?? $this->id);
    }

    public function scopeEffective(Builder $query): Builder
    {
        $table = $this->getTable();
        $time = now();

        return $query
            ->where($table.'.'.static::publicationColumn(), static::publicationColumn() === 'state' ? 'Published' : true)
            ->where(fn (Builder $window): Builder => $window->whereNull($table.'.visible_from')->orWhere($table.'.visible_from', '<=', $time))
            ->where(fn (Builder $window): Builder => $window->whereNull($table.'.visible_until')->orWhere($table.'.visible_until', '>', $time))
            ->whereNotExists(function (QueryBuilder $newer) use ($table, $time): void {
                $newer->selectRaw('1')->from($table.' as successor')
                    ->whereRaw('COALESCE(successor.root_id, successor.id) = COALESCE('.$table.'.root_id, '.$table.'.id)')
                    ->whereColumn('successor.version', '>', $table.'.version')
                    ->where('successor.ever_published', true)
                    ->where(function (QueryBuilder $window) use ($time): void {
                        $window->whereNull('successor.visible_from')->orWhere('successor.visible_from', '<=', $time)
                            ->orWhere('successor.'.static::publicationColumn(), static::publicationColumn() === 'state' ? 'Unpublished' : false);
                    });
            });
    }

    public function publicationLabel(): string
    {
        if (! $this->isPublished()) {
            return $this->wasPublished() ? 'Unpublished' : 'Draft';
        }
        if (static::query()->where('root_id', $this->rootIdentifier())->where('version', '>', $this->version)
            ->where('ever_published', true)
            ->where(fn (Builder $query): Builder => $query->whereNull('visible_from')->orWhere('visible_from', '<=', now()))->exists()) {
            return 'Superseded';
        }
        if ($this->visible_until?->lessThanOrEqualTo(now())) {
            return 'Expired';
        }

        return $this->visible_from?->isFuture() ? 'Scheduled' : 'Published';
    }
}
