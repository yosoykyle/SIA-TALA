<?php

namespace Tests\Feature;

use App\Filament\Resources\Enrollments\EnrollmentResource;
use App\Filament\Resources\Enrollments\Pages\ListEnrollments;
use App\Filament\Resources\Enrollments\Pages\ViewEnrollment;
use App\Models\CorVersion;
use App\Models\Enrollment;
use App\Models\RegistrationCaseEvent;
use App\Models\StudentProfile;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class EnrollmentRecordPresentationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', app()->environment());
        $this->assertSame((string) env('TALA_TEST_DATABASE', 'test_tala_db'), DB::connection()->getDatabaseName());
        Role::findOrCreate(User::StaffRoleRegistrar, 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_missing_certificate_preserves_official_subject_context_and_queue_navigation(): void
    {
        [$registrar, $case, $profile] = $this->fixture();
        $page = Livewire::actingAs($registrar)->withQueryParams(['queue' => 'all_cases'])
            ->test(ViewEnrollment::class, ['record' => $case->id])
            ->assertSuccessful()
            ->assertSee('Enrollment record')->assertSee('Record Learner')
            ->assertSee($profile->student_number)->assertSee('Officially enrolled')
            ->assertSee('Registration certificate source unavailable')->assertSee('No active subjects')
            ->assertDontSee('View registration certificate')->assertDontSee('Add a subject');

        $this->assertSame('Enrollment record', $page->instance()->getTitle());
        $this->assertArrayHasKey(EnrollmentResource::getUrl('index', ['tab' => 'all_cases']), $page->instance()->getBreadcrumbs());
        $page->assertSeeHtml(e(EnrollmentResource::getUrl('view', ['record' => $case, 'view' => 'history', 'queue' => 'all_cases'])));
        $this->assertNull($case->fresh()->current_cor_version_id);
    }

    public function test_available_certificate_retains_the_exact_output_destination(): void
    {
        [$registrar, $case] = $this->fixture();
        $certificate = CorVersion::factory()->create(['enrollment_id' => $case->id]);
        $case->update(['current_cor_version_id' => $certificate->id]);

        Livewire::actingAs($registrar)->test(ViewEnrollment::class, ['record' => $case->id])
            ->assertSuccessful()->assertSee('View registration certificate')
            ->assertDontSee('Registration certificate source unavailable')
            ->assertSeeHtml(e(route('cor.print', $case)));
    }

    public function test_directory_uses_the_same_student_identity_and_can_find_it_by_name(): void
    {
        [$registrar, $case, $profile] = $this->fixture();
        $case->credentialUser->update(['name' => 'Different login identity']);

        Livewire::actingAs($registrar)->test(ListEnrollments::class)
            ->set('activeTab', 'all_cases')
            ->searchTable('Record Learner')
            ->assertCanSeeTableRecords([$case])
            ->assertSee('Record Learner')->assertSee($profile->student_number)
            ->assertDontSee('Different login identity')
            ->assertSeeHtml(e(EnrollmentResource::getUrl('view', ['record' => $case, 'queue' => 'all_cases'])))
            ->searchTable('No such learner 78')->assertCanNotSeeTableRecords([$case]);
    }

    public function test_history_renders_recorded_events_and_retained_certificate_without_inventing_activity(): void
    {
        [$registrar, $case] = $this->fixture();
        $certificate = CorVersion::factory()->create(['enrollment_id' => $case->id, 'version' => 1]);
        RegistrationCaseEvent::factory()->create([
            'enrollment_id' => $case->id, 'event_type' => 'CourseDrop', 'sequence' => 1,
            'from_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
            'to_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
            'reason' => 'Recorded course withdrawal reason', 'actor_id' => $registrar->id,
            'authority_reference' => 'Existing school reference',
        ]);
        $page = Livewire::actingAs($registrar)->withQueryParams(['view' => 'history', 'queue' => 'all_cases'])
            ->test(ViewEnrollment::class, ['record' => $case->id])
            ->assertSuccessful()->assertSee('Enrollment history')->assertSee('Subject dropped')
            ->assertSee('Recorded course withdrawal reason')->assertSee($registrar->getFilamentName())
            ->assertSee('Existing school reference')->assertSee('Earlier certificate')
            ->assertDontSee('No enrollment activity recorded')->assertDontSee('Previous class')
            ->assertSeeHtml(e(route('cor.print', ['enrollment' => $case, 'version' => $certificate->version])));
        $this->assertSame('Enrollment history', $page->instance()->getTitle());
        $page->assertSeeHtml(e(EnrollmentResource::getUrl('view', ['record' => $case, 'queue' => 'all_cases'])));
    }

    /** @return array{User, Enrollment, StudentProfile} */
    private function fixture(): array
    {
        $registrar = User::factory()->create(['status' => User::StatusActive]);
        $registrar->assignRole(User::StaffRoleRegistrar);
        $profile = StudentProfile::factory()->create(['first_name' => 'Record', 'middle_name' => null, 'last_name' => 'Learner']);
        $case = Enrollment::factory()->for($profile)->create([
            'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
            'status' => 'officially_enrolled', 'officially_enrolled_at' => now(),
        ]);

        return [$registrar, $case, $profile];
    }
}
