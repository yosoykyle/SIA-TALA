<?php

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\TestCase;
use Livewire\Livewire;
use TalaPreview\Components;

class NativePreviewTest extends TestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__).'/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $app->instance('env', 'testing');

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('preview'));
        Filament::bootCurrentPanel();
    }

    public function test_form_validation_and_native_notification_without_database(): void
    {
        $this->assertSame([], config('database.connections'));
        Livewire::test(Components::class)
            ->fillForm(['name' => '', 'email' => 'invalid', 'program' => null], 'form')
            ->assertSet('data.name', '')
            ->assertSet('data.email', 'invalid')
            ->call('save')
            ->assertHasFormErrors(['name' => 'required', 'email' => 'email', 'program' => 'required'], 'form')
            ->fillForm(['name' => 'Alex Santos', 'email' => 'alex@example.test', 'program' => 'bsit'], 'form')
            ->call('save')
            ->assertHasNoFormErrors([], 'form')
            ->assertNotified('Example validated');
    }

    public function test_table_filtering_and_empty_recovery(): void
    {
        Livewire::test(Components::class)
            ->sortTable('name', 'desc')
            ->assertSeeInOrder(['Morgan Cruz', 'Jamie Reyes', 'Alex Santos'])
            ->searchTable('Jamie')
            ->assertSee('Jamie Reyes')
            ->assertDontSee('Morgan Cruz')
            ->searchTable('no-matching-example')
            ->assertSee('No matching sample records');
    }

    public function test_confirmation_uses_native_action_lifecycle(): void
    {
        Livewire::test(Components::class)
            ->mountAction('confirmation')
            ->assertActionMounted('confirmation')
            ->callMountedAction()
            ->assertNotified('Example confirmed');
    }
}
