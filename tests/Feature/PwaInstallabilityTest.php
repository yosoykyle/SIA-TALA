<?php

namespace Tests\Feature;

use JsonException;
use Tests\TestCase;

class PwaInstallabilityTest extends TestCase
{
    /**
     * @throws JsonException
     */
    public function test_manifest_is_tala_branded_and_installable(): void
    {
        $manifest = json_decode(
            file_get_contents(public_path('manifest.json')) ?: '',
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame('T.A.L.A. School Information System', $manifest['name']);
        $this->assertSame('T.A.L.A. SIS', $manifest['short_name']);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame('/', $manifest['start_url']);
        $this->assertSame('#0369a1', $manifest['theme_color']);
        $this->assertSame('#f4f4f5', $manifest['background_color']);
        $this->assertSame('talalogo.jpg', $manifest['icons'][0]['src']);
        $this->assertSame('512x512', $manifest['icons'][0]['sizes']);
    }

    public function test_public_surfaces_register_pwa_head_and_service_worker(): void
    {
        $welcome = file_get_contents(resource_path('views/welcome.blade.php')) ?: '';
        $publicLayout = file_get_contents(resource_path('views/layouts/public.blade.php')) ?: '';

        foreach ([$welcome, $publicLayout] as $surface) {
            $this->assertStringContainsString('@PwaHead', $surface);
            $this->assertStringContainsString('@RegisterServiceWorkerScript', $surface);
        }
    }

    public function test_service_worker_keeps_protected_student_data_out_of_precache(): void
    {
        $serviceWorker = file_get_contents(public_path('sw.js')) ?: '';

        $this->assertStringContainsString('const PRECACHE_URLS', $serviceWorker);
        $this->assertStringContainsString('OFFLINE_URL', $serviceWorker);
        $this->assertStringContainsString('/talalogo.jpg', $serviceWorker);
        $this->assertStringContainsString('request.method === "GET"', $serviceWorker);
        $this->assertStringContainsString('isProtectedRoute', $serviceWorker);
        $this->assertStringContainsString('"/student"', $serviceWorker);
        $this->assertStringContainsString('"/admin"', $serviceWorker);
        $this->assertStringContainsString('"/livewire"', $serviceWorker);
        $this->assertStringContainsString('event.respondWith(fetch(request));', $serviceWorker);
        $this->assertStringNotContainsString('/student/dashboard', $serviceWorker);
        $this->assertStringNotContainsString('/student/cor', $serviceWorker);
        $this->assertStringNotContainsString('/student/grades', $serviceWorker);
        $this->assertStringNotContainsString('student@tala.edu', $serviceWorker);
    }

    public function test_legacy_serviceworker_wrapper_uses_safe_current_worker(): void
    {
        $legacyWorker = file_get_contents(public_path('serviceworker.js')) ?: '';

        $this->assertSame("\"use strict\";\n\nimportScripts(\"/sw.js\");", trim($legacyWorker));
    }

    public function test_offline_fallback_is_static_and_does_not_expose_student_records(): void
    {
        $offline = file_get_contents(public_path('offline.html')) ?: '';

        $this->assertStringContainsString('T.A.L.A. SIS Offline', $offline);
        $this->assertStringContainsString('You are offline.', $offline);
        $this->assertStringContainsString('protected student-account pages are not intentionally stored for offline viewing', $offline);
        $this->assertStringNotContainsString('student@tala.edu', $offline);
        $this->assertStringNotContainsString('PHP 6500.00', $offline);
        $this->assertStringNotContainsString('BSIT-1A', $offline);
    }
}
