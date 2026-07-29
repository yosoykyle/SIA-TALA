<?php

namespace Tests\Feature;

use App\Actions\Graduation\GraduationEligibilitySnapshotService;
use App\Filament\Pages\FacultyGradeRoster;
use App\Filament\Resources\GradeRosters\Pages\ListGradeRosters;
use App\Filament\Resources\GraduationReviewBatches\Pages\ViewGraduationReviewBatch;
use App\Filament\Resources\GraduationReviewBatches\RelationManagers\MembersRelationManager;
use App\Filament\Resources\StudentLifecycleChanges\Pages\ListStudentLifecycleChanges;
use App\Filament\Resources\StudentLifecycleChanges\Pages\ViewStudentLifecycleChange;
use App\Filament\Student\Pages\GradesView;
use App\Filament\Student\Pages\LifecycleView;
use App\Models\GradeRoster;
use App\Models\GradeRosterRow;
use App\Models\GraduationReviewBatch;
use App\Models\GraduationSnapshot;
use App\Models\Hold;
use App\Models\Section;
use App\Models\StudentLifecycleChange;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use Database\Seeders\TAL96D4BAcceptanceStateSeeder;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL96D4BGradesLifecycleHardeningTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());

        foreach ([User::StaffRoleRegistrar, User::StaffRoleFaculty, 'student'] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    #[Test]
    public function grade_workspaces_keep_actions_discoverable_on_small_screens(): void
    {
        $faculty = $this->staff(User::StaffRoleFaculty);
        $this->actingAs($faculty);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $facultyPage = Livewire::test(FacultyGradeRoster::class)->instance();
        $this->assertInstanceOf(FacultyGradeRoster::class, $facultyPage);
        $facultyTable = $facultyPage->getTable();
        $this->assertTrue($facultyTable->isStackedOnMobile());

        $registrar = $this->staff(User::StaffRoleRegistrar);
        $this->actingAs($registrar);
        $registrarPage = Livewire::test(ListGradeRosters::class)->instance();
        $this->assertInstanceOf(ListGradeRosters::class, $registrarPage);
        $registrarTable = $registrarPage->getTable();

        $this->assertTrue($registrarTable->isStackedOnMobile());
        $this->assertCount(1, $registrarTable->getRecordActions());
        $this->assertInstanceOf(ActionGroup::class, $registrarTable->getRecordActions()[0]);
    }

    #[Test]
    public function lifecycle_workspaces_present_structured_impact_and_mobile_safe_actions(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $change = StudentLifecycleChange::factory()->create([
            'impact_snapshot' => [
                'course_enrollment_ids' => [11, 12],
                'binding_ids' => [21],
                'reservation_ids' => [31],
                'master_schedule_changes' => 0,
                'profile_status_after' => 'withdrawn',
                'finance_adjustment' => 1250,
                'cor_available_after' => false,
            ],
        ]);
        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $lifecyclePage = Livewire::test(ListStudentLifecycleChanges::class)->instance();
        $this->assertInstanceOf(ListStudentLifecycleChanges::class, $lifecyclePage);
        $table = $lifecyclePage->getTable();
        $this->assertTrue($table->isStackedOnMobile());
        $this->assertCount(1, $table->getRecordActions());
        $this->assertInstanceOf(ActionGroup::class, $table->getRecordActions()[0]);

        Livewire::test(ViewStudentLifecycleChange::class, ['record' => $change->getRouteKey()])
            ->assertSee('Affected subjects')
            ->assertSee('Student status after action')
            ->assertSee('COR availability after action')
            ->assertDontSee('"course_enrollment_ids"', false);
    }

    #[Test]
    public function graduation_member_picker_is_not_limited_to_the_first_one_hundred_students(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $batch = GraduationReviewBatch::factory()->create(['created_by' => $registrar->id]);
        $sharedProfile = StudentProfile::factory()->create();
        StudentProfile::factory()->count(101)->create([
            'program_id' => $sharedProfile->program_id,
            'curriculum_version_id' => $sharedProfile->curriculum_version_id,
        ]);
        $target = StudentProfile::factory()->create([
            'student_number' => 'TAL-D4B-SEARCH-999',
            'last_name' => 'Reachable',
            'first_name' => 'Student',
        ]);
        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = Livewire::test(MembersRelationManager::class, [
            'ownerRecord' => $batch,
            'pageClass' => ViewGraduationReviewBatch::class,
        ]);
        $relationManager = $component->instance();
        $this->assertInstanceOf(MembersRelationManager::class, $relationManager);
        $table = $relationManager->getTable();

        $this->assertTrue($table->isStackedOnMobile());
        $this->assertCount(1, $table->getRecordActions());
        $this->assertInstanceOf(ActionGroup::class, $table->getRecordActions()[0]);

        $results = MembersRelationManager::searchableStudentOptions('TAL-D4B-SEARCH-999');
        $this->assertArrayHasKey($target->id, $results);
        $this->assertStringContainsString('Reachable, Student', $results[$target->id]);
    }

    #[Test]
    public function student_grade_and_lifecycle_projections_are_mobile_safe_and_plain_language(): void
    {
        $student = $this->staff('student');
        StudentProfile::factory()->create([
            'user_id' => $student->id,
            'academic_standing' => 'good_standing',
        ]);
        $this->actingAs($student);
        Filament::setCurrentPanel(Filament::getPanel('student'));

        $gradesPage = Livewire::test(GradesView::class)->instance();
        $this->assertInstanceOf(GradesView::class, $gradesPage);
        $this->assertTrue($gradesPage->getTable()->isStackedOnMobile());
        $lifecycle = Livewire::test(LifecycleView::class);
        $studentLifecyclePage = $lifecycle->instance();
        $this->assertInstanceOf(LifecycleView::class, $studentLifecyclePage);
        $this->assertTrue($studentLifecyclePage->getTable()->isStackedOnMobile());
        $lifecycle->assertSee('Good Standing');
    }

    #[Test]
    public function acceptance_state_overlay_is_guarded_and_idempotent(): void
    {
        $this->staff(User::StaffRoleFaculty);
        $this->staff(User::StaffRoleRegistrar);
        $term = Term::factory()->create();
        $sharedProfile = StudentProfile::factory()->create();
        StudentProfile::factory()->count(3)->create([
            'program_id' => $sharedProfile->program_id,
            'curriculum_version_id' => $sharedProfile->curriculum_version_id,
        ]);
        $offerings = TermOffering::factory()->count(4)->create(['term_id' => $term->id]);
        $offerings->each(fn (TermOffering $offering) => Section::factory()->create(['term_offering_id' => $offering->id]));

        app(TAL96D4BAcceptanceStateSeeder::class)->run();
        $this->artisan('acceptance:seed-tal96d4b-states')->assertSuccessful();

        $seededTerm = Term::query()
            ->whereHas('termOfferings', fn ($query) => $query->whereHas('sections'), '>=', 4)
            ->withCount('termOfferings')
            ->orderByDesc('term_offerings_count')
            ->orderByDesc('id')
            ->firstOrFail();
        $seededOfferingIds = TermOffering::query()
            ->where('term_id', $seededTerm->id)
            ->whereHas('sections')
            ->orderBy('id')
            ->limit(4)
            ->pluck('id');
        $seededFacultyId = User::role(User::StaffRoleFaculty)->orderBy('id')->value('id');
        $seededRegistrarId = User::role(User::StaffRoleRegistrar)->orderBy('id')->value('id');

        $this->assertNotNull($seededFacultyId);
        $this->assertNotNull($seededRegistrarId);
        $seededRosterIds = GradeRoster::query()
            ->where('faculty_user_id', $seededFacultyId)
            ->whereIn('term_offering_id', $seededOfferingIds)
            ->pluck('id');
        $this->assertCount(4, $seededRosterIds);
        GradeRoster::query()->with('section')->get()->each(function (GradeRoster $roster): void {
            $this->assertSame($roster->term_offering_id, $roster->section->term_offering_id);
        });
        $releasedRow = GradeRosterRow::query()
            ->whereIn('grade_roster_id', $seededRosterIds)
            ->whereNotNull('released_at')
            ->sole();
        $this->assertSame('89.8000', $releasedRow->computed_average);
        $this->assertSame('1.75', $releasedRow->current_outcome_code);
        $this->assertSame(1, StudentLifecycleChange::query()->where('private_source_reference', 'TAL-96D4B-WITHDRAWAL')->count());
        $this->assertSame(1, StudentLifecycleChange::query()->where('private_source_reference', 'TAL-96D4B-PROGRAM-SHIFT')->count());
        $programShift = StudentLifecycleChange::query()->where('private_source_reference', 'TAL-96D4B-PROGRAM-SHIFT')->sole();
        $sourceProgramId = StudentProfile::query()->findOrFail($programShift->student_profile_id)->program_id;
        $this->assertNotSame($sourceProgramId, $programShift->target_program_id);
        $this->assertTrue($programShift->term->starts_on->isFuture());
        $this->assertSame(1, $programShift->programShiftCredits()->count());
        $batch = GraduationReviewBatch::query()
            ->where('name', TAL96D4BAcceptanceStateSeeder::BatchName)
            ->sole();
        $this->assertSame(
            2,
            GraduationSnapshot::query()
                ->whereHas('member', fn ($query) => $query->where('graduation_review_batch_id', $batch->id))
                ->where('generated_by', $seededRegistrarId)
                ->count(),
        );
        $blockedSnapshot = GraduationSnapshot::query()
            ->where('result_status', GraduationEligibilitySnapshotService::ResultBlockedHoldOrClearance)
            ->whereHas('member', fn ($query) => $query->where('graduation_review_batch_id', $batch->id))
            ->with('member')
            ->sole();
        $this->assertTrue(Hold::query()
            ->where('student_profile_id', $blockedSnapshot->member->student_profile_id)
            ->where('status', Hold::StatusActive)
            ->where('blocking_level', Hold::BlockingGraduationEligibility)
            ->exists());
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
