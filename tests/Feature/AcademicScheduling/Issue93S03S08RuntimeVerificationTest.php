<?php

namespace Tests\Feature\AcademicScheduling;

use App\Filament\Pages\TermCalendar;
use App\Filament\Pages\TermOverview;
use App\Filament\Pages\TermResources;
use App\Filament\Pages\TermTimetable;
use App\Filament\Resources\FacultyQualifications\FacultyQualificationResource;
use App\Filament\Resources\FacultyQualifications\Pages\ListFacultyQualifications;
use App\Filament\Resources\FacultyQualifications\Pages\ViewFacultyQualification;
use App\Filament\Resources\Terms\Pages\ViewTerm;
use App\Filament\Resources\Terms\TermResource;
use App\Models\Course;
use App\Models\CourseSpecification;
use App\Models\FacultyQualification;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\AcademicScheduling\Concerns\InteractsWithAcademicSchedulingFixtures;
use Tests\TestCase;

final class Issue93S03S08RuntimeVerificationTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAcademicSchedulingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', app()->environment());
        $this->assertSame(config('database.test_database'), DB::connection()->getDatabaseName());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    #[Test]
    public function s03_view_term_and_term_planning_pages_render_for_registrar_and_readonly_academic_head(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $academicHead = $this->staff(User::StaffRoleAcademicHead);

        $termWithoutCalendar = Term::factory()->create(['state' => Term::StateActive]);

        $this->asStaff($registrar, User::StaffRoleRegistrar)
            ->get(TermResource::getUrl('view', ['record' => $termWithoutCalendar]))
            ->assertOk()
            ->assertSee('Awaiting core calendar activation')
            ->assertSee('History')
            ->assertDontSee('Calendar history');

        $this->asStaff($registrar, User::StaffRoleRegistrar);
        Livewire::actingAs($registrar)
            ->test(ViewTerm::class, ['record' => $termWithoutCalendar->getRouteKey()])
            ->assertOk()
            ->assertSee('Awaiting core calendar activation')
            ->assertSee('History')
            ->assertActionVisible('edit');

        $this->asStaff($academicHead, User::StaffRoleAcademicHead);
        Livewire::actingAs($academicHead)
            ->test(ViewTerm::class, ['record' => $termWithoutCalendar->getRouteKey()])
            ->assertOk()
            ->assertSee('Awaiting core calendar activation')
            ->assertSee('History')
            ->assertActionHidden('edit');

        $termWithCalendar = Term::factory()->create(['state' => Term::StateActive]);
        $calendar = $this->activeCalendar($termWithCalendar, $registrar);

        $this->asStaff($registrar, User::StaffRoleRegistrar)
            ->get(TermResource::getUrl('view', ['record' => $termWithCalendar]))
            ->assertOk()
            ->assertSee('Active teaching calendar')
            ->assertSee('7:30 AM')
            ->assertSee('History')
            ->assertDontSee('Awaiting core calendar activation');

        $this->asStaff($academicHead, User::StaffRoleAcademicHead)
            ->get(TermResource::getUrl('view', ['record' => $termWithCalendar]))
            ->assertOk()
            ->assertSee('Active teaching calendar')
            ->assertSee($calendar->authority_reference);

        foreach ([User::StaffRoleRegistrar, User::StaffRoleAcademicHead] as $role) {
            $actor = $role === User::StaffRoleRegistrar ? $registrar : $academicHead;
            $this->asStaff($actor, $role)
                ->get(TermOverview::getUrl())
                ->assertOk();

            $this->asStaff($actor, $role)
                ->get(TermOverview::getUrl(['termId' => $termWithCalendar->id]))
                ->assertRedirect(TermCalendar::getUrl(['termId' => $termWithCalendar->id]));
        }

        $pages = [
            TermCalendar::class,
            TermResources::class,
            TermTimetable::class,
        ];

        foreach ($pages as $pageClass) {
            $url = $pageClass::getUrl(['termId' => $termWithCalendar->id]);

            $this->asStaff($registrar, User::StaffRoleRegistrar)
                ->get($url)
                ->assertOk();

            $this->asStaff($academicHead, User::StaffRoleAcademicHead)
                ->get($url)
                ->assertOk();
        }
    }

    #[Test]
    public function s08_faculty_qualifications_render_for_registrar_and_readonly_academic_head_and_handle_course_without_active_spec(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $academicHead = $this->staff(User::StaffRoleAcademicHead);
        $teacher = $this->staff(User::StaffRoleFaculty);

        $courseWithSpec = Course::factory()->create(['code' => 'CS 401']);
        CourseSpecification::factory()->for($courseWithSpec)->create([
            'title' => 'Distributed Systems',
            'state' => CourseSpecification::StateActive,
        ]);

        $courseWithoutActiveSpec = Course::factory()->create(['code' => 'CS 499']);

        $qualificationWithSpec = FacultyQualification::factory()->create([
            'faculty_user_id' => $teacher->id,
            'course_id' => $courseWithSpec->id,
            'recorded_by' => $registrar->id,
            'is_active' => true,
        ]);

        $qualificationWithoutSpec = FacultyQualification::factory()->create([
            'faculty_user_id' => $teacher->id,
            'course_id' => $courseWithoutActiveSpec->id,
            'recorded_by' => $registrar->id,
            'is_active' => true,
        ]);

        $this->asStaff($registrar, User::StaffRoleRegistrar)
            ->get(FacultyQualificationResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Distributed Systems')
            ->assertSee('CS 499');

        $this->asStaff($registrar, User::StaffRoleRegistrar);
        Livewire::actingAs($registrar)
            ->test(ListFacultyQualifications::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$qualificationWithSpec, $qualificationWithoutSpec])
            ->assertActionVisible('create');

        $this->asStaff($academicHead, User::StaffRoleAcademicHead);
        Livewire::actingAs($academicHead)
            ->test(ListFacultyQualifications::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$qualificationWithSpec, $qualificationWithoutSpec])
            ->assertActionHidden('create');

        $this->asStaff($registrar, User::StaffRoleRegistrar)
            ->get(FacultyQualificationResource::getUrl('view', ['record' => $qualificationWithSpec]))
            ->assertOk()
            ->assertSee('Distributed Systems')
            ->assertSee('CS 401');

        $this->asStaff($registrar, User::StaffRoleRegistrar);
        Livewire::actingAs($registrar)
            ->test(ViewFacultyQualification::class, ['record' => $qualificationWithoutSpec->getRouteKey()])
            ->assertOk()
            ->assertSee('Faculty qualification')
            ->assertSee('CS 499')
            ->assertSee('No active course title recorded')
            ->assertActionVisible('edit');

        $this->asStaff($academicHead, User::StaffRoleAcademicHead);
        Livewire::actingAs($academicHead)
            ->test(ViewFacultyQualification::class, ['record' => $qualificationWithoutSpec->getRouteKey()])
            ->assertOk()
            ->assertSee('CS 499')
            ->assertSee('No active course title recorded')
            ->assertActionHidden('edit');
    }

    #[Test]
    public function s08_faculty_direct_url_access_is_cleanly_authorized_without_server_error(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $otherFaculty = $this->staff(User::StaffRoleFaculty);

        $course = Course::factory()->create(['code' => 'IT 405']);
        CourseSpecification::factory()->for($course)->create([
            'title' => 'Information Assurance',
            'state' => CourseSpecification::StateActive,
        ]);

        $ownQualification = FacultyQualification::factory()->create([
            'faculty_user_id' => $faculty->id,
            'course_id' => $course->id,
            'recorded_by' => $registrar->id,
            'is_active' => true,
        ]);

        $otherQualification = FacultyQualification::factory()->create([
            'faculty_user_id' => $otherFaculty->id,
            'course_id' => $course->id,
            'recorded_by' => $registrar->id,
            'is_active' => true,
        ]);

        // FacultyQualificationPolicy allows Faculty read-only self-scoped access; verify clean 200 on own records and clean denial (403/404) on others (never 500).
        $this->asStaff($faculty, User::StaffRoleFaculty)
            ->get(FacultyQualificationResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Information Assurance');

        Livewire::actingAs($faculty)
            ->test(ListFacultyQualifications::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$ownQualification])
            ->assertCanNotSeeTableRecords([$otherQualification])
            ->assertActionHidden('create');

        $this->asStaff($faculty, User::StaffRoleFaculty)
            ->get(FacultyQualificationResource::getUrl('view', ['record' => $ownQualification]))
            ->assertOk()
            ->assertSee('Information Assurance');

        Livewire::actingAs($faculty)
            ->test(ViewFacultyQualification::class, ['record' => $ownQualification->getRouteKey()])
            ->assertOk()
            ->assertActionHidden('edit');

        $otherViewResponse = $this->asStaff($faculty, User::StaffRoleFaculty)
            ->get(FacultyQualificationResource::getUrl('view', ['record' => $otherQualification]));
        $this->assertContains(
            $otherViewResponse->getStatusCode(),
            [403, 404],
            'Expected clean 403/404 for Faculty viewing another teacher qualification, got '.$otherViewResponse->getStatusCode(),
        );
    }

    private function asStaff(User $user, string $role): static
    {
        return $this->withStaffSession($user, $role);
    }

    private function staff(string $role): User
    {
        $permissions = match ($role) {
            User::StaffRoleRegistrar => ['manage-schedules', 'manage-sections'],
            User::StaffRoleAcademicHead => ['authorize-overrides', 'view-global-records'],
            default => [],
        };

        return $this->createSchedulingStaff($role, $permissions, actAs: false, withMfa: true);
    }

    private function activeCalendar(Term $term, User $recorder): TermCalendarPackage
    {
        return $this->createActiveTermCalendar(
            term: $term,
            recorder: $recorder,
            days: [1],
            startsAt: '07:30:00',
            endsAt: '19:00:00',
            packageOverrides: [
                'version' => 1,
                'classes_start_on' => $term->starts_on,
                'classes_end_on' => $term->ends_on,
                'authority_reference' => 'MEMO-93-S03',
            ],
        );
    }
}
