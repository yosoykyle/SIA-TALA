<?php

namespace App\Filament\Student\Pages;

use Filament\Pages\Page;

class GradesView extends Page
{
    protected static ?string $navigationLabel = 'Grades';

    protected static bool $shouldRegisterNavigation = false;

    public static function canAccess(): bool
    {
        return Academics::canAccess();
    }

    public function mount(): void
    {
        $this->redirect(Academics::getUrl(['view' => 'record'], panel: 'student'), navigate: true);
        $this->skipRender();
    }
}
