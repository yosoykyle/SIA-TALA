<?php

namespace Tests\Feature;

use Tests\TestCase;

final class RemoveFilamentTourPluginTest extends TestCase
{
    public function test_admin_panel_does_not_register_the_filament_tour_plugin(): void
    {
        $provider = file_get_contents(app_path('Providers/Filament/AdminPanelProvider.php'));

        $this->assertStringNotContainsString('YacoubAlhaidari\\FilamentTour', $provider);
        $this->assertStringNotContainsString('FilamentTourPlugin', $provider);
        $this->assertStringNotContainsString('FilamentTourPlugin::make()', $provider);
    }

    public function test_filament_tour_package_is_removed_from_composer_manifests(): void
    {
        $composerJson = file_get_contents(base_path('composer.json'));
        $composerLock = file_get_contents(base_path('composer.lock'));

        $this->assertStringNotContainsString('yacoubalhaidari/filament-tour', $composerJson);
        $this->assertStringNotContainsString('yacoubalhaidari/filament-tour', $composerLock);
        $this->assertStringNotContainsString('YacoubAlhaidari\\FilamentTour', $composerLock);
    }

    public function test_filament_tour_package_is_not_discovered_by_laravel(): void
    {
        $packagesPath = base_path('bootstrap/cache/packages.php');

        $this->assertFileExists($packagesPath);

        $packages = file_get_contents($packagesPath);

        $this->assertStringNotContainsString('yacoubalhaidari/filament-tour', $packages);
        $this->assertStringNotContainsString('YacoubAlhaidari\\FilamentTour', $packages);
    }
}
