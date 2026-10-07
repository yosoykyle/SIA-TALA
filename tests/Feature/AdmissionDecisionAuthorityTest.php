<?php

namespace Tests\Feature;

use App\Actions\Admissions\RecordAdmissionDecision;
use App\Filament\Resources\AdmissionApplications\Pages\ViewAdmissionApplication;
use App\Models\AdmissionApplication;
use App\Models\AdmissionApplicationEvent;
use App\Models\AdmissionCycle;
use App\Models\AdmissionDecision;
use App\Models\AdmissionRequirement;
use App\Models\AdmissionRequirementSet;
use App\Models\ApplicationSubmissionVersion;
use App\Models\DocumentEvidence;
use App\Models\IdentityMatchReview;
use App\Models\OperationalEvent;
use App\Models\PreliminaryEvidenceReview;
use App\Models\Term;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdmissionDecisionAuthorityTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('applicant', 'web');
        Role::findOrCreate(User::StaffRoleRegistrar, 'web')
            ->givePermissionTo(Permission::findOrCreate('approve-documents', 'web'));
    }

    #[DataProvider('routineDecisions')]
    public function test_routine_first_decision_uses_recorded_registrar_authority(string $outcome, ?string $reference): void
    {
        [$application, $registrar] = $this->application();
        $decision = app(RecordAdmissionDecision::class)->execute(
            $application, $registrar, $outcome, ' Private review basis ', $reference, ' Safe applicant explanation ',
        );

        $this->assertNull($decision->authority_reference);
        $this->assertSame($registrar->id, $decision->decided_by);
        $this->assertNotNull($decision->decided_at);
        $this->assertSame($application->current_submission_version_id, $decision->application_submission_version_id);
        $this->assertSame('Private review basis', $decision->reason);
        $this->assertSame('Safe applicant explanation', $decision->applicant_explanation);
        $this->assertSame($outcome, $application->fresh()->application_state);
        $event = $application->events()->where('event_type', AdmissionApplicationEvent::TypeDecisionRecorded)->sole();
        $this->assertSame($registrar->id, $event->actor_id);
        $this->assertFalse($event->payload['requires_external_approval']);
        $notice = OperationalEvent::query()->where('external_id', 'admissions:'.($outcome === AdmissionDecision::DecisionAdmitted
            ? OperationalEvent::TypeAdmissionApplicationAdmitted
            : OperationalEvent::TypeAdmissionApplicationNotAdmitted).':admission-decision:'.$decision->id)->sole();
        $this->assertSame('Safe applicant explanation', $notice->payload['applicant_explanation']);
        $this->assertArrayNotHasKey('reason', $notice->payload);
        $this->assertArrayNotHasKey('authority_reference', $notice->payload);
    }

    /** @return array<string, array{string, ?string}> */
    public static function routineDecisions(): array
    {
        return [
            'admitted without reference' => [AdmissionDecision::DecisionAdmitted, null],
            'admitted with whitespace' => [AdmissionDecision::DecisionAdmitted, '   '],
            'not admitted without reference' => [AdmissionDecision::DecisionNotAdmitted, null],
            'not admitted with whitespace' => [AdmissionDecision::DecisionNotAdmitted, '   '],
        ];
    }

    #[DataProvider('requiredReferences')]
    public function test_replacement_or_exception_missing_reference_changes_nothing(bool $replacement, ?string $reference): void
    {
        [$application, $registrar] = $this->application();
        $previous = $replacement ? app(RecordAdmissionDecision::class)->execute(
            $application, $registrar, AdmissionDecision::DecisionNotAdmitted, 'Original private basis', null, 'Original safe explanation',
        ) : null;
        $application->refresh();
        $state = $application->application_state;
        $decisions = $application->decisions()->count();
        $events = $application->events()->count();
        $notices = OperationalEvent::query()->where('related_record_type', AdmissionApplication::class)->where('related_record_id', $application->id)->count();

        try {
            app(RecordAdmissionDecision::class)->execute(
                $application, $registrar, AdmissionDecision::DecisionAdmitted, 'New private basis', $reference, 'New safe explanation',
                expectedCurrentDecisionId: $previous?->id,
                requiresExternalApproval: ! $replacement,
            );
            $this->fail('A separate approval reference must be required.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('authority_reference', $exception->errors());
        }

        $this->assertSame($state, $application->fresh()->application_state);
        $this->assertSame($decisions, $application->decisions()->count());
        $this->assertSame($events, $application->events()->count());
        $this->assertSame($notices, OperationalEvent::query()->where('related_record_type', AdmissionApplication::class)->where('related_record_id', $application->id)->count());
    }

    /** @return array<string, array{bool, ?string}> */
    public static function requiredReferences(): array
    {
        return [
            'replacement null' => [true, null],
            'replacement whitespace' => [true, '   '],
            'exception null' => [false, null],
            'exception whitespace' => [false, '   '],
        ];
    }

    public function test_exception_records_its_reference_and_classification(): void
    {
        [$application, $registrar] = $this->application();
        $decision = app(RecordAdmissionDecision::class)->execute(
            $application, $registrar, AdmissionDecision::DecisionAdmitted, 'Private exception basis', ' School approval 123 ', 'Safe explanation',
            requiresExternalApproval: true,
        );

        $this->assertSame('School approval 123', $decision->authority_reference);
        $event = $application->events()->where('event_type', AdmissionApplicationEvent::TypeDecisionRecorded)->sole();
        $this->assertTrue($event->payload['requires_external_approval']);
    }

    public function test_replacement_preserves_original_registrar_decision_without_inventing_a_reference(): void
    {
        [$application, $registrar] = $this->application();
        $previous = app(RecordAdmissionDecision::class)->execute(
            $application, $registrar, AdmissionDecision::DecisionNotAdmitted, 'Original basis', null, 'Original safe explanation',
        );
        $original = $previous->fresh()->getAttributes();
        $successor = app(RecordAdmissionDecision::class)->execute(
            $application->fresh(), $registrar, AdmissionDecision::DecisionAdmitted, 'Reconsidered basis', 'School reconsideration 456', 'Reconsidered safe explanation',
            expectedCurrentDecisionId: $previous->id,
        );

        $this->assertSame($original, $previous->fresh()->getAttributes());
        $this->assertSame($previous->id, $successor->supersedes_admission_decision_id);
        $this->assertSame($successor->id, $previous->successor->id);
        $this->assertSame(2, $application->decisions()->count());
    }

    #[DataProvider('admissionBlockers')]
    public function test_exception_reference_does_not_waive_admission_safeguards(bool $identityWarning): void
    {
        [$application, $registrar] = $this->application(acceptedCopy: $identityWarning);
        if ($identityWarning) {
            IdentityMatchReview::factory()->create(['admission_application_id' => $application->id]);
        }

        try {
            app(RecordAdmissionDecision::class)->execute(
                $application, $registrar, AdmissionDecision::DecisionAdmitted, 'Private basis', 'School exception 789', 'Safe explanation',
                requiresExternalApproval: true,
            );
            $this->fail('Exceptional approval must preserve admission safeguards.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($identityWarning ? 'identity_match' : 'preliminary_evidence', $exception->errors());
        }

        $this->assertSame(0, $application->decisions()->count());
        $this->assertSame(AdmissionApplication::StateSubmitted, $application->fresh()->application_state);
    }

    /** @return array<string, array{bool}> */
    public static function admissionBlockers(): array
    {
        return ['unresolved identity' => [true], 'unreviewed preliminary copy' => [false]];
    }

    public function test_not_admitted_can_record_its_basis_without_accepting_an_unreviewed_copy_or_separate_reference(): void
    {
        [$application, $registrar] = $this->application(acceptedCopy: false);
        $decision = app(RecordAdmissionDecision::class)->execute(
            $application, $registrar, AdmissionDecision::DecisionNotAdmitted, 'The required copy was unusable', null, 'Contact the Registrar about your review result',
        );

        $this->assertNull($decision->authority_reference);
        $this->assertSame(AdmissionApplication::StateNotAdmitted, $application->fresh()->application_state);
        $this->assertSame(0, PreliminaryEvidenceReview::query()->whereIn('document_evidence_id', $application->evidenceVersions()->pluck('id'))->count());
    }

    public function test_registrar_form_records_an_ordinary_first_decision_without_a_reference(): void
    {
        [$application, $registrar] = $this->application();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($registrar)->test(ViewAdmissionApplication::class, ['record' => $application->id])
            ->callAction('recordDecision', data: [
                'decision' => AdmissionDecision::DecisionAdmitted,
                'reason' => 'Private review basis',
                'applicant_explanation' => 'Safe explanation',
            ])
            ->assertHasNoActionErrors();

        $this->assertNull($application->decisions()->sole()->authority_reference);
        $this->assertSame(AdmissionApplication::StateAdmitted, $application->fresh()->application_state);
    }

    #[DataProvider('formReferenceRequirements')]
    public function test_registrar_form_requires_a_reference_for_replacement_or_exception(bool $replacement): void
    {
        [$application, $registrar] = $this->application();
        if ($replacement) {
            app(RecordAdmissionDecision::class)->execute(
                $application, $registrar, AdmissionDecision::DecisionNotAdmitted, 'Original basis', null, 'Original safe explanation',
            );
        }
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($registrar)->test(ViewAdmissionApplication::class, ['record' => $application->id])
            ->callAction('recordDecision', data: [
                'decision' => AdmissionDecision::DecisionAdmitted,
                'reason' => 'Private review basis',
                'applicant_explanation' => 'Safe explanation',
                'requires_external_approval' => ! $replacement,
            ])
            ->assertHasActionErrors(['authority_reference' => 'required']);

        $this->assertSame($replacement ? 1 : 0, $application->decisions()->count());
    }

    /** @return array<string, array{bool}> */
    public static function formReferenceRequirements(): array
    {
        return ['replacement' => [true], 'exception' => [false]];
    }

    /** @return array{AdmissionApplication, User} */
    private function application(bool $acceptedCopy = true): array
    {
        $registrar = User::factory()->create(['status' => User::StatusActive]);
        $registrar->assignRole(User::StaffRoleRegistrar);
        $term = Term::query()->first() ?? Term::factory()->create();
        $cycle = AdmissionCycle::factory()->published()->create([
            'term_id' => $term->id,
        ]);
        $application = AdmissionApplication::factory()->submitted()->create([
            'admission_cycle_id' => $cycle->id,
            'term_id' => $term->id,
        ]);
        $application->user->assignRole('applicant');
        $set = AdmissionRequirementSet::factory()->create([
            'admission_cycle_id' => $application->admission_cycle_id,
            'application_path' => $application->application_path,
        ]);
        $requirement = AdmissionRequirement::factory()->create([
            'admission_requirement_set_id' => $set->id,
            'requires_preliminary_evidence' => true,
            'due_stage' => AdmissionRequirement::DuePreliminaryReview,
        ]);
        $set->update(['state' => AdmissionRequirementSet::StatePublished, 'effective_at' => now(), 'published_at' => now(), 'published_by' => $registrar->id]);
        $version = ApplicationSubmissionVersion::factory()->create([
            'admission_application_id' => $application->id,
            'admission_requirement_set_id' => $set->id,
            'submitted_by' => $application->user_id,
        ]);
        $application->update(['current_submission_version_id' => $version->id]);
        $evidence = DocumentEvidence::factory()->canonical($application, $requirement, $version)->create();
        if ($acceptedCopy) {
            PreliminaryEvidenceReview::factory()->create([
                'document_evidence_id' => $evidence->id,
                'result' => PreliminaryEvidenceReview::ResultAccepted,
                'reviewed_by' => $registrar->id,
            ]);
        }

        return [$application->fresh(), $registrar];
    }
}
