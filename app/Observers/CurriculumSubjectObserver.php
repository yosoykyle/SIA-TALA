<?php

namespace App\Observers;

use App\Actions\AcademicFoundation\CurriculumScopeReadinessService;
use App\Models\CurriculumSubject;
use App\Models\User;

class CurriculumSubjectObserver
{
    public function __construct(private readonly CurriculumScopeReadinessService $readinessService) {}

    /**
     * Handle the CurriculumSubject "created" event.
     */
    public function created(CurriculumSubject $curriculumSubject): void
    {
        $this->markCurrentScope($curriculumSubject, 'Curriculum subject added.');
    }

    /**
     * Handle the CurriculumSubject "updated" event.
     */
    public function updated(CurriculumSubject $curriculumSubject): void
    {
        if (! $curriculumSubject->wasChanged($this->readinessFields())) {
            return;
        }

        $this->markCurrentScope($curriculumSubject, 'Curriculum subject scheduling fields changed.');

        if ($curriculumSubject->wasChanged(['curriculum_id', 'year_level', 'semester'])) {
            $this->markOriginalScope($curriculumSubject);
        }
    }

    /**
     * Handle the CurriculumSubject "deleted" event.
     */
    public function deleted(CurriculumSubject $curriculumSubject): void
    {
        $this->markCurrentScope($curriculumSubject, 'Curriculum subject removed.');
    }

    /**
     * Handle the CurriculumSubject "restored" event.
     */
    public function restored(CurriculumSubject $curriculumSubject): void
    {
        $this->markCurrentScope($curriculumSubject, 'Curriculum subject restored.');
    }

    /**
     * Handle the CurriculumSubject "force deleted" event.
     */
    public function forceDeleted(CurriculumSubject $curriculumSubject): void
    {
        $this->markCurrentScope($curriculumSubject, 'Curriculum subject permanently removed.');
    }

    /**
     * @return list<string>
     */
    private function readinessFields(): array
    {
        return [
            'curriculum_id',
            'subject_id',
            'year_level',
            'semester',
            'weekly_contact_hours',
            'academic_subject_type',
            'scheduling_group',
            'delivery_rule_override',
            'sort_order',
        ];
    }

    private function markCurrentScope(CurriculumSubject $curriculumSubject, string $reason): void
    {
        $this->readinessService->markNeedsReviewForCurriculumSubject(
            curriculumSubject: $curriculumSubject,
            actor: $this->actor(),
            reason: $reason,
        );
    }

    private function markOriginalScope(CurriculumSubject $curriculumSubject): void
    {
        $curriculumId = $curriculumSubject->getOriginal('curriculum_id');
        $yearLevel = $curriculumSubject->getOriginal('year_level');
        $semester = $curriculumSubject->getOriginal('semester');

        if ($curriculumId === null || blank($yearLevel) || blank($semester)) {
            return;
        }

        $this->readinessService->markNeedsReviewForValues(
            curriculumId: (int) $curriculumId,
            yearLevel: (string) $yearLevel,
            curriculumPeriod: (string) $semester,
            actor: $this->actor(),
            reason: 'Curriculum subject moved out of this readiness scope.',
        );
    }

    private function actor(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
