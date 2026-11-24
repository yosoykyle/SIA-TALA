<?php

namespace App\Actions\AcademicSetup;

use App\Models\CourseComponent;
use App\Models\CourseRequirement;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Section;
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
                'title' => $lockedSource->title,
                'description' => $lockedSource->description,
                'credit_units' => $lockedSource->credit_units,
                'grading_profile_key' => $lockedSource->grading_profile_key,
                'grading_profile_version' => $lockedSource->grading_profile_version,
                'academic_classification' => $lockedSource->academic_classification,
                'scheduling_treatment' => $lockedSource->scheduling_treatment,
                'allowed_modalities' => $lockedSource->allowed_modalities,
                'same_faculty_default' => $lockedSource->same_faculty_default,
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

    public function discardDraft(User $actor, CourseSpecification $courseSpecification): void
    {
        Gate::forUser($actor)->authorize('update', $courseSpecification);

        DB::transaction(function () use ($actor, $courseSpecification): void {
            $locked = CourseSpecification::query()
                ->with('course')
                ->lockForUpdate()
                ->findOrFail($courseSpecification->id);

            Gate::forUser($actor)->authorize('update', $locked);

            if ($locked->state !== CourseSpecification::StateDraft) {
                throw ValidationException::withMessages([
                    'course_specification' => 'Only a Draft Course Specification can be discarded.',
                ]);
            }

            $entries = $locked->curriculumEntries()
                ->with('curriculumVersion.program')
                ->get();

            if ($entries->isNotEmpty()) {
                $curriculumLabels = $entries
                    ->map(function (CurriculumEntry $entry): ?string {
                        $version = $entry->curriculumVersion;

                        if (! $version instanceof CurriculumVersion) {
                            return null;
                        }

                        $programCode = $version->program?->code;

                        return filled($programCode)
                            ? "{$programCode} · {$version->name}"
                            : (string) $version->name;
                    })
                    ->filter()
                    ->unique()
                    ->values();

                $message = $curriculumLabels->isNotEmpty()
                    ? 'This draft is used in '.$curriculumLabels->implode(', ').'. Remove it from those curricula before discarding.'
                    : 'This draft is currently used in a curriculum. Remove it from the curriculum before discarding.';

                throw ValidationException::withMessages([
                    'course_specification' => $message,
                ]);
            }

            if (Section::query()->where('course_specification_id', $locked->id)->exists()) {
                throw ValidationException::withMessages([
                    'course_specification' => 'This draft is referenced by a class offering and cannot be discarded.',
                ]);
            }

            $course = $locked->course;
            $specificationId = $locked->id;
            $revisionCode = $locked->revision_code;
            $title = $locked->title;

            $locked->components()->delete();
            $locked->requirements()->delete();
            $locked->delete();

            activity()
                ->performedOn($course)
                ->causedBy($actor)
                ->event('course_specification_draft_discarded')
                ->withProperties([
                    'course_specification_id' => $specificationId,
                    'revision_code' => $revisionCode,
                    'title' => $title,
                ])
                ->log('Course Specification draft discarded');
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
            $errors[] = 'Subject name is missing. Open the subject and enter a title.';
        }

        $isNonCredit = $courseSpecification->academic_classification === CourseSpecification::AcademicClassificationNonCredit;

        if ($isNonCredit ? (float) $courseSpecification->credit_units < 0 : (float) $courseSpecification->credit_units <= 0) {
            $errors[] = 'Credit units must be greater than zero. Open the subject and set its unit value.';
        }

        if (blank($courseSpecification->grading_profile_key)) {
            $errors[] = 'Grading profile is missing. Open the subject and save its details.';
        }

        if (! array_key_exists((string) $courseSpecification->academic_classification, CourseSpecification::academicClassificationOptions())) {
            $errors[] = 'Academic classification is missing. Open the subject and choose Ordinary, Physical Education, or NSTP.';
        }

        if ($modalities->isEmpty() || $modalities->diff($supportedModalities)->isNotEmpty()) {
            $errors[] = 'Allowed delivery modes are not set. Open the subject and choose Face-to-Face, Online, or both.';
        }

        $schedulingTreatment = $courseSpecification->scheduling_treatment;
        $hasComponents = $courseSpecification->components()->exists();

        if (! array_key_exists((string) $schedulingTreatment, CourseSpecification::schedulingTreatmentOptions())) {
            $errors[] = 'Class schedule is not set. Open the subject and choose recurring meetings or externally arranged.';
        } elseif ($schedulingTreatment === CourseSpecification::SchedulingRecurring && ! $hasComponents) {
            $errors[] = 'Missing weekly class meetings (Lecture or Lab). Open the subject and add at least one class meeting.';
        } elseif ($schedulingTreatment === CourseSpecification::SchedulingExternallyArranged && $hasComponents) {
            $errors[] = 'Externally arranged subjects cannot have weekly class meetings. Open the subject and remove its class meetings or switch to recurring.';
        }

        if ($courseSpecification->components()->where('weekly_contact_hours', '<=', 0)->exists()) {
            $errors[] = 'Each class meeting needs weekly hours greater than zero.';
        }

        foreach ($courseSpecification->components()->get() as $component) {
            $meetingPattern = CourseComponent::parseMeetingPattern($component->meeting_pattern);

            if ($meetingPattern === null) {
                $errors[] = 'Each class meeting needs a valid weekly meeting pattern (such as 2 × 90 minutes).';

                continue;
            }

            $patternMinutes = $meetingPattern['count'] * $meetingPattern['duration_minutes'];
            $weeklyMinutes = (int) round((float) $component->weekly_contact_hours * 60);

            if ($patternMinutes !== $weeklyMinutes) {
                $errors[] = 'Each class meeting’s weekly hours must match its meeting pattern.';
            }
        }

        if ($courseSpecification->components()
            ->whereNotNull('modality_restriction')
            ->whereNotIn('modality_restriction', $supportedModalities)
            ->exists()) {
            $errors[] = 'Each class meeting’s delivery mode must be Face-to-Face or Online.';
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
            'state',
            'sequence',
        ]);
    }
}
