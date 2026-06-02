<?php

namespace Tests\Feature;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UserCanonicalNamePartsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('first_name', 100)->nullable();
            $table->string('middle_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('suffix', 40)->nullable();
            $table->string('username')->nullable()->unique();
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('status', 80)->default('active');
            $table->timestamp('archived_at')->nullable();
            $table->text('archived_reason')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    public function test_user_name_parts_compose_the_display_name(): void
    {
        $user = User::factory()->create([
            ...User::staffNamePayload('Maria', 'Santos', 'Dela Cruz', 'Jr.'),
        ]);

        $this->assertSame('Maria Santos Dela Cruz Jr.', $user->fresh()->name);
        $this->assertSame('Maria', $user->first_name);
        $this->assertSame('Santos', $user->middle_name);
        $this->assertSame('Dela Cruz', $user->last_name);
        $this->assertSame('Jr.', $user->suffix);
    }

    public function test_fortify_registration_accepts_split_name_fields(): void
    {
        $user = app(CreateNewUser::class)->create([
            'first_name' => 'Juan',
            'middle_name' => 'Reyes',
            'last_name' => 'Santos',
            'suffix' => 'III',
            'email' => 'juan.santos@example.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertSame('Juan Reyes Santos III', $user->name);
        $this->assertSame('Juan', $user->first_name);
        $this->assertSame('Santos', $user->last_name);
    }

    public function test_fortify_profile_update_accepts_split_name_fields(): void
    {
        $user = User::factory()->create([
            ...User::staffNamePayload('Old', null, 'Name'),
            'email' => 'old-name@example.test',
        ]);

        app(UpdateUserProfileInformation::class)->update($user, [
            'first_name' => 'Updated',
            'middle_name' => null,
            'last_name' => 'Staff',
            'suffix' => null,
            'email' => 'updated-staff@example.test',
        ]);

        $user->refresh();

        $this->assertSame('Updated Staff', $user->name);
        $this->assertSame('Updated', $user->first_name);
        $this->assertSame('Staff', $user->last_name);
        $this->assertSame('updated-staff@example.test', $user->email);
    }

    public function test_legacy_full_name_input_remains_supported_for_existing_auth_flows(): void
    {
        $user = app(CreateNewUser::class)->create([
            'name' => 'Legacy Full Name',
            'email' => 'legacy@example.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertSame('Legacy Full Name', $user->name);
        $this->assertNull($user->first_name);
        $this->assertNull($user->last_name);
    }

    public function test_staff_account_form_uses_split_name_fields_not_raw_full_name_input(): void
    {
        $formSource = file_get_contents(app_path('Filament/Resources/Users/Schemas/UserForm.php'));

        $this->assertIsString($formSource);
        $this->assertStringContainsString("TextInput::make('first_name')", $formSource);
        $this->assertStringContainsString("TextInput::make('middle_name')", $formSource);
        $this->assertStringContainsString("TextInput::make('last_name')", $formSource);
        $this->assertStringContainsString("TextInput::make('suffix')", $formSource);
        $this->assertStringNotContainsString("TextInput::make('name')", $formSource);
    }
}
