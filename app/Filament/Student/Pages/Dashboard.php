<?php

namespace App\Filament\Student\Pages;

use App\Filament\Student\Widgets\ActiveHoldsWidget;
use App\Filament\Student\Widgets\StudentPriorityNoticeWidget;
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
            StudentPriorityNoticeWidget::class,
            StudentProfileOverviewWidget::class,
            ActiveHoldsWidget::class,
        ];
    }
}
