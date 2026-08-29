<?php

namespace Tests\Feature\Admissions;

use App\Actions\Admissions\AdmissionNotificationLedger;
use App\Filament\Applicant\Pages\Application as ApplicationPage;
use App\Filament\Applicant\Pages\Dashboard as ApplicantDashboard;
use App\Filament\Resources\AdmissionApplications\AdmissionApplicationResource;
use App\Filament\Resources\AdmissionCycles\AdmissionCycleResource;
use App\Filament\Resources\AdmissionRequirementPolicies\AdmissionRequirementPolicyResource;
use App\Filament\Resources\ApplicantIntakes\ApplicantIntakeResource;
use App\Models\AdmissionApplication;
use App\Models\AdmissionCycle;
use App\Models\AdmissionRequirement;
use App\Models\AdmissionRequirementSet;
use App\Models\ApplicationSubmissionVersion;
use App\Models\OperationalEvent;
use App\Models\OutputAccessLog;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\View;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ApplicationToReadinessJourneyTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('test_tala_db', config('database.connections.mysql.database'));
        Role::findOrCreate('applicant', 'web');
        Role::findOrCreate(User::StaffRoleRegistrar, 'web')->givePermissionTo(
            Permission::findOrCreate('approve-documents', 'web'),
        );
    }

    public function test_applicant_account_status_is_independent_from_application_progress(): void
    {
        $applicant = User::factory()->create([
            'status' => User::StatusActive,
        ]);
        $applicant->syncRoles(['applicant']);

        $this->assertSame([
            User::StatusActive => 'Active',
        ], User::applicantWorkspaceStatusOptions());
        $this->assertTrue($applicant->canAuthenticate());
    }

    public function test_legacy_intake_and_generic_requirement_policy_resources_are_not_registered(): void
    {
        $resources = Filament::getPanel('admin')->getResources();

        $this->assertNotContains(ApplicantIntakeResource::class, $resources);
        $this->assertNotContains(AdmissionRequirementPolicyResource::class, $resources);
        $this->assertContains(AdmissionApplicationResource::class, $resources);
        $this->assertContains(AdmissionCycleResource::class, $resources);
    }

    public function test_five_step_applicant_page_submits_one_versioned_application_without_creating_student_records(): void
    {
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $program = Program::factory()->create(['is_active' => true]);
        $cycle = AdmissionCycle::factory()->for($term)->published()->create([
            'opens_at' => now()->subDay(),
            'closes_at' => now()->addDay(),
        ]);
        $cycle->programs()->attach($program->id, [
            'accepts_first_year' => true,
            'accepts_transferee' => false,
        ]);
        $set = AdmissionRequirementSet::factory()->create([
            'admission_cycle_id' => $cycle->id,
            'application_path' => AdmissionApplication::PathFirstYear,
        ]);
        $set->update([
            'state' => AdmissionRequirementSet::StatePublished,
            'effective_at' => now()->subMinute(),
            'published_at' => now()->subMinute(),
        ]);
        $applicant = User::factory()->create([
            'status' => User::StatusActive,
            'email_verified_at' => now(),
        ]);
        $applicant->assignRole('applicant');
        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        Livewire::actingAs($applicant)
            ->test(ApplicationPage::class)
            ->fillForm([
                'admission_cycle_id' => $cycle->id,
                'application_path' => AdmissionApplication::PathFirstYear,
                'program_id' => $program->id,
                'first_name' => 'Alma',
                'middle_name' => null,
                'last_name' => 'Applicant',
                'extension_name' => null,
                'birth_date' => '2005-01-02',
                'citizenship_country_code' => 'PH',
                'phone' => '09171234567',
                'current_city_municipality' => 'Synthetic City',
                'current_province' => 'Laguna',
                'prior_school_name' => 'Synthetic Senior High School',
                'prior_school_country_code' => 'PH',
                'credential_basis' => AdmissionApplication::CredentialSeniorHighSchool,
                'prior_school_completion_year' => 2025,
                'privacy_acknowledged' => true,
                'accuracy_declared' => true,
            ])
            ->call('submitApplication')
            ->assertHasNoFormErrors();

        $application = AdmissionApplication::query()->where('user_id', $applicant->id)->sole();
        $this->assertSame(AdmissionApplication::StateSubmitted, $application->application_state);
        $this->assertSame(1, $application->submissionVersions()->count());
        $this->assertSame(0, StudentProfile::query()->where('user_id', $applicant->id)->count());
    }

    public function test_applicant_can_retry_only_a_failed_admissions_update_without_changing_application_state(): void
    {
        Mail::fake();
        $applicant = User::factory()->create([
            'status' => User::StatusActive,
            'email_verified_at' => now(),
        ]);
        $applicant->assignRole('applicant');
        $application = AdmissionApplication::factory()->submitted()->create([
            'user_id' => $applicant->id,
            'email' => $applicant->email,
        ]);
        $ledger = app(AdmissionNotificationLedger::class);
        $event = $ledger->recordPending(
            $application,
            $applicant,
            OperationalEvent::TypeAdmissionApplicationSubmitted,
            'submission:ui-resend-'.$application->id,
            [
                'application_reference' => $application->application_reference,
                'submitted_at' => now()->toIso8601String(),
            ],
        );
        $ledger->markFailed($event, 'Synthetic delivery failure.');
        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        Livewire::actingAs($applicant)
            ->test(ApplicantDashboard::class)
            ->assertActionVisible('resendFailedNotification')
            ->callAction('resendFailedNotification')
            ->assertNotified('Admissions update queued again');

        $this->assertSame(AdmissionApplication::StateSubmitted, $application->fresh()->application_state);
        $this->assertSame(OperationalEvent::StatusPending, $event->fresh()->status);
        Mail::assertQueuedCount(1);
    }

    public function test_version_bound_acknowledgment_is_owner_only_and_records_access(): void
    {
        $applicant = User::factory()->create([
            'status' => User::StatusActive,
        ]);
        $applicant->syncRoles(['applicant']);
        $application = AdmissionApplication::factory()->submitted()->create([
            'user_id' => $applicant->id,
            'email' => $applicant->email,
            'application_reference' => 'APP-2026-0001',
        ]);
        $set = AdmissionRequirementSet::factory()->create([
            'admission_cycle_id' => $application->admission_cycle_id,
            'application_path' => $application->application_path,
        ]);
        AdmissionRequirement::factory()->create([
            'admission_requirement_set_id' => $set->id,
            'label' => 'Form 138 or equivalent',
        ]);
        $set->update([
            'state' => AdmissionRequirementSet::StatePublished,
            'effective_at' => now()->subMinute(),
            'published_at' => now()->subMinute(),
        ]);
        $version = ApplicationSubmissionVersion::factory()->create([
            'admission_application_id' => $application->id,
            'admission_requirement_set_id' => $set->id,
            'submitted_by' => $applicant->id,
            'snapshot' => [
                'application_reference' => 'APP-2026-0001',
                'first_name' => 'Alma',
                'last_name' => 'Adult',
                'admission_cycle_id' => $application->admission_cycle_id,
                'admission_cycle' => ['label' => 'Captured Cycle', 'code' => 'CAPTURED-2026'],
                'term_id' => $application->term_id,
                'term' => ['label' => 'Captured Term'],
                'program_id' => $application->program_id,
                'program' => ['name' => 'Captured Program'],
                'application_path' => AdmissionApplication::PathFirstYear,
                'prior_school_name' => 'Synthetic Senior High School',
            ],
        ]);
        $application->update([
            'current_submission_version_id' => $version->id,
            'application_path' => AdmissionApplication::PathTransferee,
        ]);

        $this->actingAs($applicant)
            ->get(route('admissions.application.acknowledgment', [
                'application' => $application,
                'version' => $version,
            ]))
            ->assertOk()
            ->assertSee('APPLICATION ACKNOWLEDGMENT')
            ->assertSee('APP-2026-0001')
            ->assertSee('Captured Cycle')
            ->assertSee('Captured Program')
            ->assertSee('First Year')
            ->assertSee(asset('css/tala-application-acknowledgment.css'), false)
            ->assertSee('href="'.route('filament.applicant.pages.dashboard').'"', false)
            ->assertSee('not an admission certificate');

        $printCss = file_get_contents(public_path('css/tala-application-acknowledgment.css'));
        $this->assertIsString($printCss);
        $this->assertStringContainsString('size: A4 portrait', $printCss);
        $this->assertStringContainsString('margin: 12mm', $printCss);
        $this->assertStringContainsString('counter(page)', $printCss);
        $this->assertStringContainsString('table-header-group', $printCss);

        $this->assertDatabaseHas(OutputAccessLog::class, [
            'output_type' => 'application.acknowledgment',
            'source_record_id' => $application->id,
            'actor_user_id' => $applicant->id,
            'status' => 'generated',
        ]);

        $otherApplicant = User::factory()->create(['status' => User::StatusActive]);
        $otherApplicant->syncRoles(['applicant']);

        $this->actingAs($otherApplicant)
            ->get(route('admissions.application.acknowledgment', [
                'application' => $application,
                'version' => $version,
            ]))
            ->assertNotFound();

        $otherApplication = AdmissionApplication::factory()->submitted()->create();
        $otherSet = AdmissionRequirementSet::factory()->create([
            'admission_cycle_id' => $otherApplication->admission_cycle_id,
            'application_path' => $otherApplication->application_path,
        ]);
        $otherVersion = ApplicationSubmissionVersion::factory()->create([
            'admission_application_id' => $otherApplication->id,
            'admission_requirement_set_id' => $otherSet->id,
        ]);

        $this->actingAs($applicant)
            ->get(route('admissions.application.acknowledgment', [
                'application' => $application,
                'version' => $otherVersion,
            ]))
            ->assertNotFound();

        $registrar = User::factory()->create(['status' => User::StatusActive]);
        $registrar->assignRole(User::StaffRoleRegistrar);

        $this->actingAs($registrar)
            ->get(route('admissions.application.acknowledgment', [
                'application' => $application,
                'version' => $version,
            ]))
            ->assertOk()
            ->assertSee('Back to Applicant Record');
    }

    public function test_acknowledgment_render_failure_does_not_record_false_success(): void
    {
        $applicant = User::factory()->create(['status' => User::StatusActive]);
        $applicant->syncRoles(['applicant']);
        $application = AdmissionApplication::factory()->submitted()->create([
            'user_id' => $applicant->id,
        ]);
        $requirementSet = AdmissionRequirementSet::factory()->published()->create([
            'admission_cycle_id' => $application->admission_cycle_id,
            'application_path' => $application->application_path,
        ]);
        $version = ApplicationSubmissionVersion::factory()->create([
            'admission_application_id' => $application->id,
            'admission_requirement_set_id' => $requirementSet->id,
            'submitted_by' => $applicant->id,
        ]);
        $application->update(['current_submission_version_id' => $version->id]);

        View::composer('admissions.application-acknowledgment', static function (): never {
            throw new \RuntimeException('Synthetic acknowledgment render failure.');
        });

        $this->actingAs($applicant)
            ->get(route('admissions.application.acknowledgment', [
                'application' => $application,
                'version' => $version,
            ]))
            ->assertServerError();

        $this->assertDatabaseMissing(OutputAccessLog::class, [
            'output_type' => 'application.acknowledgment',
            'source_record_id' => $application->id,
            'status' => 'generated',
        ]);
    }
}
