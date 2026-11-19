<?php

namespace Tests\Feature;

use App\Support\AuthBannerMedia;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AuthBannerMediaTest extends TestCase
{
    public function test_slides_discovers_all_supported_auth_banner_images_with_preferred_panel_image_first(): void
    {
        $adminSlides = AuthBannerMedia::slides('admin.webp');
        $this->assertGreaterThanOrEqual(3, count($adminSlides));
        $this->assertSame('admin.webp', $adminSlides[0]['filename']);
        $this->assertStringContainsString('images/auth/admin.webp?v=', $adminSlides[0]['url']);

        $studentSlides = AuthBannerMedia::slides('student.webp');
        $this->assertSame('student.webp', $studentSlides[0]['filename']);

        $applicantSlides = AuthBannerMedia::slides('applicant.webp');
        $this->assertSame('applicant.webp', $applicantSlides[0]['filename']);
    }

    public function test_slides_scans_custom_directory_for_all_supported_extensions_and_ignores_non_images(): void
    {
        $tempDir = storage_path('framework/testing/auth-banner-media-'.uniqid());
        File::ensureDirectoryExists($tempDir);

        try {
            File::put($tempDir.'/campus-2.jpg', 'jpg');
            File::put($tempDir.'/campus-10.png', 'png');
            File::put($tempDir.'/campus-1.WEBP', 'webp');
            File::put($tempDir.'/hero.avif', 'avif');
            File::put($tempDir.'/notes.txt', 'ignored');
            File::put($tempDir.'/.DS_Store', 'ignored');

            $slides = AuthBannerMedia::slides('campus-10.png', $tempDir);

            $this->assertSame(
                ['campus-10.png', 'campus-1.WEBP', 'campus-2.jpg', 'hero.avif'],
                array_column($slides, 'filename'),
            );
        } finally {
            File::deleteDirectory($tempDir);
        }
    }
}
