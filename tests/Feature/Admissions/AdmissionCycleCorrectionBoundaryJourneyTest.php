<?php

namespace Tests\Feature\Admissions;

use App\Actions\Admissions\AdmissionCycleReadinessService;
use App\Actions\Admissions\ChangeAdmissionCycle;
use App\Actions\Admissions\RequestAdmissionCorrection;
use App\Actions\Admissions\SaveAdmissionApplication;
use App\Actions\Admissions\SubmitAdmissionApplication;
use App\Actions\Applicants\AdmissionWindowService;
use App\Filament\Applicant\Pages\Application as ApplicantApplicationPage;
use App\Filament\Applicant\Pages\Requirements as ApplicantRequirementsPage;
use App\Filament\Resources\AdmissionApplications\Pages\ViewAdmissionApplication;
use App\Filament\Resources\AdmissionCycles\Pages\ViewAdmissionCycle;
use App\Models\AdmissionApplication;
use App\Models\AdmissionCycle;
use App\Models\AdmissionRequirementSet;
use App\Models\ApplicationCorrectionItem;
use App\Models\ApplicationCorrectionRequest;
use App\Models\ApplicationSubmissionVersion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdmissionCycleCorrectionBoundaryJourneyTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('applicant', 'web');
        Role::findOrCreate(User::StaffRoleRegistrar, 'web')->givePermissionTo([
            Permission::findOrCreate('approve-documents', 'web'),
            Permission::findOrCreate('manage-admission-setup', 'web'),
        ]);
    }

    public function test_legacy_cycles_are_backfilled_without_changing_public_closing(): void
    {
        $publicClose = CarbonImmutable::parse('2026-09-30 17:00:00', 'Asia/Manila')->utc();
        $cycle = AdmissionCycle::factory()->create([
            'closes_at' => $publicClose,
            'correction_closes_at' => null,
        ]);

        $migration = require database_path('migrations/2026_08_18_065132_backfill_admission_cycle_correction_boundaries.php');
        $migration->up();
        $migration->up();

        $cycle->refresh();
        $this->assertTrue($cycle->closes_at->equalTo($publicClose));
        $this->assertTrue($cycle->correction_closes_at->equalTo($publicClose));
        $this->assertSame(0, $cycle->events()->count());
    }

    public function test_new_corrections_use_the_separate_inclusive_boundary(): void
    {
        CarbonImmutable::setTestNow('2026-10-02 09:00:00');
        [$application, $registrar] = $this->reviewableApplication([
            'closes_at' => now()->subDay(),
            'correction_closes_at' => now()->addDay(),
        ]);

        $request = $this->requestCorrection($application, $registrar, now()->addDay());

        $this->assertSame(ApplicationCorrectionRequest::StateActive, $request->state);
        $this->assertTrue($request->due_at->equalTo($application->admissionCycle->correction_closes_at));

        try {
            $this->requestCorrection($application->fresh(), $registrar, now()->addHours(12));
            $this->fail('A duplicate active correction request must fail without mutation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('application_state', $exception->errors());
            $this->assertSame(1, $application->correctionRequests()->count());
        }

        [$laterApplication, $laterRegistrar] = $this->reviewableApplication([
            'closes_at' => now()->subDay(),
            'correction_closes_at' => now()->addDay(),
        ]);

        try {
            $this->requestCorrection($laterApplication, $laterRegistrar, now()->addDay()->addSecond());
            $this->fail('A due time after the correction boundary must fail without mutation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('due_at', $exception->errors());
            $this->assertSame(0, $laterApplication->correctionRequests()->count());
        }
    }

    public function test_publication_readiness_requires_ordered_public_and_correction_boundaries(): void
    {
        $cycle = AdmissionCycle::factory()->create([
            'opens_at' => now()->subDay(),
            'closes_at' => now()->addDay(),
            'correction_closes_at' => null,
        ]);

        $projection = app(AdmissionCycleReadinessService::class)->for($cycle);
        $this->assertFalse($projection['ready']);
        $this->assertContains('target_term_and_dates', collect($projection['blockers'])->pluck('code')->all());

        $cycle->forceFill(['correction_closes_at' => now()])->save();
        $projection = app(AdmissionCycleReadinessService::class)->for($cycle->fresh());
        $this->assertFalse($projection['ready']);
        $this->assertContains('target_term_and_dates', collect($projection['blockers'])->pluck('code')->all());
    }

    public function test_expired_boundary_blocks_only_new_issuance_and_preserves_active_correction(): void
    {
        [$application, $registrar] = $this->reviewableApplication([
            'closes_at' => now()->subDays(2),
            'correction_closes_at' => now()->addDay(),
        ]);
        $request = $this->requestCorrection($application, $registrar, now()->addHours(12));
        CarbonImmutable::setTestNow(now()->addDays(2));

        $this->assertTrue($request->fresh()->isOverdue());

        app(SaveAdmissionApplication::class)->execute(
            $application->user,
            $application->admissionCycle,
            ['current_province' => 'Cavite', 'privacy_acknowledged' => true, 'accuracy_declared' => true],
            $application->fresh(),
        );
        $resubmitted = app(SubmitAdmissionApplication::class)->execute($application->fresh(), $application->user);

        $this->assertSame(AdmissionApplication::StateSubmitted, $resubmitted->application_state);
        $this->assertSame(ApplicationCorrectionRequest::StateCompleted, $request->fresh()->state);

        try {
            $this->requestCorrection($resubmitted, $registrar, now()->addHour());
            $this->fail('Expired correction issuance must fail closed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('due_at', $exception->errors());
        }
    }

    public function test_public_and_correction_boundaries_change_independently_with_history(): void
    {
        [$application, $registrar] = $this->reviewableApplication([
            'closes_at' => now()->addDay(),
            'correction_closes_at' => now()->addDays(2),
        ]);
        $cycle = $application->admissionCycle;
        $originalCorrectionClose = $cycle->correction_closes_at->copy();
        $extendedCorrectionClose = $originalCorrectionClose->copy()->addDay();

        $closed = app(ChangeAdmissionCycle::class)->close(
            $cycle,
            $registrar,
            'Public intake closed by authorized decision.',
            'Synthetic registrar authority',
        );
        $this->assertTrue($closed->correction_closes_at->equalTo($originalCorrectionClose));

        $extended = app(ChangeAdmissionCycle::class)->extendCorrectionBoundary(
            $closed,
            $registrar,
            $extendedCorrectionClose,
            'Allow active correction issuance for one more day.',
            'Synthetic registrar authority',
        );

        $event = $extended->events()->latest('id')->firstOrFail();
        $this->assertTrue($extended->closes_at->equalTo($closed->closes_at));
        $this->assertSame('extend_correction_boundary', $event->new_values['operation']);
        $this->assertArrayHasKey('closes_at', $event->previous_values);
        $this->assertArrayHasKey('correction_closes_at', $event->previous_values);
        $this->assertArrayHasKey('closes_at', $event->new_values);
        $this->assertArrayHasKey('correction_closes_at', $event->new_values);
        $this->assertSame($registrar->id, $event->actor_id);
        $this->assertNotNull($event->reason);
        $this->assertNotNull($event->authority_reference);

        $eventCount = $extended->events()->count();

        try {
            app(ChangeAdmissionCycle::class)->extendCorrectionBoundary(
                $closed,
                $registrar,
                $originalCorrectionClose->copy()->addDays(2),
                'Attempted from a stale Cycle view.',
                'Synthetic registrar authority',
            );
            $this->fail('A stale boundary action must not mutate the Cycle.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('boundary_version', $exception->errors());
        }

        $this->assertSame($eventCount, $extended->events()->count());
        $this->assertTrue($extended->fresh()->correction_closes_at->equalTo($extendedCorrectionClose));
    }

    public function test_unauthorized_boundary_change_creates_no_partial_mutation(): void
    {
        [$application] = $this->reviewableApplication([
            'closes_at' => now()->addDay(),
            'correction_closes_at' => now()->addDays(2),
        ]);
        $cycle = $application->admissionCycle;
        $originalBoundary = $cycle->correction_closes_at->copy();
        $applicant = $application->user;

        try {
            app(ChangeAdmissionCycle::class)->extendCorrectionBoundary(
                $cycle,
                $applicant,
                $originalBoundary->copy()->addDay(),
                'Unauthorized extension.',
                'No authority',
            );
            $this->fail('An Applicant must not change an Admission Cycle boundary.');
        } catch (AuthorizationException) {
            $this->assertTrue($cycle->fresh()->correction_closes_at->equalTo($originalBoundary));
            $this->assertSame(0, $cycle->events()->count());
        }
    }

    public function test_public_availability_ignores_the_correction_boundary(): void
    {
        [$application] = $this->reviewableApplication([
            'closes_at' => now()->subSecond(),
            'correction_closes_at' => now()->addDay(),
        ]);

        $this->assertFalse(app(AdmissionWindowService::class)->hasOpenAdmissionsWindow());
        $this->assertNull(app(AdmissionWindowService::class)->currentCycle());
        $this->assertTrue($application->admissionCycle->correction_closes_at->isFuture());
    }

    public function test_registrar_and_applicant_surfaces_distinguish_boundaries_due_and_overdue_recovery(): void
    {
        [$application, $registrar] = $this->reviewableApplication([
            'closes_at' => now()->subDay(),
            'correction_closes_at' => now()->addDay(),
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($registrar)
            ->test(ViewAdmissionCycle::class, ['record' => $application->admissionCycle->id])
            ->assertSee('Public closing')
            ->assertSee('New-correction closing')
            ->assertActionVisible('extendCorrectionBoundary');

        Livewire::actingAs($registrar)
            ->test(ViewAdmissionApplication::class, ['record' => $application->id])
            ->assertActionVisible('requestCorrection')
            ->assertActionHidden('manageCorrectionBoundary');

        $application->admissionCycle->forceFill(['correction_closes_at' => now()->subSecond()])->save();
        Livewire::actingAs($registrar)
            ->test(ViewAdmissionApplication::class, ['record' => $application->id])
            ->assertActionHidden('requestCorrection')
            ->assertActionVisible('manageCorrectionBoundary');
        $application->admissionCycle->forceFill(['correction_closes_at' => now()->addDay()])->save();

        $request = $this->requestCorrection($application, $registrar, now()->addHours(2));
        CarbonImmutable::setTestNow(now()->addDays(2));
        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        Livewire::actingAs($application->user)
            ->test(ApplicantApplicationPage::class)
            ->assertSee('Correction overdue')
            ->assertSee($request->applicant_instruction);
        Livewire::actingAs($application->user)
            ->test(ApplicantRequirementsPage::class)
            ->assertSee('Correction overdue')
            ->assertSee('remains editable and resubmittable');
    }

    /** @param array<string, mixed> $cycleData @return array{AdmissionApplication, User} */
    private function reviewableApplication(array $cycleData): array
    {
        $application = AdmissionApplication::factory()->submitted()->create();
        $cycle = $application->admissionCycle;
        $cycle->forceFill(array_merge([
            'state' => AdmissionCycle::StatePublished,
            'opens_at' => now()->subMonth(),
        ], $cycleData))->save();
        $cycle->programs()->syncWithoutDetaching([
            $application->program_id => [
                'accepts_first_year' => $application->application_path === AdmissionApplication::PathFirstYear,
                'accepts_transferee' => $application->application_path === AdmissionApplication::PathTransferee,
            ],
        ]);
        $application->user->forceFill(['status' => User::StatusActive])->save();
        $application->user->assignRole('applicant');
        $requirementSet = AdmissionRequirementSet::factory()->for($cycle)->create([
            'application_path' => $application->application_path,
            'state' => AdmissionRequirementSet::StatePublished,
            'effective_at' => now()->subDay(),
            'published_at' => now()->subDay(),
        ]);
        $submission = ApplicationSubmissionVersion::factory()
            ->for($application, 'application')
            ->for($requirementSet, 'requirementSet')
            ->create(['version' => 1]);
        $application->forceFill(['current_submission_version_id' => $submission->id])->save();
        $registrar = User::factory()->create(['status' => User::StatusActive]);
        $registrar->assignRole(User::StaffRoleRegistrar);

        return [$application->refresh(), $registrar];
    }

    private function requestCorrection(
        AdmissionApplication $application,
        User $registrar,
        CarbonInterface $dueAt,
    ): ApplicationCorrectionRequest {
        return app(RequestAdmissionCorrection::class)->execute(
            $application,
            $registrar,
            [[
                'type' => ApplicationCorrectionItem::ScopeField,
                'key' => 'current_province',
                'admission_requirement_id' => null,
            ]],
            'Correct the province shown in your application.',
            'Applicant',
            $dueAt,
        );
    }
}
