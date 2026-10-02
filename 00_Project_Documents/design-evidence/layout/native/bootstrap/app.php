<?php

use Illuminate\Foundation\Application;
use TalaPreview\PreviewPanelProvider;

$dependencyRoot = getenv('TALA_DEPENDENCY_ROOT') ?: 'C:/C SCHOOL/1st_SEM_Resources/Fundamentals_of_Research/GROUP/ACTIVITIES/SIA-TALA';
require $dependencyRoot.'/vendor/autoload.php';

spl_autoload_register(function (string $class): void {
    if (str_starts_with($class, 'TalaPreview\\')) {
        $path = dirname(__DIR__).'/app/'.str_replace('\\', '/', substr($class, strlen('TalaPreview\\'))).'.php';
        if (is_file($path)) {
            require $path;
        }
    }
});

return Application::configure(basePath: dirname(__DIR__))
    ->withProviders([
        BladeUI\Icons\BladeIconsServiceProvider::class,
        BladeUI\Heroicons\BladeHeroiconsServiceProvider::class,
        Filament\Support\SupportServiceProvider::class,
        Filament\Actions\ActionsServiceProvider::class,
        Filament\Notifications\NotificationsServiceProvider::class,
        Filament\Schemas\SchemasServiceProvider::class,
        Filament\Forms\FormsServiceProvider::class,
        Filament\Infolists\InfolistsServiceProvider::class,
        Filament\Tables\TablesServiceProvider::class,
        Filament\Widgets\WidgetsServiceProvider::class,
        Filament\FilamentServiceProvider::class,
        Livewire\LivewireServiceProvider::class,
        PreviewPanelProvider::class,
    ], withBootstrapProviders: false)
    ->withRouting(web: __DIR__.'/../routes/web.php')
    ->withMiddleware()
    ->withExceptions()
    ->create();
