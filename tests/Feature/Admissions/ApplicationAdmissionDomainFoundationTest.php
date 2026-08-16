<?php

namespace Tests\Feature\Admissions;

use App\Models\AdmissionApplication;
use App\Models\AdmissionApplicationEvent;
use App\Models\AdmissionCycle;
use App\Models\AdmissionCycleEvent;
use App\Models\AdmissionDecision;
use App\Models\AdmissionRequirement;
use App\Models\AdmissionRequirementSet;
use App\Models\ApplicantIntake;
use App\Models\ApplicationCorrectionItem;
use App\Models\ApplicationCorrectionRequest;
use App\Models\ApplicationSubmissionVersion;
use App\Models\DocumentEvidence;
use App\Models\IdentityMatchReview;
use App\Models\OfficialCredentialResult;
use App\Models\PreliminaryEvidenceReview;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

class ApplicationAdmissionDomainFoundationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_canonical_applications_share_the_existing_table_without_reinterpreting_legacy_rows(): void
    {
        $legacyIntake = ApplicantIntake::factory()->create();

        $this->assertNull($legacyIntake->application_state);
        $this->assertFalse(
            AdmissionApplication::query()->canonical()->whereKey($legacyIntake->id)->exists(),
        );

        $application = AdmissionApplication::factory()->create();

        $this->assertSame('applicant_intakes', $application->getTable());
        $this->assertSame(AdmissionApplication::StateDraft, $application->application_state);
        $this->assertTrue(AdmissionApplication::query()->canonical()->whereKey($application->id)->exists());
        $this->assertSame($application->admissionCycle->term_id, $application->term_id);
    }

    public function test_cycles_and_published_requirement_versions_have_restrictive_versioned_foundations(): void
    {
        $cycle = AdmissionCycle::factory()->published()->create();
        $cycleEvent = AdmissionCycleEvent::factory()->for($cycle)->create();
        $requirementSet = AdmissionRequirementSet::factory()->for($cycle)->create();
        $requirement = AdmissionRequirement::factory()->for($requirementSet, 'requirementSet')->create();
        $requirementSet->update([
            'state' => AdmissionRequirementSet::StatePublished,
            'effective_at' => now(),
            'published_at' => now(),
        ]);

        $this->assertCount(1, $cycle->events);
        $this->assertCount(1, $cycle->requirementSets);
        $this->assertCount(1, $requirementSet->requirements);
        $this->assertTrue($requirement->requirementSet->is($requirementSet));

        try {
            $requirementSet->update(['authority_reference' => 'Changed authority']);
            $this->fail('A published requirement set must be immutable.');
        } catch (LogicException) {
            $this->assertSame('Synthetic Registrar authority', $requirementSet->fresh()->authority_reference);
        }

        $this->expectException(LogicException::class);
        $cycleEvent->update(['reason' => 'Rewritten history']);
    }

    public function test_application_history_relationships_are_append_only_and_use_fixed_vocabulary(): void
    {
        $application = AdmissionApplication::factory()->submitted()->create();
        $requirementSet = AdmissionRequirementSet::factory()
            ->for($application->admissionCycle)
            ->create(['application_path' => $application->application_path]);
        $requirement = AdmissionRequirement::factory()->for($requirementSet, 'requirementSet')->create();
        $requirementSet->update([
            'state' => AdmissionRequirementSet::StatePublished,
            'effective_at' => now(),
            'published_at' => now(),
        ]);
        $submission = ApplicationSubmissionVersion::factory()
            ->for($application, 'application')
            ->for($requirementSet, 'requirementSet')
            ->create();
        $correction = ApplicationCorrectionRequest::factory()->for($application, 'application')->create();
        ApplicationCorrectionItem::factory()->for($correction, 'correctionRequest')->create([
            'admission_requirement_id' => $requirement->id,
        ]);
        $decision = AdmissionDecision::factory()->for($application, 'application')->admitted()->create();
        AdmissionDecision::factory()
            ->for($application, 'application')
            ->notAdmitted()
            ->create(['supersedes_admission_decision_id' => $decision->id]);
        $credentialResult = OfficialCredentialResult::factory()
            ->for($application, 'application')
            ->for($requirement, 'requirement')
            ->verified()
            ->create();
        OfficialCredentialResult::factory()
            ->for($application, 'application')
            ->for($requirement, 'requirement')
            ->actionNeeded()
            ->create(['supersedes_official_credential_result_id' => $credentialResult->id]);
        IdentityMatchReview::factory()->for($application, 'application')->create();
        AdmissionApplicationEvent::factory()->for($application, 'application')->create();

        $application->forceFill(['current_submission_version_id' => $submission->id])->save();
        $application->refresh();

        $this->assertTrue($application->currentSubmissionVersion->is($submission));
        $this->assertCount(1, $application->submissionVersions);
        $this->assertCount(1, $application->correctionRequests);
        $this->assertCount(2, $application->decisions);
        $this->assertCount(2, $application->credentialResults);
        $this->assertCount(1, $application->identityMatchReviews);
        $this->assertCount(1, $application->events);
        $this->assertTrue($decision->successor->supersedes($decision));
        $this->assertTrue($credentialResult->successor->supersedes($credentialResult));

        try {
            $decision->update(['reason' => 'Rewritten decision']);
            $this->fail('Admission decisions must be append-only.');
        } catch (LogicException) {
            $this->assertNotSame('Rewritten decision', $decision->fresh()->reason);
        }

        $this->expectException(InvalidArgumentException::class);
        AdmissionApplicationEvent::factory()->create([
            'admission_application_id' => $application->id,
            'event_type' => 'ArbitraryState',
        ]);
    }

    public function test_document_evidence_supports_canonical_versions_without_breaking_legacy_checklists(): void
    {
        $legacyEvidence = DocumentEvidence::factory()->create();
        $application = AdmissionApplication::factory()->submitted()->create();
        $requirementSet = AdmissionRequirementSet::factory()
            ->for($application->admissionCycle)
            ->create(['application_path' => $application->application_path]);
        $requirement = AdmissionRequirement::factory()->for($requirementSet, 'requirementSet')->create();
        $requirementSet->update([
            'state' => AdmissionRequirementSet::StatePublished,
            'effective_at' => now(),
            'published_at' => now(),
        ]);
        $submission = ApplicationSubmissionVersion::factory()
            ->for($application, 'application')
            ->for($requirementSet, 'requirementSet')
            ->create();
        $canonicalEvidence = DocumentEvidence::factory()
            ->canonical($application, $requirement, $submission)
            ->create();
        PreliminaryEvidenceReview::factory()->for($canonicalEvidence, 'documentEvidence')->create();

        $this->assertNotNull($legacyEvidence->checklist_item_id);
        $this->assertNull($legacyEvidence->admission_application_id);
        $this->assertNull($canonicalEvidence->checklist_item_id);
        $this->assertTrue($canonicalEvidence->admissionApplication->is($application));
        $this->assertTrue($canonicalEvidence->admissionRequirement->is($requirement));
        $this->assertTrue($canonicalEvidence->applicationSubmissionVersion->is($submission));
        $this->assertCount(1, $canonicalEvidence->preliminaryReviews);

        $this->expectException(QueryException::class);
        DocumentEvidence::factory()
            ->canonical($application, $requirement, $submission)
            ->create(['checksum' => $canonicalEvidence->checksum]);
    }
}
