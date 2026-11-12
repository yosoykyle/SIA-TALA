<?php

namespace Tests\Feature;

use App\Actions\Academics\AcademicRecordNotificationService;
use App\Filament\Student\Pages\Academics;
use App\Filament\Student\Pages\Enrollment;
use App\Filament\Student\Pages\LifecycleView;
use App\Mail\AcademicRecordChangedMail;
use App\Models\OperationalEvent;
use App\Models\StudentLifecycleChange;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class StudentAppliedLifecycleHistoryTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame((string) env('TALA_TEST_DATABASE', 'test_tala_db'), DB::connection()->getDatabaseName());
        Role::query()->firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Filament::setCurrentPanel(Filament::getPanel('student'));
    }

    #[Test]
    public function applied_history_is_own_only_and_projects_no_private_evidence(): void
    {
        [$user, $student] = $this->studentWithProfile();
        [, $otherStudent] = $this->studentWithProfile();
        $term = Term::factory()->create(['label' => 'Applied history term']);
        $applied = $this->change($student, StudentLifecycleChange::StateApplied, [
            'term_id' => $term->id,
            'type' => StudentLifecycleChange::TypeProgramShift,
            'reason' => 'PRIVATE lifecycle reason',
            'authority' => 'PRIVATE lifecycle authority',
            'private_source_reference' => 'PRIVATE evidence reference',
        ]);
        $pending = $this->change($student, StudentLifecycleChange::StateRecordedApproved);
        $cancelled = $this->change($student, StudentLifecycleChange::StateCancelled);
        $foreign = $this->change($otherStudent, StudentLifecycleChange::StateApplied);

        $history = StudentLifecycleChange::query()->appliedForStudent($student)->get();
        $this->assertSame([$applied->id], $history->modelKeys());
        $this->assertSame('Program change', $history->sole()->studentLabel());
        foreach (['reason', 'authority', 'private_source_reference', 'impact_snapshot'] as $privateAttribute) {
            $this->assertArrayNotHasKey($privateAttribute, $history->sole()->getAttributes());
        }

        Livewire::actingAs($user)->withQueryParams(['view' => 'record'])->test(Academics::class)
            ->assertViewHas('lifecycleChanges', fn ($changes): bool => $changes->getCollection()->modelKeys() === [$applied->id])
            ->assertSee('Program change')
            ->assertSee('Applied history term')
            ->assertSee($term->academicYear->label)
            ->assertSee('Effective')
            ->assertDontSee('View all school record changes')
            ->assertDontSee('PRIVATE lifecycle reason')
            ->assertDontSee('PRIVATE lifecycle authority')
            ->assertDontSee('PRIVATE evidence reference');
    }

    #[Test]
    public function old_history_links_open_the_same_complete_record_and_unavailable_targets_recover_safely(): void
    {
        [$user, $student] = $this->studentWithProfile();
        [, $otherStudent] = $this->studentWithProfile();
        $applied = $this->change($student, StudentLifecycleChange::StateApplied);
        $otherApplied = $this->change($student, StudentLifecycleChange::StateApplied);
        $pending = $this->change($student, StudentLifecycleChange::StateRecordedApproved);
        $cancelled = $this->change($student, StudentLifecycleChange::StateCancelled);
        $foreign = $this->change($otherStudent, StudentLifecycleChange::StateApplied);

        Livewire::actingAs($user)->withQueryParams(['change' => $applied->id])->test(LifecycleView::class)
            ->assertRedirect(Academics::getUrl(['view' => 'record', 'change' => $applied->id], panel: 'student').'#school-record-changes');
        Livewire::actingAs($user)->withQueryParams([])->test(LifecycleView::class)
            ->assertRedirect(Academics::getUrl(['view' => 'record'], panel: 'student').'#school-record-changes');

        Livewire::actingAs($user)->withQueryParams(['view' => 'record', 'change' => $applied->id])->test(Academics::class)
            ->assertViewHas('lifecycleChanges', fn ($changes): bool => $changes->total() === 2)
            ->assertDontSee('This change is unavailable.');
        foreach ([$pending->id, $cancelled->id, $foreign->id, 999999999] as $unavailableId) {
            Livewire::actingAs($user)->withQueryParams(['view' => 'record', 'change' => $unavailableId])->test(Academics::class)
                ->assertSee('This change is unavailable. Your own applied changes are shown below.')
                ->assertViewHas('lifecycleChanges', fn ($changes): bool => $changes->total() === 2)
                ->assertDontSee('PRIVATE');
        }
    }

    #[Test]
    public function complete_history_pages_in_place_and_old_change_links_open_the_matching_page(): void
    {
        [$user, $student] = $this->studentWithProfile();
        $applied = collect(range(1, 23))->map(fn (int $day): StudentLifecycleChange => $this->change($student, StudentLifecycleChange::StateApplied, [
            'effective_on' => '2026-10-'.str_pad((string) $day, 2, '0', STR_PAD_LEFT),
        ]));
        $ordered = $applied->sortByDesc('effective_on')->pluck('id')->values();
        $component = Livewire::actingAs($user)->withQueryParams(['view' => 'record'])->test(Academics::class)
            ->assertViewHas('lifecycleChanges', fn ($changes): bool => $changes->total() === 23 && $changes->getCollection()->modelKeys() === $ordered->take(10)->all())
            ->assertDontSee('View all changes')
            ->assertDontSee('View change');
        $component->call('gotoPage', 2, 'changesPage')
            ->assertViewHas('lifecycleChanges', fn ($changes): bool => $changes->currentPage() === 2 && $changes->getCollection()->modelKeys() === $ordered->slice(10, 10)->values()->all());
        $component->call('gotoPage', 99, 'changesPage')
            ->assertViewHas('lifecycleChanges', fn ($changes): bool => $changes->currentPage() === 3 && $changes->count() === 3);
        Livewire::actingAs($user)->withQueryParams(['view' => 'record', 'change' => $applied->first()->id])->test(Academics::class)
            ->assertViewHas('lifecycleChanges', fn ($changes): bool => $changes->currentPage() === 3 && $changes->getCollection()->contains('id', $applied->first()->id));
        $this->assertSame(23, $student->lifecycleChanges()->where('state', StudentLifecycleChange::StateApplied)->count());
    }

    #[Test]
    public function lifecycle_notice_destination_tracks_applied_state_and_preserves_state_idempotency(): void
    {
        Mail::fake();
        [$user, $student] = $this->studentWithProfile();
        $change = $this->change($student, StudentLifecycleChange::StateRecordedApproved);
        $service = app(AcademicRecordNotificationService::class);

        DB::transaction(function () use ($service, $change, $user): void {
            $pendingNotice = $service->recordLifecycleAfterCommit($change);
            $this->assertSame($pendingNotice->id, $service->recordLifecycleAfterCommit($change)->id);
            $this->assertSame(Enrollment::getUrl(['term' => $change->term_id], isAbsolute: false, panel: 'student'), $pendingNotice->payload['action_path']);

            $change->update(['state' => StudentLifecycleChange::StateApplied]);
            $appliedNotice = $service->recordLifecycleAfterCommit($change);
            $this->assertSame($appliedNotice->id, $service->recordLifecycleAfterCommit($change)->id);
            $this->assertNotSame($pendingNotice->id, $appliedNotice->id);
            $this->assertSame(Academics::getUrl(['view' => 'record', 'change' => $change->id], isAbsolute: false, panel: 'student').'#school-record-changes', $appliedNotice->payload['action_path']);
            $this->assertSame('A school record update', $appliedNotice->payload['change_label']);
            $this->assertSame([], $appliedNotice->payload['delivery_attempts']);
            $this->assertSame(2, OperationalEvent::query()
                ->where('event_type', OperationalEvent::TypeAcademicProgressLifecycleEmail)
                ->where('user_id', $user->id)->where('related_record_id', $change->id)->count());
            Mail::assertNothingQueued();
        });

        Mail::assertQueued(AcademicRecordChangedMail::class, 2);
        Mail::assertQueued(AcademicRecordChangedMail::class, fn (AcademicRecordChangedMail $mail): bool => $mail->hasTo($user->email)
            && $mail->actionUrl === Academics::getUrl(['view' => 'record', 'change' => $change->id], panel: 'student').'#school-record-changes');
    }

    /** @return array{User, StudentProfile} */
    private function studentWithProfile(): array
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole('student');

        return [$user, StudentProfile::factory()->create(['user_id' => $user->id])];
    }

    /** @param array<string, mixed> $attributes */
    private function change(StudentProfile $student, string $state, array $attributes = []): StudentLifecycleChange
    {
        return StudentLifecycleChange::factory()->create([
            'student_profile_id' => $student->id,
            'state' => $state,
            ...$attributes,
        ]);
    }
}
