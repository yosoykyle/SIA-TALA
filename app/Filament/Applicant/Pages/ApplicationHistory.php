<?php

namespace App\Filament\Applicant\Pages;

use Illuminate\Contracts\View\View;

/**
 * Applicant's own application details, receipts and history, reached from the account menu (#61 F97).
 *
 * Reuses Home's current-application, readiness and applications-table logic so history stays reachable
 * (PRD 02, Blueprint APP-004, #64 AC7) without crowding Applicant Home.
 */
class ApplicationHistory extends Dashboard
{
    protected static string $routePath = '/history';

    protected static ?string $slug = 'history';

    protected string $view = 'filament.applicant.pages.application-history';

    public static function getNavigationLabel(): string
    {
        return "Applicant's History";
    }

    public function getTitle(): string
    {
        return "Applicant's History";
    }

    public function getHeading(): string
    {
        return "Applicant's History";
    }

    public function getSubheading(): string
    {
        return 'Your application details, receipts and what has happened so far.';
    }

    public function getHeader(): ?View
    {
        return null;
    }

    /**
     * @return array<class-string>
     */
    public function getWidgets(): array
    {
        return [];
    }
}
