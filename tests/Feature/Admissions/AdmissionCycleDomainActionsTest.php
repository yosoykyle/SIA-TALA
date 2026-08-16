<?php

namespace Tests\Feature\Admissions;

use App\Actions\Admissions\AdmissionCycleReadinessService;
use App\Actions\Admissions\ChangeAdmissionCycle;
use App\Actions\Admissions\PublishAdmissionCycle;
use App\Actions\Admissions\PublishAdmissionRequirementSet;
use App\Models\AdmissionCycle;
use App\Models\AdmissionCycleEvent;
use App\Models\AdmissionRequirement;
use App\Models\AdmissionRequirementSet;
use App\Models\Program;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdmissionCycleDomainActionsTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $permission = Permission::findOrCreate('manage-admission-setup', 'web');
        Role::findOrCreate(User::StaffRoleRegistrar, 'web')->givePermissionTo($permission);
    }

    public function test_publication_is_failed_first_and_reports_owned_recovery_for_each_blocker(): void
    {
        Storage::fake('local');
        $cycle = AdmissionCycle::factory()->create([
            'applicant_instructions' => null,
            'support_contact' => null,
            'privacy_notice_reference' => null,
            'registrar_owner_id' => null,
        ]);

        $projection = app(AdmissionCycleReadinessService::class)->for($cycle);

        $this->assertFalse($projection['ready']);
        $this->assertNotEmpty($projection['blockers']);

        foreach ($projection['blockers'] as $blocker) {
            $this->assertSame(
                ['code', 'source', 'owner', 'reason', 'next_action', 'recovery'],
                array_keys($blocker),
            );
            $this->assertNotSame('', $blocker['source']);
            $this->assertNotSame('', $blocker['owner']);
            $this->assertNotSame('', $blocker['reason']);
            $this->assertNotSame('', $blocker['recovery']);
        }

        $this->expectException(ValidationException::class);
        app(PublishAdmissionCycle::class)->execute(
            $cycle,
            $this->registrar(),
            authorityReference: 'Synthetic Registrar authority',
        );
    }

    public function test_cycle_publication_and_later_date_or_cancellation_changes_append_history(): void
    {
        Storage::fake('local');
        $actor = $this->registrar();
        $cycle = $this->publishableCycle($actor);
        $publisher = app(PublishAdmissionCycle::class);
        $changes = app(ChangeAdmissionCycle::class);

        $published = $publisher->execute($cycle, $actor, 'Synthetic publication authority');

        $this->assertSame(AdmissionCycle::StatePublished, $published->state);
        $this->assertSame(AdmissionCycleEvent::TypePublished, $published->events()->sole()->event_type);

        $closed = $changes->close(
            $published,
            $actor,
            reason: 'Application deadline reached.',
            authorityReference: 'Synthetic close authority',
        );
        $this->assertTrue($closed->closes_at->lessThanOrEqualTo(now()));

        $extended = $changes->extend(
            $closed,
            $actor,
            newClosingTime: now()->addDays(2),
            reason: 'Authorized two-day extension.',
            authorityReference: 'Synthetic extension authority',
        );
        $this->assertTrue($extended->closes_at->isFuture());

        $closedAgain = $changes->close(
            $extended,
            $actor,
            reason: 'Extended application deadline reached.',
            authorityReference: 'Synthetic second close authority',
        );
        $reopened = $changes->reopen(
            $closedAgain,
            $actor,
            newClosingTime: now()->addDays(3),
            reason: 'Authorized reopening.',
            authorityReference: 'Synthetic reopening authority',
        );
        $this->assertTrue($reopened->closes_at->isFuture());

        $cancelled = $changes->cancel(
            $reopened,
            $actor,
            reason: 'Cycle replaced by an authorized successor.',
            authorityReference: 'Synthetic cancellation authority',
        );

        $this->assertSame(AdmissionCycle::StateCancelled, $cancelled->state);
        $this->assertSame(6, $cancelled->events()->count());
        $this->assertSame([
            AdmissionCycleEvent::TypePublished,
            AdmissionCycleEvent::TypeDatesChanged,
            AdmissionCycleEvent::TypeDatesChanged,
            AdmissionCycleEvent::TypeDatesChanged,
            AdmissionCycleEvent::TypeDatesChanged,
            AdmissionCycleEvent::TypeCancelled,
        ], $cancelled->events()->oldest('id')->pluck('event_type')->all());
    }

    public function test_complete_requirement_versions_publish_and_replacements_stay_in_same_cycle_and_path(): void
    {
        $actor = $this->registrar();
        $cycle = AdmissionCycle::factory()->create();
        $first = AdmissionRequirementSet::factory()->for($cycle)->create(['version' => 1]);
        AdmissionRequirement::factory()->for($first, 'requirementSet')->create([
            'credential_classification' => 'CoreFirstYearCompletionCredential',
        ]);

        $published = app(PublishAdmissionRequirementSet::class)->execute(
            $first,
            $actor,
            'Synthetic requirement authority v1',
        );
        $this->assertSame(AdmissionRequirementSet::StatePublished, $published->state);

        $replacement = AdmissionRequirementSet::factory()->for($cycle)->create([
            'application_path' => $published->application_path,
            'version' => 2,
        ]);
        AdmissionRequirement::factory()->for($replacement, 'requirementSet')->create([
            'credential_classification' => 'CoreFirstYearCompletionCredential',
        ]);
        $publishedReplacement = app(PublishAdmissionRequirementSet::class)->execute(
            $replacement,
            $actor,
            'Synthetic requirement authority v2',
            $published,
        );

        $this->assertSame($published->id, $publishedReplacement->replaces_requirement_set_id);
        $this->assertSame(2, $publishedReplacement->version);
    }

    public function test_requirement_set_publication_requires_the_path_core_credential_and_non_core_only_exceptions(): void
    {
        $actor = $this->registrar();
        $cycle = AdmissionCycle::factory()->create();

        $nonCoreOnly = AdmissionRequirementSet::factory()->for($cycle)->create([
            'application_path' => AdmissionCycle::PathFirstYear,
            'version' => 1,
        ]);
        AdmissionRequirement::factory()->for($nonCoreOnly, 'requirementSet')->create([
            'credential_classification' => 'NonCore',
            'exception_permitted' => true,
            'required_approving_authority' => 'Registrar',
        ]);

        try {
            app(PublishAdmissionRequirementSet::class)->execute(
                $nonCoreOnly,
                $actor,
                'Synthetic non-core-only requirement authority',
            );
            $this->fail('A path without its mandatory core credential must not publish.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('requirements', $exception->errors());
            $this->assertSame(AdmissionRequirementSet::StateDraft, $nonCoreOnly->fresh()->state);
        }

        $coreWithException = AdmissionRequirementSet::factory()->for($cycle)->create([
            'application_path' => AdmissionCycle::PathFirstYear,
            'version' => 2,
        ]);
        AdmissionRequirement::factory()->for($coreWithException, 'requirementSet')->create([
            'credential_classification' => 'CoreFirstYearCompletionCredential',
            'exception_permitted' => true,
            'required_approving_authority' => 'Registrar',
        ]);

        try {
            app(PublishAdmissionRequirementSet::class)->execute(
                $coreWithException,
                $actor,
                'Synthetic invalid core-exception authority',
            );
            $this->fail('A core credential that permits an exception must not publish.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('requirements', $exception->errors());
            $this->assertSame(AdmissionRequirementSet::StateDraft, $coreWithException->fresh()->state);
        }

        $complete = AdmissionRequirementSet::factory()->for($cycle)->create([
            'application_path' => AdmissionCycle::PathFirstYear,
            'version' => 3,
        ]);
        AdmissionRequirement::factory()->for($complete, 'requirementSet')->create([
            'credential_classification' => 'CoreFirstYearCompletionCredential',
            'exception_permitted' => false,
            'required_approving_authority' => null,
        ]);
        AdmissionRequirement::factory()->for($complete, 'requirementSet')->create([
            'credential_classification' => 'NonCore',
            'exception_permitted' => true,
            'required_approving_authority' => 'Registrar',
            'display_order' => 20,
        ]);

        $published = app(PublishAdmissionRequirementSet::class)->execute(
            $complete,
            $actor,
            'Synthetic complete requirement authority',
        );

        $this->assertSame(AdmissionRequirementSet::StatePublished, $published->state);

        $wrongTransfereeCore = AdmissionRequirementSet::factory()->for($cycle)->create([
            'application_path' => AdmissionCycle::PathTransferee,
            'version' => 1,
        ]);
        AdmissionRequirement::factory()->for($wrongTransfereeCore, 'requirementSet')->create([
            'credential_classification' => 'CoreFirstYearCompletionCredential',
        ]);

        try {
            app(PublishAdmissionRequirementSet::class)->execute(
                $wrongTransfereeCore,
                $actor,
                'Synthetic wrong-path credential authority',
            );
            $this->fail('A transferee path without its transfer credential must not publish.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('requirements', $exception->errors());
        }

        $completeTransferee = AdmissionRequirementSet::factory()->for($cycle)->create([
            'application_path' => AdmissionCycle::PathTransferee,
            'version' => 2,
        ]);
        AdmissionRequirement::factory()->for($completeTransferee, 'requirementSet')->create([
            'credential_classification' => 'CoreTransferCredential',
            'official_submission_method' => AdmissionRequirement::SubmissionSchoolToSchool,
        ]);

        $publishedTransferee = app(PublishAdmissionRequirementSet::class)->execute(
            $completeTransferee,
            $actor,
            'Synthetic complete transferee requirement authority',
        );

        $this->assertSame(AdmissionRequirementSet::StatePublished, $publishedTransferee->state);
    }

    public function test_cycle_readiness_fails_closed_for_an_unclassified_published_requirement_version(): void
    {
        Storage::fake('local');
        $cycle = $this->publishableCycle($this->registrar());
        $requirement = $cycle->requirementSets()->firstOrFail()->requirements()->firstOrFail();

        AdmissionRequirement::withoutEvents(
            fn () => $requirement->forceFill(['credential_classification' => null])->save(),
        );

        $projection = app(AdmissionCycleReadinessService::class)->for($cycle->fresh());

        $this->assertFalse($projection['ready']);
        $this->assertContains(
            'requirement_set_first_year',
            collect($projection['blockers'])->pluck('code')->all(),
        );
    }

    private function registrar(): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole(User::StaffRoleRegistrar);

        return $user;
    }

    private function publishableCycle(User $owner): AdmissionCycle
    {
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $program = Program::factory()->create(['is_active' => true]);
        $cycle = AdmissionCycle::factory()->create([
            'term_id' => $term->id,
            'state' => AdmissionCycle::StateDraft,
            'opens_at' => now()->subDay(),
            'closes_at' => now()->addDay(),
            'registrar_owner_id' => $owner->id,
        ]);
        $cycle->programs()->attach($program, [
            'accepts_first_year' => true,
            'accepts_transferee' => false,
        ]);
        $requirementSet = AdmissionRequirementSet::factory()->for($cycle)->create([
            'application_path' => AdmissionCycle::PathFirstYear,
        ]);
        AdmissionRequirement::factory()->for($requirementSet, 'requirementSet')->create([
            'credential_classification' => 'CoreFirstYearCompletionCredential',
        ]);
        $requirementSet->forceFill([
            'state' => AdmissionRequirementSet::StatePublished,
            'effective_at' => now()->subMinute(),
            'published_at' => now()->subMinute(),
        ])->save();

        return $cycle;
    }
}
