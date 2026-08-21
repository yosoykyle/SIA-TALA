<?php

namespace Tests\Feature;

use App\Actions\Enrollment\CurrentOfficialEnrollmentResolver;
use App\Models\CorVersion;
use App\Models\Enrollment;
use App\Models\StudentProfile;
use App\Models\Term;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

final class TAL96D3DOfficialEnrollmentCorConvergenceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', config('database.default'));
        $this->assertSame('test_tala_db', config('database.connections.mysql.database'));
    }

    public function test_resolver_selects_the_official_enrollment_in_the_active_term(): void
    {
        $profile = StudentProfile::factory()->create();
        $closedTerm = Term::factory()->create(['state' => Term::StateClosed]);
        $activeTerm = Term::factory()->create(['state' => Term::StateActive]);
        $historical = Enrollment::factory()->for($profile)->for($closedTerm)->create([
            'status' => 'officially_enrolled',
            'officially_enrolled_at' => now(),
        ]);
        $current = Enrollment::factory()->for($profile)->for($activeTerm)->create([
            'status' => 'officially_enrolled',
            'officially_enrolled_at' => now()->subDay(),
        ]);
        $this->makeCanonical($historical);
        $this->makeCanonical($current);

        $resolved = app(CurrentOfficialEnrollmentResolver::class)->forProfile($profile);

        $this->assertNotNull($resolved);
        $this->assertTrue($resolved->is($current));
        $this->assertFalse($resolved->is($historical));
    }

    public function test_resolver_returns_null_without_an_official_enrollment_in_the_active_term(): void
    {
        $profile = StudentProfile::factory()->create();
        $closedTerm = Term::factory()->create(['state' => Term::StateClosed]);
        $activeTerm = Term::factory()->create(['state' => Term::StateActive]);
        Enrollment::factory()->for($profile)->for($closedTerm)->create([
            'status' => 'officially_enrolled',
            'officially_enrolled_at' => now(),
        ]);
        Enrollment::factory()->for($profile)->for($activeTerm)->create([
            'status' => 'pending_payment',
            'officially_enrolled_at' => null,
        ]);

        $this->assertNull(app(CurrentOfficialEnrollmentResolver::class)->forProfile($profile));
    }

    public function test_resolver_is_deterministic_when_more_than_one_active_term_exists(): void
    {
        $profile = StudentProfile::factory()->create();
        $olderTerm = Term::factory()->create([
            'state' => Term::StateActive,
            'starts_on' => now()->subMonths(5)->toDateString(),
        ]);
        $newerTerm = Term::factory()->create([
            'state' => Term::StateActive,
            'starts_on' => now()->toDateString(),
        ]);
        $older = Enrollment::factory()->for($profile)->for($olderTerm)->create([
            'status' => 'officially_enrolled',
            'officially_enrolled_at' => now(),
        ]);
        $expected = Enrollment::factory()->for($profile)->for($newerTerm)->create([
            'status' => 'officially_enrolled',
            'officially_enrolled_at' => now()->subDay(),
        ]);
        $this->makeCanonical($older);
        $this->makeCanonical($expected);

        $resolved = app(CurrentOfficialEnrollmentResolver::class)->forProfile($profile);

        $this->assertNotNull($resolved);
        $this->assertTrue($resolved->is($expected));
    }

    private function makeCanonical(Enrollment $enrollment): void
    {
        $cor = CorVersion::factory()->for($enrollment)->create();
        $enrollment->update([
            'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
            'current_cor_version_id' => $cor->id,
        ]);
    }
}
