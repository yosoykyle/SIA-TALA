<?php

namespace Tests\Feature;

use App\Models\FaqEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicFaqPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_public_faq_route_is_guest_accessible(): void
    {
        $this->get(route('faq'))
            ->assertOk()
            ->assertSeeText('Public Help Center')
            ->assertSeeText('No published FAQ entries yet');
    }

    public function test_public_faq_displays_only_published_entries(): void
    {
        FaqEntry::query()->create([
            'question' => 'How do I reset my password?',
            'answer' => 'Use the password reset link from the login page.',
            'category' => FaqEntry::CategoryAccountLogin,
            'sort_order' => 1,
            'is_published' => true,
        ]);

        FaqEntry::query()->create([
            'question' => 'Unpublished draft answer',
            'answer' => 'This staff draft must stay hidden.',
            'category' => FaqEntry::CategoryTechnicalSupport,
            'sort_order' => 1,
            'is_published' => false,
        ]);

        $this->get(route('faq'))
            ->assertOk()
            ->assertSeeText('Account / Login')
            ->assertSeeText('How do I reset my password?')
            ->assertSeeText('Use the password reset link from the login page.')
            ->assertDontSeeText('Unpublished draft answer')
            ->assertDontSeeText('This staff draft must stay hidden.');
    }

    public function test_public_faq_route_has_no_authenticated_student_middleware(): void
    {
        $route = app('router')->getRoutes()->getByName('faq');

        $this->assertNotNull($route);
        $this->assertSame(['web'], $route->gatherMiddleware());
    }
}
