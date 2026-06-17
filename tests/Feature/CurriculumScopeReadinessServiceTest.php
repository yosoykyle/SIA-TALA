<?php

namespace Tests\Feature;

use App\Actions\AcademicFoundation\CurriculumScopeReadinessService;
use App\Models\Curriculum;
use App\Models\CurriculumReadinessScope;
use App\Models\CurriculumSubject;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CurriculumScopeReadinessServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_scope_cannot_be_marked_ready_until_required_scheduling_fields_exist(): void
    {
        $service = app(CurriculumScopeReadinessService::class);
        $actor = User::factory()->create();
        $curriculum = Curriculum::factory()->create();
        $subject = Subject::factory()->create(['code' => 'IT101']);
        $curriculumSubject = CurriculumSubject::factory()->create([
            'curriculum_id' => $curriculum->id,
            'subject_id' => $subject->id,
            'year_level' => '1st Year',
            'semester' => '1st Semester',
            'weekly_contact_hours' => null,
            'academic_subject_type' => null,
            'scheduling_group' => null,
        ]);
        $scope = $service->scopeFor($curriculum, '1st Year', '1st Semester');

        try {
            $service->markReady($scope, $actor);
            $this->fail('Expected incomplete curriculum scope to block readiness.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('readiness', $exception->errors());
        }

        $this->assertSame(CurriculumReadinessScope::StatusBlocked, $scope->refresh()->status);
        $this->assertContains('IT101:weekly_contact_hours_required', $scope->last_blockers);

        $curriculumSubject->update([
            'weekly_contact_hours' => '3.00',
            'academic_subject_type' => CurriculumSubject::AcademicSubjectTypeMajor,
            'scheduling_group' => CurriculumSubject::SchedulingGroupLecture,
        ]);

        $readyScope = $service->markReady($scope->refresh(), $actor, 'Reviewed against official curriculum.');

        $this->assertSame(CurriculumReadinessScope::StatusReadyForScheduling, $readyScope->status);
        $this->assertSame([], $readyScope->last_blockers);
    }

    public function test_all_excluded_auto_schedule_scope_requires_reviewer_reason(): void
    {
        $service = app(CurriculumScopeReadinessService::class);
        $actor = User::factory()->create();
        $curriculum = Curriculum::factory()->create();
        CurriculumSubject::factory()
            ->excludedFromAutoSchedule()
            ->create([
                'curriculum_id' => $curriculum->id,
                'year_level' => '1st Year',
                'semester' => '1st Semester',
            ]);
        $scope = $service->scopeFor($curriculum, '1st Year', '1st Semester');

        try {
            $service->markReady($scope, $actor);
            $this->fail('Expected all-excluded auto-schedule scope to require a reason.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString(
                'all_subjects_excluded_from_auto_schedule_requires_reviewer_reason',
                $exception->getMessage(),
            );
        }

        $readyScope = $service->markReady($scope->refresh(), $actor, 'All subjects are intentionally handled outside auto-scheduling.');

        $this->assertSame(CurriculumReadinessScope::StatusReadyForScheduling, $readyScope->status);
        $this->assertSame('All subjects are intentionally handled outside auto-scheduling.', $readyScope->last_transition_reason);
    }

    public function test_curriculum_subject_edit_resets_ready_scope_to_needs_review(): void
    {
        $service = app(CurriculumScopeReadinessService::class);
        $actor = User::factory()->create();
        $curriculum = Curriculum::factory()->create();
        $curriculumSubject = CurriculumSubject::factory()->create([
            'curriculum_id' => $curriculum->id,
            'year_level' => '1st Year',
            'semester' => '1st Semester',
        ]);
        $scope = $service->markReady(
            $service->scopeFor($curriculum, '1st Year', '1st Semester'),
            $actor,
            'Initial curriculum review complete.',
        );

        $curriculumSubject->update([
            'weekly_contact_hours' => '4.00',
        ]);

        $this->assertSame(CurriculumReadinessScope::StatusNeedsReview, $scope->refresh()->status);
        $this->assertSame('Curriculum subject scheduling fields changed.', $scope->last_transition_reason);
    }
}
