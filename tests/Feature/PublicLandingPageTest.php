<?php

namespace Tests\Feature;

use Tests\TestCase;

class PublicLandingPageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_public_landing_page_renders_tala_portal_without_protected_student_data(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSeeText('T.A.L.A. SIS')
            ->assertSeeText('School Information System')
            ->assertSeeText('View admissions flow')
            ->assertSeeText('Applicant intake')
            ->assertSeeText('Registrar review')
            ->assertSeeText('Protected records')
            ->assertDontSeeText('student@tala.edu')
            ->assertDontSeeText('Student Hub')
            ->assertDontSeeText('Document request')
            ->assertDontSeeText('Credential request')
            ->assertDontSeeText('Courier');
    }
}
