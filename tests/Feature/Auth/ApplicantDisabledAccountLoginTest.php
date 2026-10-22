<?php

namespace Tests\Feature\Auth;

use App\Filament\Pages\Auth\ContextualLogin;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ApplicantDisabledAccountLoginTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_disabled_applicant_with_correct_password_sees_disabled_access_not_the_generic_failure(): void
    {
        Role::findOrCreate('applicant', 'web');
        $applicant = User::factory()->create([
            'status' => User::StatusDisabled,
            'email_verified_at' => now(),
            'password' => 'a secure password 2026',
        ]);
        $applicant->assignRole('applicant');
        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        $component = Livewire::test(ContextualLogin::class)
            ->set('data.email', $applicant->email)
            ->set('data.password', 'a secure password 2026')
            ->call('authenticate');

        $component->assertHasErrors(['data.email']);
        $this->assertStringContainsString('disabled', (string) $component->errors()->first('data.email'));
        $this->assertGuest();
    }
}
