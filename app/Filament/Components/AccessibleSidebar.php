<?php

namespace App\Filament\Components;

use Filament\Livewire\Sidebar;
use Illuminate\Contracts\View\View;

class AccessibleSidebar extends Sidebar
{
    public function render(): View
    {
        return view('filament.components.accessible-sidebar');
    }
}
