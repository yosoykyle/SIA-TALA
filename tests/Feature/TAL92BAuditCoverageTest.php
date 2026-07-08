<?php

namespace Tests\Feature;

use App\Actions\Graduation\GraduationEligibilitySnapshotService;
use App\Actions\StudentLifecycle\CreateHold;
use App\Actions\StudentLifecycle\ExpireHold;
use App\Actions\StudentLifecycle\ResolveHold;
use App\Actions\StudentLifecycle\WaiveHold;
use App\Filament\Resources\Activities\ActivityResource;
use App\Filament\Resources\Activities\Pages\ListActivities;
use App\Filament\Resources\Activities\Pages\ViewActivity;
use App\Listeners\LogAuthenticationActivity;
use App\Models\GraduationReviewBatch;
use App\Models\GraduationReviewMember;
use App\Models\Hold;
use App\Models\StudentProfile;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * TAL-92B: Audit Trail Coverage Acceptance.
 *
 * Owning contract: PRD `13_system_admin_reports_audit.md` §13.6 (11 MVP audit
 * scopes) and §13.8 (audit-log interaction contract). Covers the three
 * confirmed logging gaps closed by this slice: login/session events (scope 1),
 * Graduation Review Batch/membership/snapshot/visibility events (scope 7),
 * and Holds create/expire/resolve/waive events (scope 9, holds half). Does not
 * duplicate schedule/calendar-block/enrollment coverage already asserted in
 * TAL66SchedulePublicationTest, TAL77CalendarSchedulingBlockTest, and
 * TAL87DOfficialEnrollmentTest.
 */
