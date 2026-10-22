<?php

namespace Tests\Feature;

use App\Actions\Enrollment\PrepareRegistrationProposal;
use App\Actions\Enrollment\PrepareStandardRegistration;
use App\Actions\Enrollment\StartRegistrationCase;
use App\Filament\Resources\Enrollments\Pages\ViewEnrollment;
use App\Models\CurriculumEntry;
use App\Models\Enrollment;
use App\Models\PublishedTimetableMeeting;
use App\Models\PublishedTimetableVersion;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use Filament\Actions\ActionGroup;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL67EnrollmentPlacementTest extends TestCase
{
    use DatabaseTransactions;

    public function test_standard_preparation_keeps_multi_year_offerings_in_the_earliest_unsatisfied_curriculum_position(): void
    {
        $profile = StudentProfile::factory()->create();
        $term = Term::factory()->create(['type' => Term::TypeFirstSemester, 'state' => Term::StateActive]);
        $case = Enrollment::factory()->for($profile)->for($term)->create();
        $timetable = PublishedTimetableVersion::factory()->for($term)->create();
        $sections = [];
        foreach (['First Year', 'Second Year'] as $index => $year) {
            $entry = CurriculumEntry::factory()->for($profile->curriculumVersion)->create([
                'year_level' => $year, 'term_type' => $term->type, 'sequence' => $index + 1,
            ]);
            $offering = TermOffering::factory()->for($term)->for($entry)->create(['state' => TermOffering::StateScheduled]);
            $section = Section::factory()->for($offering, 'termOffering')->create(['state' => Section::StateOpen]);
            PublishedTimetableMeeting::factory()->for($timetable, 'timetableVersion')->create(['section_id' => $section->id]);
            $sections[] = $section;
        }

        $this->assertSame([$sections[0]->id], app(PrepareStandardRegistration::class)->sectionIds($case));
        $timetable->update(['state' => PublishedTimetableVersion::StateSuperseded]);
        Role::findOrCreate(User::StaffRoleRegistrar, 'web');
        $registrar = User::factory()->create(['status' => User::StatusActive]);
        $registrar->assignRole(User::StaffRoleRegistrar);
        try {
            app(PrepareRegistrationProposal::class)->execute($case, $registrar, [$sections[0]->id], $case->lock_version);
            $this->fail('A superseded timetable must not substitute for a current published source.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('timetable', $exception->errors());
            $this->assertSame(0, $case->proposalVersions()->count());
        }

        $this->assertSame(0, $case->courseEnrollments()->count());
        $this->assertSame(0, $case->seatReservations()->count());
    }

    public function test_non_active_lifecycle_blocks_registration_before_schedule_and_seat_effects(): void
    {
        Role::findOrCreate(User::StaffRoleRegistrar, 'web');
        $registrar = User::factory()->create(['status' => User::StatusActive]);
        $registrar->assignRole(User::StaffRoleRegistrar);
        $term = Term::factory()->create(['state' => Term::StateActive]);
        foreach ([StudentProfile::LifecycleCompleted, StudentProfile::LifecycleWithdrawn, StudentProfile::LifecycleInactive] as $lifecycle) {
            $profile = StudentProfile::factory()->create(['lifecycle_status' => $lifecycle]);
            try {
                app(StartRegistrationCase::class)->forContinuingStudent($profile, $term, $registrar, 'RegistrarAssisted', 'SYN-LIFECYCLE-CHECK');
                $this->fail('A blocked Student lifecycle must not create a Registration Case.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('student_profile', $exception->errors());
            }
            $this->assertSame(0, Enrollment::query()->where('credential_user_id', $profile->user_id)->count());
        }
    }

    public function test_legacy_placement_action_is_retired_from_the_canonical_registration_surface(): void
    {
        $case = Enrollment::factory()->create();
        foreach ([User::StaffRoleRegistrar, User::StaffRoleAcademicHead] as $role) {
            Role::findOrCreate($role, 'web');
            $actor = User::factory()->create(['status' => User::StatusActive]);
            $actor->assignRole($role);
            $actions = collect(Livewire::actingAs($actor)->test(ViewEnrollment::class, ['record' => $case->getRouteKey()])
                ->instance()->getCachedHeaderActions())
                ->flatMap(fn ($action): array => $action instanceof ActionGroup ? $action->getFlatActions() : [$action->getName() => $action]);
            $this->assertArrayNotHasKey('confirmPlacement', $actions->all());
        }
    }
}
