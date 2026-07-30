<?php

namespace App\Filament\Pages;

use App\Filament\Resources\GradeRosters\GradeRosterResource;
use App\Filament\Resources\GraduationReviewBatches\GraduationReviewBatchResource;
use App\Models\User;
use Filament\Pages\Page;

class GradesAndCompletion extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?string $navigationLabel = 'Grades & Completion';

    protected static ?string $title = 'Grades & Completion';

    protected string $view = 'filament.pages.grades-and-completion';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole(User::StaffRoleRegistrar) ?? false;
    }

    /**
     * @return list<array{title: string, description: string, action: string, url: string, icon: string}>
     */
    public function workAreas(): array
    {
        return [
            [
                'title' => 'Grade Review and Release',
                'description' => 'Review submitted faculty rosters, return corrections, and complete authorized posting and release actions.',
                'action' => 'Open grade rosters',
                'url' => GradeRosterResource::getUrl('index'),
                'icon' => 'heroicon-o-document-check',
            ],
            [
                'title' => 'Completion Eligibility Review',
                'description' => 'Evaluate completion evidence, resolve blockers, and share a traceable result with the student. This review does not confer a degree.',
                'action' => 'Open eligibility reviews',
                'url' => GraduationReviewBatchResource::getUrl('index'),
                'icon' => 'heroicon-o-trophy',
            ],
        ];
    }
}
