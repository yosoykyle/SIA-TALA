<?php

namespace Tests\Feature\StudentHub;

use App\Filament\Student\Pages\Profile;
use App\Models\StudentProfile;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StudentProfilePhotoTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame((string) env('TALA_TEST_DATABASE', 'test_tala_db'), DB::connection()->getDatabaseName());
    }

    public function test_student_can_replace_a_private_photo_without_changing_official_identity(): void
    {
        Storage::fake('local');
        $profile = $this->student();
        $identity = $profile->fresh()->getAttributes();
        $page = Livewire::test(Profile::class);

        $page->callAction('changePhoto', data: ['photo' => UploadedFile::fake()->image('photo.png')])
            ->assertHasNoActionErrors();
        $first = $profile->user->fresh()->avatar_path;
        Storage::disk('local')->assertExists($first);
        $this->assertStringStartsWith('profile-photos/'.$profile->user_id.'/', $first);
        Storage::disk('public')->assertMissing($first);
        $this->get(route('student.profile-photo'))->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertContent(Storage::disk('local')->get($first));

        $page->callAction('changePhoto', data: ['photo' => UploadedFile::fake()->image('new-photo.jpg')])
            ->assertHasNoActionErrors();
        $second = $profile->user->fresh()->avatar_path;
        $this->assertNotSame($first, $second);
        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertExists($second);
        $this->get(route('student.profile-photo'))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertSame($identity, $profile->fresh()->getAttributes());
        $this->assertStringStartsWith(route('student.profile-photo'), $profile->user->fresh()->getFilamentAvatarUrl());
        $this->assertArrayNotHasKey('avatar_path', $profile->user->fresh()->toArray());
    }

    public function test_invalid_or_oversized_photo_does_not_create_a_file_or_change_the_saved_photo(): void
    {
        Storage::fake('local');
        $profile = $this->student();
        $page = Livewire::test(Profile::class);

        $page->callAction('changePhoto', data: [
            'photo' => UploadedFile::fake()->createWithContent('not-a-photo.pdf', "%PDF-1.4\n%%EOF"),
        ])->assertHasActionErrors(['photo']);
        Livewire::test(Profile::class)->callAction('changePhoto', data: [
            'photo' => UploadedFile::fake()->image('large-photo.png')->size(2049),
        ])->assertHasActionErrors(['photo']);

        $this->assertNull($profile->user->fresh()->avatar_path);
        $this->assertSame([], Storage::disk('local')->allFiles('profile-photos'));
    }

    public function test_photo_endpoint_cannot_select_or_retrieve_another_students_private_file(): void
    {
        Storage::fake('local');
        $owner = $this->student();
        $other = StudentProfile::factory()->create();
        $foreignPath = 'profile-photos/'.$other->user_id.'/private.png';
        Storage::disk('local')->put($foreignPath, UploadedFile::fake()->image('private.png')->getContent());
        $other->user->forceFill(['avatar_path' => $foreignPath])->save();

        $this->get(route('student.profile-photo', ['user' => $other->user_id, 'path' => $foreignPath]))->assertNotFound();
        $this->assertNull($other->user->getFilamentAvatarUrl());
        $owner->user->forceFill(['avatar_path' => $foreignPath])->save();
        $this->get(route('student.profile-photo'))->assertNotFound();

        Storage::disk('local')->assertExists($foreignPath);
    }

    public function test_missing_unsupported_and_inaccessible_photos_keep_the_default_avatar_and_private_access(): void
    {
        Storage::fake('local');
        $this->get(route('student.profile-photo'))->assertRedirect();
        $profile = $this->student();
        $this->assertNull($profile->user->getFilamentAvatarUrl());
        $this->get(route('student.profile-photo'))->assertNotFound();

        $path = 'profile-photos/'.$profile->user_id.'/invalid.png';
        Storage::disk('local')->put($path, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $profile->user->forceFill(['avatar_path' => $path])->save();
        $this->get(route('student.profile-photo'))->assertStatus(415);

        $profile->update(['lifecycle_status' => StudentProfile::LifecycleArchived]);
        $this->get(route('student.profile-photo'))->assertForbidden();
        $this->actingAs(User::factory()->create())->get(route('student.profile-photo'))->assertForbidden();
    }

    public function test_staff_and_applicant_account_photos_are_served_privately_and_staff_can_view_student_profile_photos(): void
    {
        Storage::fake('local');

        $staff = User::factory()->create(['status' => User::StatusActive]);
        $staff->assignRole(Role::findOrCreate(User::StaffRoleAccounting, 'web'));
        $staffPhotoPath = 'profile-photos/'.$staff->id.'/staff.png';
        Storage::disk('local')->put($staffPhotoPath, UploadedFile::fake()->image('staff.png', 120, 120)->get());
        $staff->forceFill(['avatar_path' => $staffPhotoPath])->save();

        $this->actingAs($staff);
        $this->assertStringContainsString('/account/profile-photo', (string) $staff->getFilamentAvatarUrl());
        $this->get($staff->getFilamentAvatarUrl())
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $profile = StudentProfile::factory()->create();
        $profile->user->assignRole(Role::findOrCreate('student', 'web'));
        $studentPhotoPath = 'profile-photos/'.$profile->user_id.'/student.png';
        Storage::disk('local')->put($studentPhotoPath, UploadedFile::fake()->image('student.png', 120, 120)->get());
        $profile->user->forceFill(['avatar_path' => $studentPhotoPath])->save();

        $adminPhotoUrl = $profile->adminPhotoUrl();
        $this->assertNotNull($adminPhotoUrl);

        $this->actingAs($staff)
            ->get($adminPhotoUrl)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $applicant = User::factory()->create(['status' => User::StatusActive]);
        $applicant->assignRole(Role::findOrCreate('applicant', 'web'));
        $otherStudent = StudentProfile::factory()->create();
        $otherStudent->user->assignRole(Role::findOrCreate('student', 'web'));
        $this->actingAs($applicant)->get($adminPhotoUrl)->assertForbidden();
        $this->actingAs($otherStudent->user)->get($adminPhotoUrl)->assertForbidden();

        $staff->forceFill(['status' => User::StatusDisabled])->save();
        $this->actingAs($staff)->get(route('account.profile-photo'))->assertForbidden();
    }

    private function student(): StudentProfile
    {
        $profile = StudentProfile::factory()->create();
        $profile->user->assignRole(Role::findOrCreate('student', 'web'));
        Filament::setCurrentPanel(Filament::getPanel('student'));
        $this->actingAs($profile->user);

        return $profile;
    }
}
