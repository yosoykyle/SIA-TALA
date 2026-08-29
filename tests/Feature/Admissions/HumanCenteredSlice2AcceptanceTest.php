<?php

namespace Tests\Feature\Admissions;

use App\Actions\Admissions\AdmissionEvidenceService;
use App\Actions\Admissions\DiscardAdmissionApplication;
use App\Actions\Admissions\RequestAdmissionCorrection;
use App\Actions\Admissions\ResolveAdmissionRequirementSet;
use App\Actions\Admissions\SaveAdmissionApplication;
use App\Actions\Admissions\SubmitAdmissionApplication;
use App\Filament\Applicant\Pages\Application as ApplicantApplicationPage;
use App\Filament\Pages\AssistedAdmissionApplication;
use App\Models\AdmissionApplication;
use App\Models\AdmissionCycle;
use App\Models\AdmissionRequirement;
use App\Models\AdmissionRequirementSet;
use App\Models\ApplicantIntake;
use App\Models\ApplicationCorrectionItem;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class HumanCenteredSlice2AcceptanceTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('applicant', 'web');
        Role::findOrCreate(User::StaffRoleRegistrar, 'web')->givePermissionTo(
            Permission::findOrCreate('approve-documents', 'web'),
        );
    }

    public function test_effective_requirement_resolution_rejects_stale_submission_and_keeps_corrections_version_bound(): void
    {
        [$cycle, $program, $firstSet] = $this->openCycle();
        $applicant = $this->applicant();
        $draft = app(SaveAdmissionApplication::class)->execute($applicant, $cycle, $this->completeData($program));
        $resolver = app(ResolveAdmissionRequirementSet::class);

        $this->assertTrue($firstSet->is($resolver->forApplication($draft)));
        $futureSet = $this->publishedSet($cycle, 2, now()->addDay());
        $this->assertTrue($firstSet->is($resolver->forApplication($draft)));
        $effectiveSet = $this->publishedSet($cycle, 3, now()->subSecond());
        $this->assertTrue($effectiveSet->is($resolver->forApplication($draft)));

        try {
            app(SubmitAdmissionApplication::class)->execute($draft, $applicant, $firstSet->id);
            $this->fail('A stale page must not silently submit under a different Requirement Set.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('requirements', $exception->errors());
            $this->assertNull($draft->fresh()->current_submission_version_id);
        }

        $submitted = app(SubmitAdmissionApplication::class)->execute($draft, $applicant, $effectiveSet->id);
        app(RequestAdmissionCorrection::class)->execute(
            $submitted,
            $this->registrar(),
            [['type' => ApplicationCorrectionItem::ScopeField, 'key' => 'phone', 'admission_requirement_id' => null]],
            'Correct the mobile number.',
            'Applicant',
            now()->addHour(),
        );

        $this->assertTrue($effectiveSet->is($resolver->forApplication($submitted->fresh())));
        $this->assertFalse($futureSet->is($resolver->forApplication($submitted->fresh())));
    }

    public function test_evidence_correction_is_canonicalized_and_legacy_id_keys_still_allow_replacement_and_resubmission(): void
    {
        Storage::fake('local');
        [$cycle, $program, $set, $requirement] = $this->openCycle(withEvidence: true);
        $applicant = $this->applicant();
        $draft = app(SaveAdmissionApplication::class)->execute($applicant, $cycle, $this->completeData($program));
        app(AdmissionEvidenceService::class)->store(
            $draft,
            $requirement,
            $applicant,
            UploadedFile::fake()->image('initial.jpg', 10, 10)->size(20),
        );
        $submitted = app(SubmitAdmissionApplication::class)->execute($draft, $applicant, $set->id);
        $request = app(RequestAdmissionCorrection::class)->execute(
            $submitted,
            $this->registrar(),
            [[
                'type' => ApplicationCorrectionItem::ScopeEvidence,
                'key' => 'requirement:'.$requirement->id,
                'admission_requirement_id' => $requirement->id,
            ]],
            'Replace the named preliminary evidence.',
            'Applicant',
            now()->addHour(),
        );
        $item = $request->items()->sole();
        $this->assertSame($requirement->code, $item->scope_key);

        ApplicationCorrectionItem::withoutEvents(
            fn () => $item->forceFill(['scope_key' => 'requirement:'.$requirement->id])->save(),
        );
        $existing = $submitted->evidenceVersions()->latest('id')->firstOrFail();
        $replacement = app(AdmissionEvidenceService::class)->replace(
            $existing,
            $applicant,
            UploadedFile::fake()->image('corrected.jpg', 11, 11)->size(20),
        );
        $this->assertSame($existing->id, $replacement->replaces_document_evidence_id);

        $resubmitted = app(SubmitAdmissionApplication::class)->execute($submitted->fresh(), $applicant, $set->id);
        $this->assertSame(2, $resubmitted->currentSubmissionVersion->version);
        $this->assertSame($set->id, $resubmitted->currentSubmissionVersion->admission_requirement_set_id);
    }

    public function test_assisted_entry_is_attributable_applicant_owned_draft_only_and_unsupported_scope_creates_nothing(): void
    {
        [$cycle, $program] = $this->openCycle();
        $applicant = $this->applicant();
        $registrar = $this->registrar();
        $draft = app(SaveAdmissionApplication::class)->execute(
            $applicant,
            $cycle,
            $this->completeData($program),
            assistedBy: $registrar,
            assistanceReason: 'Applicant requested in-office form assistance.',
            assistanceAuthorityReference: 'REG-ASSIST-2026-001',
            assistanceEvidenceReference: 'FRONT-DESK-LOG-2026-001',
        );

        $this->assertSame($applicant->id, $draft->user_id);
        $this->assertNull($draft->application_reference);
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => AdmissionApplication::class,
            'subject_id' => $draft->id,
            'causer_id' => $registrar->id,
            'event' => 'admission_assisted_draft_saved',
        ]);
        $this->assertSame(0, StudentProfile::query()->where('user_id', $applicant->id)->count());

        try {
            app(SubmitAdmissionApplication::class)->execute($draft, $registrar);
            $this->fail('A Registrar must not submit the Applicant-owned Draft.');
        } catch (AuthorizationException) {
            $this->assertNull($draft->fresh()->current_submission_version_id);
        }

        app(DiscardAdmissionApplication::class)->execute($draft, $applicant, $registrar);
        $this->assertModelMissing($draft);

        try {
            app(SaveAdmissionApplication::class)->execute($applicant, $cycle, ['citizenship_country_code' => 'ZZ']);
            $this->fail('Unsupported scope must stop before an Application record is created.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('citizenship_country_code', $exception->errors());
            $this->assertStringContainsString(
                (string) config('institution.public.support_phone'),
                $exception->errors()['citizenship_country_code'][0],
            );
        }

        $this->assertSame(0, AdmissionApplication::query()->where('user_id', $applicant->id)->count());
    }

    public function test_native_wizard_announces_progress_and_assisted_page_withholds_submission(): void
    {
        $this->openCycle();
        $applicant = $this->applicant();
        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        Livewire::actingAs($applicant)
            ->test(ApplicantApplicationPage::class)
            ->assertSee('Step 1 of 5')
            ->assertSee('Step 5 of 5')
            ->assertSeeHtml('x-bind:aria-current=')
            ->assertSee('This Application has not been saved yet.');

        [$cycle, $program] = $this->openCycle();
        app(SaveAdmissionApplication::class)->execute(
            $applicant,
            $cycle,
            $this->completeData($program),
        );

        Livewire::actingAs($applicant)
            ->test(ApplicantApplicationPage::class)
            ->assertSeeHtml('startStep: 5');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::withQueryParams(['applicant' => $applicant->id])
            ->actingAs($this->registrar())
            ->test(AssistedAdmissionApplication::class)
            ->assertSee('Prepare the Applicant\'s Draft; do not submit for them', false)
            ->assertSee('Save Applicant Draft')
            ->assertDontSee('Submit application');
    }

    public function test_first_step_partial_draft_persists_without_premature_completion_requirements(): void
    {
        [$cycle, $program] = $this->openCycle();
        $applicant = $this->applicant();
        $draft = app(SaveAdmissionApplication::class)->execute($applicant, $cycle, [
            'application_path' => AdmissionApplication::PathFirstYear,
            'program_id' => $program->id,
            'credential_basis' => null,
            'first_name' => null,
            'last_name' => null,
            'privacy_acknowledged' => false,
            'accuracy_declared' => false,
        ]);

        $this->assertSame(AdmissionApplication::StateDraft, $draft->application_state);
        $this->assertSame($program->id, $draft->program_id);
        $this->assertNull($draft->credential_basis);
        $this->assertNull($draft->privacy_acknowledged_at);
        $this->assertNull($draft->accuracy_declared_at);

        Filament::setCurrentPanel(Filament::getPanel('applicant'));
        Livewire::actingAs($applicant)
            ->test(ApplicantApplicationPage::class)
            ->assertSeeHtml('startStep: 2')
            ->assertSee('Your persisted Application is up to date.');
    }

    public function test_scope_changes_bind_the_current_requirement_set_and_initialize_dynamic_evidence_state(): void
    {
        [$cycle, , $requirementSet, $requirement] = $this->openCycle(withEvidence: true);
        $applicant = $this->applicant();
        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        $component = Livewire::actingAs($applicant)
            ->test(ApplicantApplicationPage::class)
            ->set('data.admission_cycle_id', $cycle->id)
            ->set('data.application_path', AdmissionApplication::PathFirstYear);
        $state = $component->get('data');

        $this->assertSame($requirementSet->id, $state['requirement_set_id']);
        $this->assertArrayHasKey($requirement->id, $state['evidence']);
        $this->assertNull($state['evidence'][$requirement->id]);
    }

    private function applicant(): User
    {
        $applicant = User::factory()->create(['status' => User::StatusActive, 'email_verified_at' => now()]);
        $applicant->assignRole('applicant');

        return $applicant;
    }

    private function registrar(): User
    {
        $registrar = User::factory()->create(['status' => User::StatusActive]);
        $registrar->assignRole(User::StaffRoleRegistrar);

        return $registrar;
    }

    /** @return array{AdmissionCycle, Program, AdmissionRequirementSet, AdmissionRequirement} */
    private function openCycle(bool $withEvidence = false): array
    {
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $program = Program::factory()->create(['is_active' => true]);
        $cycle = AdmissionCycle::factory()->published()->create([
            'term_id' => $term->id,
            'opens_at' => now()->subDay(),
            'closes_at' => now()->addDay(),
            'correction_closes_at' => now()->addDays(2),
        ]);
        $cycle->programs()->attach($program, ['accepts_first_year' => true, 'accepts_transferee' => true]);
        $set = AdmissionRequirementSet::factory()->for($cycle)->create([
            'application_path' => AdmissionApplication::PathFirstYear,
            'version' => 1,
        ]);
        $requirement = AdmissionRequirement::factory()->for($set, 'requirementSet')->create([
            'requires_preliminary_evidence' => $withEvidence,
        ]);
        $set->update([
            'state' => AdmissionRequirementSet::StatePublished,
            'effective_at' => now()->subMinute(),
            'published_at' => now()->subMinute(),
        ]);

        return [$cycle, $program, $set, $requirement];
    }

    private function publishedSet(AdmissionCycle $cycle, int $version, mixed $effectiveAt): AdmissionRequirementSet
    {
        $set = AdmissionRequirementSet::factory()->for($cycle)->create([
            'application_path' => AdmissionApplication::PathFirstYear,
            'version' => $version,
        ]);
        AdmissionRequirement::factory()->for($set, 'requirementSet')->create(['requires_preliminary_evidence' => false]);
        $set->update([
            'state' => AdmissionRequirementSet::StatePublished,
            'effective_at' => $effectiveAt,
            'published_at' => now(),
        ]);

        return $set;
    }

    /** @return array<string, mixed> */
    private function completeData(Program $program): array
    {
        return [
            'program_id' => $program->id,
            'application_path' => AdmissionApplication::PathFirstYear,
            'credential_basis' => ApplicantIntake::CredentialBasisSeniorHighSchool,
            'first_name' => 'Alma',
            'last_name' => 'Applicant',
            'birth_date' => now()->subYears(20)->toDateString(),
            'citizenship_country_code' => 'PH',
            'phone' => '09477379208',
            'current_city_municipality' => 'Calamba',
            'current_province' => 'Laguna',
            'prior_school_name' => 'Synthetic Senior High School',
            'prior_school_country_code' => 'PH',
            'prior_school_completion_year' => now()->year - 1,
            'privacy_acknowledged' => true,
            'accuracy_declared' => true,
        ];
    }
}
