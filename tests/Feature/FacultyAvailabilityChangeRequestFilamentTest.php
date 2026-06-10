<?php

namespace Tests\Feature;

use App\Actions\Scheduling\FacultyAvailabilityChangeRequestService;
use App\Actions\Scheduling\FacultyAvailabilityService;
use App\Filament\Resources\FacultyAvailabilityChangeRequests\FacultyAvailabilityChangeRequestResource;
use App\Filament\Resources\FacultyAvailabilityChangeRequests\Pages\CreateFacultyAvailabilityChangeRequest;
use App\Filament\Resources\FacultyAvailabilityChangeRequests\Pages\ListFacultyAvailabilityChangeRequests;
use App\Models\FacultyAvailabilityChangeRequest;
use App\Models\FacultyAvailabilityPeriod;
use App\Models\FacultyAvailabilitySubmission;
use App\Models\Term;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FacultyAvailabilityChangeRequestFilamentTest extends TestCase
{
    use RefreshDatabase;

    public function test_change_request_resource_is_lifecycle_action_based_not_generic_crud(): void
    {
        $resource = $this->resourceSource('FacultyAvailabilityChangeRequests/FacultyAvailabilityChangeRequestResource.php');
        $form = $this->resourceSource('FacultyAvailabilityChangeRequests/Schemas/FacultyAvailabilityChangeRequestForm.php');
        $table = $this->resourceSource('FacultyAvailabilityChangeRequests/Tables/FacultyAvailabilityChangeRequestsTable.php');
        $createPage = $this->resourceSource('FacultyAvailabilityChangeRequests/Pages/CreateFacultyAvailabilityChangeRequest.php');
        $viewPage = $this->resourceSource('FacultyAvailabilityChangeRequests/Pages/ViewFacultyAvailabilityChangeRequest.php');
        $provider = file_get_contents(app_path('Providers/AppServiceProvider.php'));

        $this->assertIsString($provider);
        $this->assertStringContainsString('FacultyAvailabilityChangeRequestPolicy::class', $provider);
        $this->assertStringContainsString('StaffRoleFaculty', $resource);
        $this->assertStringContainsString('getEloquentQuery', $resource);
        $this->assertStringNotContainsString('EditFacultyAvailabilityChangeRequest::route', $resource);
        $this->assertFileDoesNotExist(app_path('Filament/Resources/FacultyAvailabilityChangeRequests/Pages/EditFacultyAvailabilityChangeRequest.php'));
        $this->assertStringContainsString("Select::make('submission_id')", $form);
        $this->assertStringContainsString("Repeater::make('requested_windows')", $form);
        $this->assertStringContainsString("Textarea::make('reason')", $form);
        $this->assertStringNotContainsString("Select::make('status')", $form);
        $this->assertStringNotContainsString("TextInput::make('faculty_id')", $form);
        $this->assertStringContainsString('FacultyAvailabilityChangeRequestService', $createPage);
        $this->assertStringContainsString('requestChange', $createPage);
        $this->assertStringContainsString("Action::make('approve')", $table);
        $this->assertStringContainsString("Action::make('reject')", $table);
        $this->assertStringContainsString('FacultyAvailabilityChangeRequestService', $table);
        $this->assertStringContainsString('approve(', $table);
        $this->assertStringContainsString('reject(', $table);
        $this->assertStringNotContainsString('EditAction::make()', $table);
        $this->assertStringNotContainsString('DeleteAction::make()', $table);
        $this->assertStringNotContainsString('DeleteBulkAction::make()', $table);
        $this->assertStringNotContainsString('EditAction::make()', $viewPage);
    }

    public function test_faculty_can_create_request_and_registrar_can_approve_it_from_filament(): void
    {
        $registrar = $this->staffUser(User::StaffRoleRegistrar, ['review-lock-faculty-availability']);
        $faculty = $this->staffUser(User::StaffRoleFaculty, ['submit-faculty-availability']);
        $submission = $this->lockedSubmission($registrar, $faculty);

        $this->actingAs($faculty);

        $this->get(FacultyAvailabilityChangeRequestResource::getUrl('index'))->assertOk();
        $this->get(FacultyAvailabilityChangeRequestResource::getUrl('create'))->assertOk();

        Livewire::test(CreateFacultyAvailabilityChangeRequest::class)
            ->fillForm([
                'submission_id' => $submission->id,
                'requested_windows' => [
                    [
                        'day_of_week' => 3,
                        'starts_at' => '10:00:00',
                        'ends_at' => '13:00:00',
                        'notes' => 'Updated Wednesday availability.',
                    ],
                ],
                'reason' => 'Department consultation moved after the availability lock.',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $request = FacultyAvailabilityChangeRequest::query()->where('faculty_id', $faculty->id)->firstOrFail();

        $this->assertSame(FacultyAvailabilityChangeRequest::StatusPending, $request->status);
        $this->assertSame('10:00:00', $request->requested_windows[0]['starts_at']);

        $this->actingAs($registrar);

        Livewire::test(ListFacultyAvailabilityChangeRequests::class)
            ->callAction(TestAction::make('approve')->table($request), data: [
                'review_note' => 'Approved before regeneration.',
            ])
            ->assertHasNoFormErrors();

        $request->refresh();

        $this->assertSame(FacultyAvailabilityChangeRequest::StatusApproved, $request->status);
        $this->assertSame($registrar->id, $request->reviewed_by);
        $this->assertNotNull($request->creates_submission_id);
        $this->assertDatabaseHas('faculty_availability_submissions', [
            'id' => $request->creates_submission_id,
            'parent_submission_id' => $submission->id,
            'status' => FacultyAvailabilitySubmission::StatusLocked,
            'version' => 2,
        ]);
    }

    public function test_registrar_can_reject_pending_change_request_from_filament(): void
    {
        $registrar = $this->staffUser(User::StaffRoleRegistrar, ['review-lock-faculty-availability']);
        $faculty = $this->staffUser(User::StaffRoleFaculty, ['submit-faculty-availability']);
        $submission = $this->lockedSubmission($registrar, $faculty);
        $request = app(FacultyAvailabilityChangeRequestService::class)->requestChange($faculty, $submission, [
            'reason' => 'Late change request.',
            'requested_windows' => [
                ['day_of_week' => 4, 'starts_at' => '14:00:00', 'ends_at' => '17:00:00'],
            ],
        ]);

        $this->actingAs($registrar);

        Livewire::test(ListFacultyAvailabilityChangeRequests::class)
            ->callAction(TestAction::make('reject')->table($request), data: [
                'review_note' => 'Use official schedule change process.',
            ])
            ->assertHasNoFormErrors();

        $this->assertSame(FacultyAvailabilityChangeRequest::StatusRejected, $request->refresh()->status);
        $this->assertSame('Use official schedule change process.', $request->review_note);
        $this->assertNull($request->creates_submission_id);
    }

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (User::staffRoleNames() as $roleName) {
            Role::findOrCreate($roleName);
        }
    }

    private function lockedSubmission(User $registrar, User $faculty): FacultyAvailabilitySubmission
    {
        $term = Term::factory()->create([
            'term_name' => '1st Semester AY 2026',
            'term_start_date' => now()->addWeeks(2)->toDateString(),
            'term_end_date' => now()->addMonths(5)->toDateString(),
            'scheduling_starts_at' => now()->addDays(2),
        ]);
        $period = FacultyAvailabilityPeriod::factory()->create([
            'term_id' => $term->id,
            'opens_at' => now()->subHour(),
            'closes_at' => now()->addHour(),
            'status' => FacultyAvailabilityPeriod::StatusOpen,
            'created_by' => $registrar->id,
        ]);
        $submission = app(FacultyAvailabilityService::class)->submitAvailability([
            'availability_period_id' => $period->id,
            'windows' => [
                ['day_of_week' => 1, 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
            ],
        ], $faculty);

        return app(FacultyAvailabilityService::class)->lockSubmission($submission, $registrar);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function staffUser(string $roleName, array $permissions): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission);
        }

        $user = User::factory()->create();
        $user->assignRole($roleName);
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function resourceSource(string $relativePath): string
    {
        $source = file_get_contents(app_path("Filament/Resources/{$relativePath}"));

        $this->assertIsString($source);

        return $source;
    }
}
