<?php

namespace Tests\Feature;

use Tests\TestCase;

final class PwaScopeRemovalTest extends TestCase
{
    public function test_pwa_package_and_runtime_files_are_removed_from_scope(): void
    {
        $composerJson = file_get_contents(base_path('composer.json')) ?: '';
        $composerLock = file_get_contents(base_path('composer.lock')) ?: '';

        $this->assertStringNotContainsString('erag/laravel-pwa', $composerJson);
        $this->assertStringNotContainsString('erag/laravel-pwa', $composerLock);
        $this->assertStringNotContainsString('EragLaravelPwa', $composerLock);

        $this->assertFileDoesNotExist(config_path('pwa.php'));
        $this->assertFileDoesNotExist(public_path('manifest.json'));
        $this->assertFileDoesNotExist(public_path('offline.html'));
        $this->assertFileDoesNotExist(public_path('serviceworker.js'));
        $this->assertFileDoesNotExist(public_path('sw.js'));
    }

    public function test_no_active_blade_surface_registers_pwa_directives(): void
    {
        foreach ([
            resource_path('views/components/public-auth.blade.php'),
            resource_path('views/cor-verifications/show.blade.php'),
        ] as $viewPath) {
            $view = file_get_contents($viewPath) ?: '';

            $this->assertStringNotContainsString('@PwaHead', $view);
            $this->assertStringNotContainsString('@RegisterServiceWorkerScript', $view);
        }
    }
}
