<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PublicSurfaceCleanupTest extends TestCase
{
    public function test_old_public_content_pages_are_removed_from_active_scope(): void
    {
        $this->assertFalse(Route::has('faq'));
        $this->assertFileDoesNotExist(resource_path('views/welcome.blade.php'));
        $this->assertFileDoesNotExist(resource_path('views/layouts/public.blade.php'));
        $this->assertFileDoesNotExist(resource_path('views/pages/⚡faq.blade.php'));
    }

    public function test_root_route_redirects_to_sign_in_instead_of_rendering_public_content(): void
    {
        $this->get(route('home'))
            ->assertRedirect('/login');
    }
}
