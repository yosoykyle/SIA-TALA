<?php

namespace App\Filament\Student\Pages;

use App\Filament\Student\Widgets\ActiveHoldsWidget;
use App\Filament\Student\Widgets\StudentProfileOverviewWidget;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    /**
     * @return array<class-string>
     */
    public function getWidgets(): array
    {
        return [
            StudentProfileOverviewWidget::class,
            ActiveHoldsWidget::class,
        ];
    }
}
