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
 * behaviour anchor: AdmissionRequirementResolver + policy-keyed digital evidence submission.
 */
final class TAL93IAdmissionRequirementPolicySeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * @var list<array{0: string, 1: string, 2: int}>
     */
    private const NaturalPairs = [
        [ApplicantIntake::AdmissionCategoryFirstTimeCollege, ApplicantIntake::CredentialBasisSeniorHighSchool, 4],
        [ApplicantIntake::AdmissionCategoryTransfer, ApplicantIntake::CredentialBasisTransferCredentials, 4],
        [ApplicantIntake::AdmissionCategoryReturning, ApplicantIntake::CredentialBasisPriorStudentRecord, 2],
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
            array_sum(array_column(self::NaturalPairs, 2)),
            AdmissionRequirementPolicy::query()->count(),
            'The baseline must create the exact mixed-evidence matrix, with no duplicates on re-run.',
        );

        $resolver = app(AdmissionRequirementResolver::class);

        foreach (self::NaturalPairs as [$category, $credential, $requirementCount]) {
            // Every seeded pair must carry an ACTIVE digital-upload identity requirement,
            // which the policy-keyed evidence submission path preserves for compatibility.
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

            $this->assertCount($requirementCount, $resolved);

            $this->assertTrue(
                $resolved->contains(fn (AdmissionRequirementPolicy $policy): bool => $policy->requirement_type === 'IDENTITY_DOCUMENT'
                    && $policy->evidence_method === ChecklistItem::EvidenceMethodDigitalUpload),
                "A fresh seeded database must let the {$category}/{$credential} pair submit (active digital-upload identity requirement present).",
            );
        }
    }
}
