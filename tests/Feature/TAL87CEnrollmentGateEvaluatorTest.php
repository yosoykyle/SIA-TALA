<?php

namespace Tests\Feature;

use App\Actions\Enrollment\RecordAcademicException;
use App\Filament\Resources\Enrollments\Pages\ViewEnrollment;
use App\Models\Enrollment;
use App\Models\EnrollmentException;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL87CEnrollmentGateEvaluatorTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function generic_gate_and_exception_actions_are_retired_from_the_canonical_surface(): void
    {
        $enrollment = Enrollment::factory()->create();
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $academicHead = $this->staff(User::StaffRoleAcademicHead);
        $accounting = $this->staff(User::StaffRoleAccounting);

        $registrarComponent = Livewire::actingAs($registrar)
            ->test(ViewEnrollment::class, ['record' => $enrollment->getRouteKey()])
            ->assertSee('Source evidence and case history');
        $academicHeadComponent = Livewire::actingAs($academicHead)
            ->test(ViewEnrollment::class, ['record' => $enrollment->getRouteKey()]);
        $accountingComponent = Livewire::actingAs($accounting)
            ->test(ViewEnrollment::class, ['record' => $enrollment->getRouteKey()]);

        foreach ([$registrarComponent, $academicHeadComponent, $accountingComponent] as $component) {
            $actionNames = collect($component->instance()->getCachedHeaderActions())
                ->flatMap(fn ($action): array => method_exists($action, 'getFlatActions') ? $action->getFlatActions() : [$action->getName() => $action])
                ->keys()
                ->all();
            $this->assertNotContains('refreshGateResults', $actionNames);
            $this->assertNotContains('academicException', $actionNames);
            $this->assertNotContains('unitLoadException', $actionNames);
        }

        $this->expectExceptionMessage('generic gate overrides and unit-load exceptions are not accepted here');
        app(RecordAcademicException::class)->record($enrollment, [
            'exception_type' => EnrollmentException::TypeGateOverride,
            'original_rule' => 'generic',
            'authority' => 'No authority',
            'reason' => 'Invalid generic override.',
            'evidence_reference' => 'INVALID-001',
            'expires_at' => now()->addMonth()->toDateTimeString(),
        ], $academicHead);
    }

    private function staff(string $role): User
    {
        Role::findOrCreate($role, 'web');
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
