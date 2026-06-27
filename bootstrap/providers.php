<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\ApplicantPanelProvider;
use App\Providers\Filament\StudentPanelProvider;
use App\Providers\FortifyServiceProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    ApplicantPanelProvider::class,
    StudentPanelProvider::class,
    FortifyServiceProvider::class,
];
