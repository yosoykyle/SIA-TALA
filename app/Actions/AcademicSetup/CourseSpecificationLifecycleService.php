<?php

namespace App\Actions\AcademicSetup;

use App\Models\CourseComponent;
use App\Models\CourseRequirement;
use App\Models\CourseSpecification;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CourseSpecificationLifecycleService
{
    public function copyToDraft(
        User $actor,
        CourseSpecification $source,
        string $revisionCode,
    ): CourseSpecification {
        Gate::forUser($actor)->authorize('copy', $source);

        $normalizedRevisionCode = trim($revisionCode);

        if ($normalizedRevisionCode === '') {
            throw ValidationException::withMessages([
                'revision_code' => 'Enter a revision identifier for the new Draft.',
            ]);
        }

        return DB::transaction(function () use ($actor, $source, $normalizedRevisionCode): CourseSpecification {
            $lockedSource = CourseSpecification::query()
                ->with(['components', 'requirements'])
                ->lockForUpdate()
                ->findOrFail($source->id);

            if (CourseSpecification::query()
                ->where('course_id', $lockedSource->course_id)
                ->where('revision_code', $normalizedRevisionCode)
                ->exists()) {
                throw ValidationException::withMessages([
                    'revision_code' => 'That revision identifier already exists for this course.',
                ]);
            }

            $draft = CourseSpecification::query()->create([
                'course_id' => $lockedSource->course_id,
                'revision_code' => $normalizedRevisionCode,
                'authority_reference' => $lockedSource->authority_reference,
                'effective_from' => $lockedSource->effective_from,
                'effective_until' => $lockedSource->effective_until,
                'title' => $lockedSource->title,
                'description' => $lockedSource->description,
                'credit_units' => $lockedSource->credit_units,
                'grading_profile_key' => $lockedSource->grading_profile_key,
                'grading_profile_version' => $lockedSource->grading_profile_version,
                'academic_classification' => $lockedSource->academic_classification,
                'scheduling_treatment' => $lockedSource->scheduling_treatment,
                'allowed_modalities' => $lockedSource->allowed_modalities,
                'same_faculty_default' => $lockedSource->same_faculty_default,
                'effective_term_id' => $lockedSource->effective_term_id,
                'state' => CourseSpecification::StateDraft,
            ]);

            foreach ($lockedSource->components as $component) {
                $draft->components()->create($this->componentAttributes($component));
            }

            foreach ($lockedSource->requirements as $requirement) {
                $draft->requirements()->create($this->requirementAttributes($requirement));
            }

            activity()
                ->performedOn($draft)
                ->causedBy($actor)
                ->event('course_specification_draft_copied')
                ->withProperties([
                    'source_course_specification_id' => $lockedSource->id,
                    'source_revision_code' => $lockedSource->revision_code,
                    'new_revision_code' => $draft->revision_code,
                ])
                ->log('Course Specification revision copied to Draft');

            return $draft->fresh(['components', 'requirements']);
        });
    }

    public function activate(User $actor, CourseSpecification $courseSpecification): CourseSpecification
    {
        Gate::forUser($actor)->authorize('activate', $courseSpecification);

        return DB::transaction(function () use ($actor, $courseSpecification): CourseSpecification {
            $locked = CourseSpecification::query()
                ->with('components')
                ->lockForUpdate()
                ->findOrFail($courseSpecification->id);

            if ($locked->state !== CourseSpecification::StateDraft) {
                throw ValidationException::withMessages([
                    'course_specification' => 'Only a Draft Course Specification can be activated.',
                ]);
            }

            $errors = $this->readinessErrors($locked);

            if ($errors !== []) {
                throw ValidationException::withMessages([
                    'course_specification' => $errors,
                ]);
            }

            $locked->update(['state' => CourseSpecification::StateActive]);

            activity()
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('course_specification_activated')
                ->withProperties(['revision_code' => $locked->revision_code])
                ->log('Course Specification revision activated');

            return $locked->fresh();
        });
    }

    /**
     * @return list<string>
     */
    public function readinessErrors(CourseSpecification $courseSpecification): array
    {
        $errors = [];
        $allowedModalities = $courseSpecification->getAttribute('allowed_modalities');
        $modalities = collect(is_array($allowedModalities) ? $allowedModalities : [])
            ->filter()
            ->values();
        $supportedModalities = array_keys(CourseSpecification::modalityOptions());

        if (blank($courseSpecification->title)) {
            $errors[] = 'Subject Title is required.';
        }

        if ((float) $courseSpecification->credit_units <= 0) {
            $errors[] = 'Credit Units must be greater than zero.';
        }

        if (blank($courseSpecification->grading_profile_key)) {
            $errors[] = 'A Grading Profile is required.';
        }

        if (! array_key_exists((string) $courseSpecification->academic_classification, CourseSpecification::academicClassificationOptions())) {
            $errors[] = 'Select the authorized Course Academic Classification.';
        }

        if ($modalities->isEmpty() || $modalities->diff($supportedModalities)->isNotEmpty()) {
            $errors[] = 'Allowed Modalities must contain only Face-to-Face and Online.';
        }

        $schedulingTreatment = $courseSpecification->scheduling_treatment;
        $hasComponents = $courseSpecification->components()->exists();

        if (! array_key_exists((string) $schedulingTreatment, CourseSpecification::schedulingTreatmentOptions())) {
            $errors[] = 'Select whether the course has recurring meetings or is externally arranged.';
        } elseif ($schedulingTreatment === CourseSpecification::SchedulingRecurring && ! $hasComponents) {
            $errors[] = 'Recurring courses require at least one Course Component.';
        } elseif ($schedulingTreatment === CourseSpecification::SchedulingExternallyArranged && $hasComponents) {
            $errors[] = 'Externally arranged courses cannot define recurring Course Components.';
        }

        if ($courseSpecification->components()->where('weekly_contact_hours', '<=', 0)->exists()) {
            $errors[] = 'Every Course Component must have contact hours greater than zero.';
        }

        foreach ($courseSpecification->components()->get() as $component) {
            $meetingPattern = CourseComponent::parseMeetingPattern($component->meeting_pattern);

            if ($meetingPattern === null) {
                $errors[] = 'Every Course Component must define an approved weekly Meeting Pattern.';

                continue;
            }

            $patternMinutes = $meetingPattern['count'] * $meetingPattern['duration_minutes'];
            $weeklyMinutes = (int) round((float) $component->weekly_contact_hours * 60);

            if ($patternMinutes !== $weeklyMinutes) {
                $errors[] = 'Every Course Component Meeting Pattern must equal its weekly contact hours.';
            }
        }

        if ($courseSpecification->components()
            ->whereNotNull('modality_restriction')
            ->whereNotIn('modality_restriction', $supportedModalities)
            ->exists()) {
            $errors[] = 'Every component modality restriction must be Face-to-Face or Online.';
        }

        return $errors;
    }

    /**
     * @return array<string, mixed>
     */
    private function componentAttributes(CourseComponent $component): array
    {
        return $component->only([
            'component_type',
            'weekly_contact_hours',
            'meeting_pattern',
            'room_type_default',
            'required_room_feature_keys',
            'modality_restriction',
            'requires_consecutive_block',
            'same_faculty',
            'sequence',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function requirementAttributes(CourseRequirement $requirement): array
    {
        return $requirement->only([
            'rule_type',
            'group_key',
            'related_course_id',
            'direction',
            'equivalency_scope',
            'required_outcome',
            'minimum_grade',
            'accepts_transfer_credit',
            'effective_from',
            'effective_until',
            'authority',
            'state',
            'sequence',
        ]);
    }
}
