<?php

namespace Tests\Feature;

use App\Actions\Applicants\AdmissionRequirementResolver;
use App\Models\AdmissionRequirementPolicy;
use App\Models\ApplicantIntake;
use App\Models\ChecklistItem;
use Database\Seeders\AdmissionRequirementPolicySeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TAL-93I: verifies the default-policy seeder that guarantees a fresh install can run
 * admissions. The seeder intentionally no-ops under the testing environment (so the
 * suite's "no active policy" expectations stay deterministic), so its baseline is
 * exercised here via seedBaseline() directly. Authority: PRD §13.1.1 rule 6, §3.1;
 * behaviour anchor: AdmissionRequirementResolver + ApplicantIntakeService::recordIdentityEvidence.
 */
final class TAL93IAdmissionRequirementPolicySeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * @var list<array{0: string, 1: string}>
     */
    private const NaturalPairs = [
        [ApplicantIntake::AdmissionCategoryFirstTimeCollege, ApplicantIntake::CredentialBasisSeniorHighSchool],
        [ApplicantIntake::AdmissionCategoryTransfer, ApplicantIntake::CredentialBasisTransferCredentials],
        [ApplicantIntake::AdmissionCategoryReturning, ApplicantIntake::CredentialBasisPriorStudentRecord],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
        $this->assertNotContains(DB::connection()->getDatabaseName(), [
            'tala_db',
            'demo_tala_db',
            'tala_test_codex',
        ]);
    }

    public function test_seeder_stays_inert_under_the_testing_environment(): void
    {
        $this->assertSame(0, AdmissionRequirementPolicy::query()->count());

        // run() is what DatabaseSeeder invokes; under 'testing' it must insert nothing
        // so that "no active policy matches" expectations elsewhere remain deterministic.
        (new AdmissionRequirementPolicySeeder)->run();

        $this->assertSame(0, AdmissionRequirementPolicy::query()->count());
    }

    public function test_seed_baseline_makes_a_fresh_database_submit_ready(): void
    {
        $seeder = new AdmissionRequirementPolicySeeder;

        $seeder->seedBaseline();
        // Idempotent: firstOrCreate on the (category, credential, requirement_type,
        // effective_from) unique key means a second run creates no duplicates.
        $seeder->seedBaseline();

        $this->assertSame(
            count(self::NaturalPairs) * 2,
            AdmissionRequirementPolicy::query()->count(),
            'The baseline must create exactly two requirements per natural pair, with no duplicates on re-run.',
        );

        $resolver = app(AdmissionRequirementResolver::class);

        foreach (self::NaturalPairs as [$category, $credential]) {
            // Every seeded pair must carry an ACTIVE digital-upload identity requirement,
            // which ApplicantIntakeService::recordIdentityEvidence() depends on.
            $this->assertDatabaseHas('admission_requirement_policies', [
                'admission_category' => $category,
                'credential_basis' => $credential,
                'requirement_type' => 'IDENTITY_DOCUMENT',
                'evidence_method' => ChecklistItem::EvidenceMethodDigitalUpload,
                'blocking_level' => ChecklistItem::BlockingHandover,
                'state' => AdmissionRequirementPolicy::StateActive,
            ]);

            // The resolver blocks submission when nothing matches; prove it now resolves
            // this pair and includes the digital-upload identity requirement.
            $intake = new ApplicantIntake([
                'admission_category' => $category,
                'credential_basis' => $credential,
            ]);

            $resolved = $resolver->resolve($intake);

            $this->assertTrue(
                $resolved->contains(fn (AdmissionRequirementPolicy $policy): bool => $policy->requirement_type === 'IDENTITY_DOCUMENT'
                    && $policy->evidence_method === ChecklistItem::EvidenceMethodDigitalUpload),
                "A fresh seeded database must let the {$category}/{$credential} pair submit (active digital-upload identity requirement present).",
            );
        }
    }
}
