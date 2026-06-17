<?php

namespace Tests\Feature;

use App\Actions\Scheduling\SchedulePublishService;
use App\Models\Program;
use App\Models\ScheduleGenerationRun;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\SectionMeeting;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SchedulePublishServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_academic_head_can_publish_committed_run_with_official_meetings(): void
    {
        $academicHead = $this->staffUser(User::StaffRoleAcademicHead, ['authorize-overrides']);
        $run = $this->committedRunWithMeeting();

        $publishedRun = app(SchedulePublishService::class)->publish(
            $run,
            $academicHead,
            '  Ready for UAT posting.  ',
        );

        $properties = $this->activityProperties($publishedRun);

        $this->assertSame(ScheduleGenerationRun::StatusPublished, $publishedRun->status);
        $this->assertSame($academicHead->id, $publishedRun->published_by);
        $this->assertNotNull($publishedRun->published_at);
        $this->assertSame('Ready for UAT posting.', $publishedRun->publish_note);
        $this->assertFalse((bool) $publishedRun->emergency_published);
        $this->assertSame(ScheduleGenerationRun::StatusPublished, $properties['status_after']);
        $this->assertFalse($properties['emergency_published']);
    }

    public function test_non_academic_head_cannot_publish_normal_run(): void
    {
        $registrar = $this->staffUser(User::StaffRoleRegistrar, ['manage-schedules', 'authorize-overrides']);
        $run = $this->committedRunWithMeeting();

        $this->expectException(AuthorizationException::class);

        app(SchedulePublishService::class)->publish($run, $registrar);
    }

    public function test_system_super_admin_emergency_publish_requires_reason(): void
    {
        $systemSuperAdmin = $this->staffUser(User::StaffRoleSystemSuperAdmin);
        $run = $this->committedRunWithMeeting();

        try {
            app(SchedulePublishService::class)->publish($run, $systemSuperAdmin, '  ', emergency: true);
            $this->fail('Expected emergency publish without reason to be rejected.');
        } catch (AuthorizationException) {
            $this->assertSame(ScheduleGenerationRun::StatusCommitted, $run->refresh()->status);
        }

        $publishedRun = app(SchedulePublishService::class)->publish(
            $run,
            $systemSuperAdmin,
            'Academic Head unavailable; client approved release.',
            emergency: true,
        );

        $this->assertSame(ScheduleGenerationRun::StatusPublished, $publishedRun->status);
        $this->assertTrue((bool) $publishedRun->emergency_published);
        $this->assertSame('Academic Head unavailable; client approved release.', $publishedRun->publish_note);
    }

    public function test_committed_run_without_official_meetings_cannot_be_published(): void
    {
        $academicHead = $this->staffUser(User::StaffRoleAcademicHead, ['authorize-overrides']);
        $term = Term::factory()->create();
        $run = ScheduleGenerationRun::query()->create([
            'term_id' => $term->id,
            'status' => ScheduleGenerationRun::StatusCommitted,
            'requested_by' => User::factory()->create()->id,
            'generated_at' => now(),
            'committed_by' => User::factory()->create()->id,
            'committed_at' => now(),
            'constraint_summary' => [],
        ]);

        $this->expectException(ValidationException::class);

        app(SchedulePublishService::class)->publish($run, $academicHead);
    }

    private function committedRunWithMeeting(): ScheduleGenerationRun
    {
        $term = Term::factory()->create();
        $program = Program::factory()->create();
        $section = Section::factory()->for($term)->for($program)->create();
        $deliveryGroup = SectionDeliveryGroup::factory()->create([
            'section_id' => $section->id,
            'modality' => 'on_site',
            'capacity' => 30,
            'assigned_count' => 0,
            'room_required' => true,
            'room' => 'R-101',
            'status' => SectionDeliveryGroup::StatusActive,
        ]);
        $subject = Subject::factory()->create();
        $faculty = User::factory()->create();
        $registrar = User::factory()->create();

        $run = ScheduleGenerationRun::query()->create([
            'term_id' => $term->id,
            'status' => ScheduleGenerationRun::StatusCommitted,
            'requested_by' => $registrar->id,
            'generated_at' => now(),
            'committed_by' => $registrar->id,
            'committed_at' => now(),
            'constraint_summary' => [],
        ]);

        SectionMeeting::query()->create([
            'term_id' => $term->id,
            'section_id' => $section->id,
            'section_delivery_group_id' => $deliveryGroup->id,
            'subject_id' => $subject->id,
            'faculty_id' => $faculty->id,
            'room' => 'R-101',
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '09:00:00',
            'modality' => 'on_site',
            'schedule_generation_run_id' => $run->id,
            'committed_by' => $registrar->id,
            'committed_at' => now(),
        ]);

        return $run;
    }

    /**
     * @param  list<string>  $permissions
     */
    private function staffUser(string $roleName, array $permissions = []): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::findOrCreate($roleName);

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission);
        }

        $user = User::factory()->create();
        $user->assignRole($role);

        if ($permissions !== []) {
            $user->givePermissionTo($permissions);
        }

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function activityProperties(ScheduleGenerationRun $run): array
    {
        $activity = DB::table('activity_log')
            ->where('subject_type', ScheduleGenerationRun::class)
            ->where('subject_id', $run->id)
            ->where('event', 'schedule_generation_run_published')
            ->first();

        $this->assertNotNull($activity);

        return json_decode((string) $activity->properties, true, 512, JSON_THROW_ON_ERROR);
    }
}
