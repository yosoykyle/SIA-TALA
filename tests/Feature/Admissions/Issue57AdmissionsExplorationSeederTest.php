<?php

namespace Tests\Feature\Admissions;

use App\Actions\Authentication\TalaAppAuthentication;
use App\Actions\SystemAdministration\TAL96D5E1ExplorationPersonaCatalog;
use App\Filament\Applicant\Pages\Application;
use App\Filament\Applicant\Pages\Dashboard;
use App\Models\AdmissionApplication;
use App\Models\AdmissionCycle;
use App\Models\ApplicationCorrectionRequest;
use App\Models\PreliminaryEvidenceReview;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use App\Queries\Admissions\ReadyApplicantProjectionQuery;
use Database\Seeders\Issue57AdmissionsExplorationSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class Issue57AdmissionsExplorationSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_narrow_exploration_seed_creates_canonical_states_without_resetting_other_records(): void
    {
        Storage::fake('local');
        Role::findOrCreate('applicant', 'web');
        $role = Role::findOrCreate(User::StaffRoleRegistrar, 'web');
        foreach (['approve-documents', 'manage-admission-setup'] as $permission) {
            $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $registrar = User::factory()->create(['status' => User::StatusActive]);
        $registrar->assignRole(User::StaffRoleRegistrar);
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $program = Program::factory()->create(['is_active' => true]);
        $retained = AdmissionApplication::factory()->create(['admission_cycle_id' => AdmissionCycle::factory()->create(['term_id' => $term->id])->id]);
        $retainedAttributes = $retained->fresh()->getAttributes();
        $studentsBefore = StudentProfile::query()->count();
        Mail::fake();
        $seeder = app(Issue57AdmissionsExplorationSeeder::class);

        AdmissionCycle::query()->where('code', Issue57AdmissionsExplorationSeeder::CycleCode)
            ->update(['code' => 'ISSUE57-RETAINED-TEST']);

        $seeder->ensure($term, $program, $registrar);
        $cycle = AdmissionCycle::query()->where('code', Issue57AdmissionsExplorationSeeder::CycleCode)->sole();
        $applications = $cycle->applications()->with('user')->get();
        $this->assertCount(11, $applications);
        $minor = $applications->first(fn (AdmissionApplication $candidate): bool => $candidate->user->email === 'applicant.minor.demo@example.test');
        $this->assertLessThan(18, $minor->birth_date->age);
        $this->assertSame('Synthetic Parent', $minor->guardian_full_name);
        $als = $applications->first(fn (AdmissionApplication $candidate): bool => $candidate->user->email === 'applicant.als.demo@example.test');
        $this->assertSame('ALS_AE', $als->credential_basis);
        $this->assertSame('NotAvailable', $als->lrn_availability);
        $this->assertNull($als->lrn);
        $this->assertSame(AdmissionApplication::StateSubmitted, $als->application_state);
        $this->assertSame(AdmissionCycle::StatePublished, $cycle->state);

        foreach (app(TAL96D5E1ExplorationPersonaCatalog::class)->applicants() as $email => $definition) {
            $application = $applications->first(fn (AdmissionApplication $candidate): bool => $candidate->user->email === $email);
            $this->assertInstanceOf(AdmissionApplication::class, $application);
            $this->assertSame($definition['application_state'], $application->application_state);
            $this->assertSame($definition['application_path'], $application->application_path);
            $this->assertSame(User::StatusActive, $application->user->status);
            $this->assertTrue($application->user->canAuthenticate());
            $this->assertNotNull($application->user->email_verified_at);
        }

        $ready = $applications->first(fn (AdmissionApplication $application): bool => $application->user->email === 'applicant.ready.demo@example.test');
        $this->assertTrue(app(ReadyApplicantProjectionQuery::class)->forApplication($ready)['ready']);
        $pending = $applications->first(fn (AdmissionApplication $application): bool => $application->user->email === 'applicant.approved.demo@example.test');
        $this->assertFalse(app(ReadyApplicantProjectionQuery::class)->forApplication($pending)['ready']);
        $correction = $applications->first(fn (AdmissionApplication $application): bool => $application->user->email === 'applicant.action-required.demo@example.test');
        $request = $correction->correctionRequests()->where('state', ApplicationCorrectionRequest::StateActive)->sole();
        $this->assertSame(1, $request->items()->count());
        $latest = $correction->evidenceVersions()->where('admission_requirement_id', $request->items()->sole()->admission_requirement_id)->latest('id')->firstOrFail();
        $this->assertSame(PreliminaryEvidenceReview::ResultActionNeeded, $latest->preliminaryReviews()->whereDoesntHave('successor')->sole()->result);
        Storage::disk('local')->assertExists($latest->path);
        $counts = [$cycle->applications()->count(), $cycle->applications()->withCount('submissionVersions')->get()->sum('submission_versions_count')];

        $seeder->ensure($term, $program, $registrar);

        $this->assertSame($counts, [$cycle->applications()->count(), $cycle->applications()->withCount('submissionVersions')->get()->sum('submission_versions_count')]);
        $this->assertEquals($retainedAttributes, $retained->fresh()->getAttributes());
        $this->assertSame($studentsBefore, StudentProfile::query()->count());
        Mail::assertNothingOutgoing();

        $reference = $correction->application_reference;
        Filament::setCurrentPanel(Filament::getPanel('applicant'));
        Livewire::actingAs($correction->user)->test(Application::class, ['sourceApplicationId' => $correction->id])
            ->fillForm([
                'privacy_acknowledged' => true,
                'accuracy_declared' => true,
                'evidence' => [$request->items()->sole()->admission_requirement_id => UploadedFile::fake()->image('legible-photo.png', 162, 162)],
            ])
            ->call('submitApplication')
            ->assertSet('saveStatus', 'saved')
            ->assertRedirect(Dashboard::getUrl(['application' => $correction->id]));
        $this->assertSame(AdmissionApplication::StateSubmitted, $correction->fresh()->application_state);
        $this->assertSame($reference, $correction->fresh()->application_reference);
        $this->assertSame(2, $correction->submissionVersions()->count());
    }

    public function test_run_creates_a_separate_registrar_with_normal_password_and_mfa_without_changing_retained_access(): void
    {
        Storage::fake('local');
        Role::findOrCreate('applicant', 'web');
        $role = Role::findOrCreate(User::StaffRoleRegistrar, 'web');
        foreach (['approve-documents', 'manage-admission-setup'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $retained = User::factory()->create(['password' => 'retained-synthetic-password']);
        $retained->assignRole($role);
        $retained->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $retained->saveAppAuthenticationRecoveryCodes(['retained-synthetic-recovery-code']);
        $retainedAttributes = $retained->fresh()->getAttributes();
        $retainedPermissions = $retained->getAllPermissions()->pluck('name')->sort()->values()->all();
        $rolePermissions = $role->permissions()->pluck('name')->sort()->values()->all();
        if (! Term::query()->where('state', Term::StateActive)->exists()) {
            Term::factory()->create(['state' => Term::StateActive]);
        }
        Program::factory()->create(['is_active' => true]);

        app(Issue57AdmissionsExplorationSeeder::class)->run();
        $registrar = User::query()->where('email', Issue57AdmissionsExplorationSeeder::RegistrarEmail)->sole();
        $authentication = app(TalaAppAuthentication::class)->recoverable();

        $this->assertTrue(Auth::validate(['email' => $registrar->email, 'password' => 'password']));
        $this->assertTrue($registrar->canAuthenticate());
        $this->assertNotNull($registrar->email_verified_at);
        $this->assertTrue($registrar->hasRole(User::StaffRoleRegistrar));
        $this->assertTrue($registrar->can('approve-documents'));
        $this->assertTrue($registrar->can('manage-admission-setup'));
        $this->assertNotNull($registrar->two_factor_confirmed_at);
        $this->assertNotNull($registrar->two_factor_recovery_codes_acknowledged_at);
        $this->assertNotEmpty($registrar->getAppAuthenticationRecoveryCodes());
        $this->assertNotSame($registrar->getAppAuthenticationSecret(), $registrar->two_factor_secret);
        $this->assertTrue($authentication->verifyCode($authentication->getCurrentCode($registrar), $registrar->getAppAuthenticationSecret()));
        $factor = $registrar->two_factor_secret;

        app(Issue57AdmissionsExplorationSeeder::class)->run();

        $this->assertSame($factor, $registrar->fresh()->two_factor_secret);
        $this->assertEquals($retainedAttributes, $retained->fresh()->getAttributes());
        $this->assertSame($retainedPermissions, $retained->fresh()->getAllPermissions()->pluck('name')->sort()->values()->all());
        $this->assertSame($rolePermissions, $role->permissions()->pluck('name')->sort()->values()->all());
    }

    public function test_narrow_seed_rejects_other_environments_before_creating_exploration_records(): void
    {
        $this->app->instance('env', 'production');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('APP_ENV=testing');
        app(Issue57AdmissionsExplorationSeeder::class)->run();
    }
}
