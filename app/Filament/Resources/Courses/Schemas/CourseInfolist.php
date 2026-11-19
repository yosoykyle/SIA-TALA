<?php

namespace App\Filament\Resources\Courses\Schemas;

use App\Models\Course;
use App\Models\CourseSpecification;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

class CourseInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.admin.catalog.course-detail')
                ->viewData(function (Course $record, $livewire): array {
                    $record->loadMissing(['specifications.components', 'specifications.requirements.relatedCourse']);
                    $revisions = $record->specifications
                        ->sortBy([
                            fn (CourseSpecification $a, CourseSpecification $b): int => self::stateRank($a->state) <=> self::stateRank($b->state),
                            fn (CourseSpecification $a, CourseSpecification $b): int => $b->id <=> $a->id,
                        ])
                        ->values();

                    return [
                        'course' => $record,
                        'specification' => method_exists($livewire, 'selectedRevision') ? $livewire->selectedRevision() : self::primarySpecification($record),
                        'revisions' => $revisions,
                        'canManage' => auth()->user()?->can('update', $record) ?? false,
                        'oversightTermId' => property_exists($livewire, 'oversightTermId') ? $livewire->oversightTermId : null,
                    ];
                })
                ->columnSpanFull(),
        ]);
    }

    private static function stateRank(string $state): int
    {
        return match ($state) {
            CourseSpecification::StateActive => 1,
            CourseSpecification::StateDraft => 2,
            default => 3,
        };
    }

    private static function activeSpecification(Course $record): ?CourseSpecification
    {
        $record->loadMissing('specifications');

        return $record->specifications
            ->where('state', CourseSpecification::StateActive)
            ->sortByDesc('id')
            ->first();
    }

    private static function primarySpecification(Course $record): ?CourseSpecification
    {
        return self::activeSpecification($record)
            ?? $record->specifications->sortByDesc('id')->first();
    }
}
