<?php

namespace App\Filament\Resources\Curriculums\Schemas;

use App\Models\Curriculum;
use App\Models\CurriculumReadinessScope;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class CurriculumInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('program.name')->label('Program'),
            TextEntry::make('version_name')->label('Version'),
            TextEntry::make('effective_year')->label('Effective Year'),
            IconEntry::make('is_active')->label('Active')->boolean(),
            TextEntry::make('activated_at')->dateTime()->placeholder('-'),
            TextEntry::make('subject_summary')
                ->label('Subjects')
                ->state(fn (Curriculum $record): string => $record->curriculumSubjects()
                    ->with('subject')
                    ->orderBy('year_level')
                    ->orderBy('semester')
                    ->orderBy('sort_order')
                    ->get()
                    ->map(fn ($curriculumSubject): string => collect([
                        $curriculumSubject->subject?->code,
                        $curriculumSubject->subject?->description,
                        $curriculumSubject->year_level,
                        $curriculumSubject->semester,
                    ])->filter()->implode(' | '))
                    ->filter()
                    ->implode("\n"))
                ->placeholder('-'),
            TextEntry::make('readiness_summary')
                ->label('Readiness Scopes')
                ->state(fn (Curriculum $record): string => $record->readinessScopes()
                    ->orderBy('year_level')
                    ->orderBy('curriculum_period')
                    ->get()
                    ->map(fn (CurriculumReadinessScope $scope): string => collect([
                        $scope->year_level,
                        $scope->curriculum_period,
                        CurriculumReadinessScope::statusOptions()[$scope->status] ?? $scope->status,
                        collect($scope->last_blockers ?? [])->implode('; '),
                    ])->filter()->implode(' | '))
                    ->filter()
                    ->implode("\n"))
                ->placeholder('-'),
            TextEntry::make('created_at')->dateTime()->placeholder('-'),
            TextEntry::make('updated_at')->dateTime()->placeholder('-'),
        ]);
    }
}
