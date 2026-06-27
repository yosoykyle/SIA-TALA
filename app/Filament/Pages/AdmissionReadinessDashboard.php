<?php

namespace App\Filament\Pages;

use App\Actions\Enrollment\AdmissionReadinessDashboardService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class AdmissionReadinessDashboard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Registrar';

    protected static ?string $navigationLabel = 'Admission Readiness';

    protected static ?int $navigationSort = 33;

    protected static ?string $title = 'Admission Readiness';

    protected string $view = 'filament.pages.admission-readiness-dashboard';

    public ?int $selectedTermId = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('manage-admission-setup') === true;
    }

    public function mount(): void
    {
        $data = app(AdmissionReadinessDashboardService::class)->evaluate();

        $this->selectedTermId = $data['selected_term_id'];
    }

    /**
     * @return array<string, mixed>
     */
    public function getReadinessProperty(): array
    {
        return app(AdmissionReadinessDashboardService::class)->evaluate($this->selectedTermId);
    }
}
