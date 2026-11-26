<?php

namespace App\Actions\Enrollment;

use App\Actions\Academics\IrregularStudentReasons;
use App\Models\Enrollment;
use App\Models\IrregularEnrollmentRequest;
use App\Models\Section;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records a student's "Enroll as irregular" request for an Individually Advised case (#99 Scope 11).
 *
 * The request only proposes classes. The Registrar still prepares the official proposal from it
 * (PRD 04 §2.1), so seats are not reserved here.
 */
class SubmitIrregularEnrollmentRequest
{
    public function __construct(
        private readonly RegistrationClassChoices $classChoices,
        private readonly IrregularStudentReasons $reasons,
    ) {}

    /**
     * @param  list<int|string>  $sectionIds
     * @param  list<string>  $documents
     */
    public function execute(Enrollment $case, User $actor, array $sectionIds, ?string $remarks, array $documents = []): IrregularEnrollmentRequest
    {
        $case->loadMissing('studentProfile');
        $student = $case->studentProfile;

        if ($student === null || (int) $case->credential_user_id !== (int) $actor->id || ! $actor->hasRole('student')) {
            throw ValidationException::withMessages(['request' => 'Only the student can submit this irregular enrollment request.']);
        }
        if ($case->canonical_outcome !== Enrollment::OutcomeInProgress || $case->selection_basis !== Enrollment::SelectionIndividuallyAdvised) {
            throw ValidationException::withMessages(['request' => 'Irregular enrollment requests are only for open Individually Advised enrollments.']);
        }
        if ($case->current_proposal_version_id !== null) {
            throw ValidationException::withMessages(['request' => 'The Registrar already prepared your subjects. Review them on this page.']);
        }

        $existing = IrregularEnrollmentRequest::query()->where('enrollment_id', $case->id)->first();
        if ($existing !== null && $existing->state !== IrregularEnrollmentRequest::StateReturned) {
            throw ValidationException::withMessages(['request' => 'Your irregular application is already with the Registrar. You can change it only if the Registrar returns it for correction.']);
        }

        $choices = $this->classChoices->forCase($case);
        $selected = collect($sectionIds)->map(fn (mixed $id): int => (int) $id)->filter()->unique()->values();
        if ($selected->isEmpty()) {
            throw ValidationException::withMessages(['section_ids' => 'Choose at least one class.']);
        }

        /** @var Collection<int, Section> $sections */
        $sections = $choices['sections']->whereIn('id', $selected)->values();
        if ($sections->count() !== $selected->count()) {
            throw ValidationException::withMessages(['section_ids' => 'One of the chosen classes is no longer offered to you. Review your choices.']);
        }

        $options = $this->classChoices->sectionOptions($case, $selected->all(), $choices);
        $courseIds = collect();
        foreach ($sections as $section) {
            $courseId = (int) ($section->termOffering?->courseSpecification()?->course_id ?? 0);
            $label = $section->termOffering?->course()?->code ?? $section->code;
            if ($courseIds->contains($courseId)) {
                throw ValidationException::withMessages(['section_ids' => 'Choose only one class for '.$label.'.']);
            }
            $courseIds->push($courseId);
            if ($this->classChoices->seats($case, $section)['remaining'] <= 0) {
                throw ValidationException::withMessages(['section_ids' => $label.' · '.$section->code.' is full. Choose another class.']);
            }
            if (str_contains((string) ($options[$section->id] ?? ''), 'unmet') || str_contains((string) ($options[$section->id] ?? ''), 'Already passed')) {
                throw ValidationException::withMessages(['section_ids' => $label.' is locked for you: '.str((string) $options[$section->id])->afterLast(' · ').'.']);
            }
        }

        $offeredCourseIds = $choices['sections']->map(fn (Section $section): int => (int) ($section->termOffering?->courseSpecification()?->course_id ?? 0));
        $missingRetakes = $this->reasons->mandatoryRetakeCourseIds($student)
            ->filter(fn (int $courseId): bool => $offeredCourseIds->contains($courseId) && ! $courseIds->contains($courseId));
        if ($missingRetakes->isNotEmpty()) {
            $codes = $choices['sections']
                ->filter(fn (Section $section): bool => $missingRetakes->contains((int) ($section->termOffering?->courseSpecification()?->course_id ?? 0)))
                ->map(fn (Section $section): string => (string) $section->termOffering?->course()?->code)
                ->unique()->implode(', ');
            throw ValidationException::withMessages(['section_ids' => 'You must retake '.$codes.'. Choose a class for it.']);
        }

        $units = $sections->sum(fn (Section $section): float => (float) $section->termOffering?->courseSpecification()?->credit_units);
        $review = $this->reasons->forStudent($student, $case->term);

        return DB::transaction(fn (): IrregularEnrollmentRequest => IrregularEnrollmentRequest::query()->updateOrCreate(
            ['enrollment_id' => $case->id],
            [
                'student_profile_id' => $student->id,
                'state' => IrregularEnrollmentRequest::StateSubmitted,
                'reasons' => $review['reasons'],
                'student_remarks' => filled($remarks) ? trim((string) $remarks) : null,
                'supporting_documents' => $documents !== [] ? array_values($documents) : ($existing?->supporting_documents),
                'section_ids' => $selected->all(),
                'selected_units' => $units,
                'submitted_at' => now(),
                'returned_at' => null,
                'returned_by' => null,
                'return_reason' => null,
            ],
        ));
    }
}
