<?php

namespace Tests\Feature;

use App\Filament\Resources\PromissoryNotes\Pages\ListPromissoryNotes;
use App\Models\Enrollment;
use App\Models\PromissoryNote;
use App\Models\StudentProfile;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SDD06CPromissoryFilamentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_accounting_can_approve_pending_request_from_the_queue(): void
    {
        $accounting = $this->accountingUser();
        $note = $this->pendingNote();

        $this->actingAs($accounting);

        Livewire::test(ListPromissoryNotes::class)
            ->callAction(TestAction::make('approve')->table($note))
            ->assertHasNoFormErrors()
            ->assertNotified();

        $this->assertSame(PromissoryNote::StatusApproved, $note->refresh()->status);
        $this->assertSame($accounting->id, $note->approved_by);
    }

    public function test_accounting_can_reject_pending_request_with_a_required_reason(): void
    {
        $accounting = $this->accountingUser();
        $note = $this->pendingNote();

        $this->actingAs($accounting);

        Livewire::test(ListPromissoryNotes::class)
            ->callAction(TestAction::make('reject')->table($note), data: [
                'rejection_reason' => 'Requested amount exceeds the documented need.',
            ])
            ->assertHasNoFormErrors()
            ->assertNotified();

        $note->refresh();

        $this->assertSame(PromissoryNote::StatusRejected, $note->status);
        $this->assertSame($accounting->id, $note->rejected_by);
        $this->assertSame('Requested amount exceeds the documented need.', $note->rejection_reason);
    }

    public function test_resource_uses_typed_lifecycle_actions_instead_of_generic_status_editing(): void
    {
        $form = file_get_contents(app_path('Filament/Resources/PromissoryNotes/Schemas/PromissoryNoteForm.php'));
        $table = file_get_contents(app_path('Filament/Resources/PromissoryNotes/Tables/PromissoryNotesTable.php'));
        $createPage = file_get_contents(app_path('Filament/Resources/PromissoryNotes/Pages/CreatePromissoryNote.php'));

        $this->assertIsString($form);
        $this->assertIsString($table);
        $this->assertIsString($createPage);
        $this->assertStringNotContainsString("Select::make('status')", $form);
        $this->assertStringContainsString("Action::make('approve')", $table);
        $this->assertStringContainsString("Action::make('reject')", $table);
        $this->assertStringContainsString("Action::make('cancel')", $table);
        $this->assertStringContainsString('PromissoryNoteLifecycleService', $table);
        $this->assertStringContainsString('PromissoryNoteLifecycleService', $createPage);
        $this->assertStringNotContainsString("\$data['status'] = 'approved';", $createPage);
    }

    private function pendingNote(): PromissoryNote
    {
        $student = StudentProfile::factory()->create(['current_balance' => '2500.00']);
        $enrollment = Enrollment::factory()->create(['student_profile_id' => $student->id]);

        return PromissoryNote::query()->create([
            'student_profile_id' => $student->id,
            'term_id' => $enrollment->term_id,
            'enrollment_id' => $enrollment->id,
            'amount' => '1500.00',
            'due_date' => now()->addDays(10),
            'status' => PromissoryNote::StatusPending,
            'reason' => 'Temporary financial emergency.',
            'requested_by' => $student->user_id,
            'requested_at' => now(),
            'request_source' => PromissoryNote::SourceStudent,
        ]);
    }

    private function accountingUser(): User
    {
        Permission::findOrCreate('approve-promissory-notes');
        $user = User::factory()->create();
        $user->givePermissionTo('approve-promissory-notes');

        return $user;
    }
}
