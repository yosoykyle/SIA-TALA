<?php

namespace App\Filament\Student\Pages;

use Filament\Pages\Page;
use Livewire\Attributes\Url;

class LifecycleView extends Page
{
    protected static ?string $navigationLabel = 'School record changes';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.student.pages.generic-table';

    #[Url]
    public ?int $change = null;

    public static function canAccess(): bool
    {
        return Academics::canAccess();
    }

    public function mount(): void
    {
        $this->redirect(Academics::getUrl(array_filter(['view' => 'record', 'change' => $this->change]), panel: 'student').'#school-record-changes', navigate: true);
        $this->skipRender();
    }
}
