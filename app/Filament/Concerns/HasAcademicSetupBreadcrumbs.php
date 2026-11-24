<?php

namespace App\Filament\Concerns;

use App\Filament\Pages\CatalogCurriculaWorkbench;
use App\Filament\Pages\TermOverview;

/**
 * Provides the role-conditional root breadcrumb for academic setup resources.
 */
trait HasAcademicSetupBreadcrumbs
{
    use HasAcademicOversightReturn;

    /** @return array<string, string> */
    protected function academicSetupParentBreadcrumb(): array
    {
        if ($this->isAcademicHeadReader()) {
            return [$this->oversightReturnUrl() => 'Academic Oversight'];
        }

        return [TermOverview::getUrl() => 'Term Planning'];
    }

    /** @return array<string, string> */
    protected function catalogCurriculaBreadcrumb(?int $oversightTermId = null): array
    {
        $params = array_filter([
            'oversightTermId' => $oversightTermId,
        ], fn (mixed $value): bool => $value !== null);

        return [CatalogCurriculaWorkbench::getUrl($params) => 'Programs & Curricula'];
    }
}