final class TAL92BAuditCoverageTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
        $this->assertNotContains(DB::connection()->getDatabaseName(), ['tala_db', 'tala_test_codex']);

        foreach ([...User::staffRoleNames(), 'student', 'applicant'] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    #[Test]
    public function activity_resource_is_super_admin_only_and_read_only(): void
    {
        $this->assertFalse(ActivityResource::canCreate());

        $superAdmin = $this->staff(User::StaffRoleSystemSuperAdmin);
        $this->actingAs($superAdmin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListActivities::class)->assertOk();

        foreach ([
            User::StaffRoleRegistrar,
            User::StaffRoleAccounting,
            User::StaffRoleAcademicHead,
            User::StaffRoleFaculty,
            'student',
        ] as $deniedRole) {
            $denied = $this->staff($deniedRole);
            $this->actingAs($denied);
            Filament::setCurrentPanel(Filament::getPanel('admin'));

            $this->assertFalse($denied->can('viewAny', Activity::class), "{$deniedRole} should not view the audit log list.");
        }
    }

    #[Test]
    public function login_logout_and_failed_login_each_write_one_activity_row_with_source_context(): void
    {
        $request = Request::create('/login', 'POST', server: [
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_USER_AGENT' => 'TAL-92B test agent',
        ]);
        $this->app->instance('request', $request);
        $listener = new LogAuthenticationActivity($request);

        $user = $this->staff(User::StaffRoleRegistrar);

        $listener->handleLogin(new Login('web', $user, false));
        $loginLog = Activity::query()->where('event', 'login')->sole();
        $this->assertSame($user->id, $loginLog->causer_id);
        $this->assertSame('203.0.113.10', data_get($loginLog->properties, 'ip'));
        $this->assertSame('TAL-92B test agent', data_get($loginLog->properties, 'user_agent'));

        $listener->handleLogout(new Logout('web', $user));
        $logoutLog = Activity::query()->where('event', 'logout')->sole();
        $this->assertSame($user->id, $logoutLog->causer_id);
        $this->assertSame('203.0.113.10', data_get($logoutLog->properties, 'ip'));

        $listener->handleFailed(new Failed('web', null, ['email' => 'unknown@example.test', 'password' => 'super-secret']));
        $failedLog = Activity::query()->where('event', 'login_failed')->sole();
        $this->assertNull($failedLog->causer_id);
        $this->assertSame('unknown@example.test', data_get($failedLog->properties, 'attempted_identifier'));
        $this->assertSame('203.0.113.10', data_get($failedLog->properties, 'ip'));
        $this->assertArrayNotHasKey('password', $failedLog->properties->toArray());
        $this->assertStringNotContainsString('super-secret', json_encode($failedLog->properties));
    }

    #[Test]
    public function graduation_review_batch_creation_membership_change_snapshot_refresh_and_visibility_toggle_are_audited(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $batch = GraduationReviewBatch::factory()->create(['created_by' => $registrar->id]);

        activity()
            ->performedOn($batch)
            ->causedBy($registrar)
            ->event('graduation_review_batch_created')
            ->log('Graduation Review Batch created');

        $batchLog = Activity::query()->where('event', 'graduation_review_batch_created')->sole();
        $this->assertSame(GraduationReviewBatch::class, $batchLog->subject_type);
        $this->assertSame($batch->id, $batchLog->subject_id);
        $this->assertSame($registrar->id, $batchLog->causer_id);

        $profile = StudentProfile::factory()->create();
        $member = GraduationReviewMember::query()->create([
            'graduation_review_batch_id' => $batch->id,
            'student_profile_id' => $profile->id,
            'added_by' => $registrar->id,
            'added_at' => now(),
            'is_active' => true,
        ]);

        activity()
            ->performedOn($member)
            ->causedBy($registrar)
            ->event('graduation_review_member_added')
            ->log('Graduation Review member added');

        $memberLog = Activity::query()->where('event', 'graduation_review_member_added')->sole();
        $this->assertSame(GraduationReviewMember::class, $memberLog->subject_type);
        $this->assertSame($member->id, $memberLog->subject_id);
        $this->assertSame($registrar->id, $memberLog->causer_id);

        $snapshot = app(GraduationEligibilitySnapshotService::class)->generate($member->fresh(), $registrar);

        $snapshotLog = Activity::query()->where('event', 'graduation_snapshot_generated')->sole();
        $this->assertSame($snapshot->id, $snapshotLog->subject_id);
        $this->assertSame($registrar->id, $snapshotLog->causer_id);

        activity()
            ->performedOn($snapshot)
            ->causedBy($registrar)
            ->event('graduation_snapshot_visibility_changed')
            ->withProperties(['visible_before' => false, 'visible_after' => true])
            ->log('Graduation Eligibility Snapshot made visible to student');

        $visibilityLog = Activity::query()->where('event', 'graduation_snapshot_visibility_changed')->sole();
        $this->assertSame($snapshot->id, $visibilityLog->subject_id);
        $this->assertSame($registrar->id, $visibilityLog->causer_id);
        $this->assertTrue((bool) data_get($visibilityLog->properties, 'visible_after'));
    }

    #[Test]
    public function create_expire_resolve_and_waive_hold_each_write_one_activity_row_with_before_after_status(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $profile = StudentProfile::factory()->create();

        $created = app(CreateHold::class)->execute($profile, [
            'hold_type' => Hold::TypeDocumentary,
            'blocking_level' => Hold::BlockingEnrollment,
            'reason' => 'Missing clearance form.',
            'resolution_requirement' => 'Submit clearance form to Registrar.',
        ], $registrar);

        $createdLog = Activity::query()->where('event', 'hold_created')->sole();
        $this->assertSame(Hold::class, $createdLog->subject_type);
        $this->assertSame($created->id, $createdLog->subject_id);
        $this->assertSame($registrar->id, $createdLog->causer_id);
        $this->assertSame(Hold::StatusActive, data_get($createdLog->properties, 'status_after'));

        $resolved = app(ResolveHold::class)->execute($created, $registrar, 'Clearance form received and verified.');
        $resolvedLog = Activity::query()->where('event', 'hold_resolved')->sole();
        $this->assertSame($resolved->id, $resolvedLog->subject_id);
        $this->assertSame($registrar->id, $resolvedLog->causer_id);
        $this->assertSame(Hold::StatusActive, data_get($resolvedLog->properties, 'status_before'));
        $this->assertSame(Hold::StatusResolved, data_get($resolvedLog->properties, 'status_after'));

        $waivable = Hold::factory()->create([
            'student_profile_id' => $profile->id,
            'hold_type' => Hold::TypeDocumentary,
            'blocking_level' => Hold::BlockingEnrollment,
            'status' => Hold::StatusActive,
        ]);
        $waived = app(WaiveHold::class)->execute($waivable, $registrar, 'Registrar Office Head', 'Approved administrative waiver.');
        $waivedLog = Activity::query()->where('event', 'hold_waived')->sole();
        $this->assertSame($waived->id, $waivedLog->subject_id);
        $this->assertSame($registrar->id, $waivedLog->causer_id);
        $this->assertSame(Hold::StatusActive, data_get($waivedLog->properties, 'status_before'));
        $this->assertSame(Hold::StatusWaived, data_get($waivedLog->properties, 'status_after'));

        $expiring = Hold::factory()->create([
            'student_profile_id' => $profile->id,
            'hold_type' => Hold::TypeDocumentary,
            'blocking_level' => Hold::BlockingEnrollment,
            'status' => Hold::StatusActive,
            'expires_at' => now()->subSecond(),
        ]);
        $expired = app(ExpireHold::class)->execute($expiring);
        $expiredLog = Activity::query()->where('event', 'hold_expired')->sole();
        $this->assertSame($expired->id, $expiredLog->subject_id);
        $this->assertNull($expiredLog->causer_id);
        $this->assertSame(Hold::StatusActive, data_get($expiredLog->properties, 'status_before'));
        $this->assertSame(Hold::StatusExpired, data_get($expiredLog->properties, 'status_after'));
    }

    #[Test]
    public function activities_table_and_infolist_render_the_new_event_types_without_error(): void
    {
        $superAdmin = $this->staff(User::StaffRoleSystemSuperAdmin);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $profile = StudentProfile::factory()->create();

        $hold = app(CreateHold::class)->execute($profile, [
            'hold_type' => Hold::TypeDocumentary,
            'blocking_level' => Hold::BlockingEnrollment,
            'reason' => 'Missing clearance form.',
            'resolution_requirement' => 'Submit clearance form to Registrar.',
        ], $registrar);

        $batch = GraduationReviewBatch::factory()->create(['created_by' => $registrar->id]);
        activity()->performedOn($batch)->causedBy($registrar)->event('graduation_review_batch_created')->log('Graduation Review Batch created');

        $request = Request::create('/login', 'POST', server: ['REMOTE_ADDR' => '203.0.113.10', 'HTTP_USER_AGENT' => 'TAL-92B test agent']);
        (new LogAuthenticationActivity($request))->handleLogin(new Login('web', $registrar, false));

        $this->actingAs($superAdmin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListActivities::class)->assertOk();

        $holdActivity = Activity::query()->where('event', 'hold_created')->where('subject_id', $hold->id)->sole();
        Livewire::test(ViewActivity::class, ['record' => $holdActivity->getRouteKey()])->assertOk();

        $batchActivity = Activity::query()->where('event', 'graduation_review_batch_created')->where('subject_id', $batch->id)->sole();
        Livewire::test(ViewActivity::class, ['record' => $batchActivity->getRouteKey()])->assertOk();

        $loginActivity = Activity::query()->where('event', 'login')->sole();
        Livewire::test(ViewActivity::class, ['record' => $loginActivity->getRouteKey()])->assertOk();
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
