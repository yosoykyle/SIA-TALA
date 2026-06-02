<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A basic example test provided by Laravel to ensure the application
 * returns a successful HTTP response.
 *
 * Steps / Test Cases:
 * 1. test_the_application_returns_a_successful_response
 */
class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }
}
