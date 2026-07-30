<?php

namespace App\Filament\Student\Pages;

use Filament\Pages\Page;

class Academics extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?string $navigationLabel = 'Academics';

    protected static ?string $title = 'Academics';

    protected string $view = 'filament.student.pages.academics';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('student') ?? false;
    }

    /**
     * @return list<array{title: string, description: string, action: string, url: string, icon: string}>
     */
    public function academicAreas(): array
    {
        return [
            [
                'title' => 'Class Schedule',
                'description' => 'View the published meeting time, delivery mode, faculty member, and room for each enrolled subject.',
                'action' => 'View class schedule',
                'url' => ScheduleView::getUrl(panel: 'student'),
                'icon' => 'heroicon-o-calendar-days',
            ],
            [
                'title' => 'Released Grades',
                'description' => 'Review grades that the Registrar has officially released to the Student Hub.',
                'action' => 'View released grades',
                'url' => GradesView::getUrl(panel: 'student'),
                'icon' => 'heroicon-o-document-check',
            ],
            [
                'title' => 'Academic Status and Holds',
                'description' => 'Understand your confirmed standing, recorded lifecycle history, and any hold that affects academic work.',
                'action' => 'View academic status',
                'url' => LifecycleView::getUrl(panel: 'student'),
                'icon' => 'heroicon-o-identification',
            ],
            [
                'title' => 'Holds and Blockers',
                'description' => 'See the office, reason, and next step for any active hold that affects enrollment, records, or outputs.',
                'action' => 'View holds and blockers',
                'url' => HoldsView::getUrl(panel: 'student'),
                'icon' => 'heroicon-o-exclamation-triangle',
            ],
            [
                'title' => 'Completion Eligibility Review',
                'description' => 'See the latest eligibility result shared by the Registrar, the evidence behind it, and your next step. This does not confer a degree.',
                'action' => 'View eligibility review',
                'url' => Completion::getUrl(panel: 'student'),
                'icon' => 'heroicon-o-trophy',
            ],
        ];
    }
}
