<?php

namespace App\Actions\Scheduling;

use App\Models\CourseSpecification;
use App\Models\PublishedTimetableMeeting;
use App\Models\PublishedTimetableVersion;
use App\Models\Section;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermOffering;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class PrepareClassOffering
{
    /**
     * @param  list<string>  $reservedReferences
     */
    public static function generateUniqueReference(
        Term $term,
        ?string $courseCode,
        ?string $cohortOrSectionReference = null,
        array $reservedReferences = [],
        ?int $excludeSectionId = null,
    ): string {
        $cleanCourse = preg_replace('/[^A-Z0-9]+/', '', strtoupper((string) $courseCode)) ?: 'CLASS';
        $cleanCohort = preg_replace('/[^A-Z0-9]+/', '', strtoupper((string) $cohortOrSectionReference)) ?: '';
        $base = ($cleanCohort !== '' && $cleanCohort !== $cleanCourse)
            ? "{$cleanCourse}-{$cleanCohort}"
            : $cleanCourse;
        $base = substr($base, 0, 56);

        $existingReferences = Section::query()
            ->when($excludeSectionId !== null, fn ($query) => $query->whereKeyNot($excludeSectionId))
            ->where(fn ($query) => $query
                ->whereHas('termOffering', fn ($q) => $q->where('term_id', $term->id))
                ->orWhereHas('calendarPackage', fn ($q) => $q->where('term_id', $term->id)))
            ->get(['code', 'class_reference'])
            ->flatMap(fn (Section $s): array => array_filter([
                filled($s->code) ? strtoupper(trim((string) $s->code)) : null,
                filled($s->class_reference) ? strtoupper(trim((string) $s->class_reference)) : null,
            ]))
            ->merge(collect($reservedReferences)->map(fn (string $ref): string => strtoupper(trim($ref))))
            ->flip()
            ->all();

        $candidate = $base;
        $suffix = 2;

        while (isset($existingReferences[strtoupper($candidate)])) {
            $candidate = "{$base}-{$suffix}";
            $suffix++;
        }

        return $candidate;
    }

    /** @param array<string, mixed> $data */
    public function execute(Term $term, Section $section, User $actor, array $data): Section
    {
        abort_unless($actor->hasRole(User::StaffRoleRegistrar), 403);
        Gate::forUser($actor)->authorize('update', $section);

        return DB::transaction(function () use ($term, $section, $actor, $data): Section {
            $term = Term::query()->lockForUpdate()->findOrFail($term->id);
            $section = Section::query()->lockForUpdate()->findOrFail($section->id);
            Gate::forUser($actor)->authorize('update', $section);
            $offering = TermOffering::query()->lockForUpdate()->findOrFail($section->term_offering_id);
            $section->load(['calendarPackage', 'cohorts']);
            if ((int) $offering->term_id !== (int) $term->id
                || ($section->calendarPackage && (int) $section->calendarPackage->term_id !== (int) $term->id)) {
                throw ValidationException::withMessages(['section_id' => 'Choose a source section belonging to this exact Term.']);
            }
            if ($term->state === Term::StateClosed || $section->confirmed_at !== null
                || in_array($section->state, [Section::StateClosed, Section::StateCancelled], true)
                || PublishedTimetableMeeting::query()->where('section_id', $section->id)->exists()
                || PublishedTimetableVersion::query()
                    ->where('term_id', $term->id)
                    ->where('state', PublishedTimetableVersion::StatePublished)
                    ->exists()) {
                throw ValidationException::withMessages(['section_id' => 'Only an unconfirmed, unpublished source section in an open Term without a published official timetable can be prepared.']);
            }
            $package = TermCalendarPackage::query()->where('term_id', $term->id)
                ->where('state', TermCalendarPackage::StateActive)->lockForUpdate()->first();
            if (! $package || ! $package->isActivatedCoreSource()) {
                throw ValidationException::withMessages(['calendar_package' => 'Activate the approved calendar for this exact Term first.']);
            }
            $specification = $offering->courseSpecification();
            $specification = $specification ? CourseSpecification::query()->with('course')->lockForUpdate()->findOrFail($specification->id) : null;
            if (! $specification || $specification->state !== CourseSpecification::StateActive) {
                throw ValidationException::withMessages(['course_revision' => 'The source offering must use an Active Course Revision.']);
            }

            $rawReference = trim((string) ($data['class_reference'] ?? ''));
            if ($rawReference === '') {
                $existingCode = trim((string) ($section->class_reference ?: $section->code));
                $existingCodeIsTaken = $existingCode !== '' && Section::query()
                    ->whereKeyNot($section->id)
                    ->where('class_reference', $existingCode)
                    ->where(fn ($query) => $query
                        ->whereHas('termOffering', fn ($q) => $q->where('term_id', $term->id))
                        ->orWhereHas('calendarPackage', fn ($q) => $q->where('term_id', $term->id)))
                    ->exists();

                $rawReference = ($existingCode !== '' && ! $existingCodeIsTaken)
                    ? $existingCode
                    : self::generateUniqueReference(
                        $term,
                        $specification->course?->code,
                        $section->cohorts->first()?->reference,
                        [],
                        $section->id,
                    );
            }

            $validated = Validator::make([
                'class_reference' => $rawReference,
                'source' => $data['source'] ?? null,
                'delivery_mode' => $data['delivery_mode'] ?? null,
                'authority_reference' => filled($data['authority_reference'] ?? null) ? trim((string) $data['authority_reference']) : null,
            ], [
                'class_reference' => ['required', 'string', 'max:64'],
                'source' => ['required', Rule::in(array_keys(Section::confirmableSourceOptions()))],
                'delivery_mode' => ['required', Rule::in($specification->allowed_modalities ?? [])],
                'authority_reference' => ['required_if:source,'.Section::SourceAdditional, 'nullable', 'string', 'max:255'],
            ])->validate();
            $duplicate = Section::query()->whereKeyNot($section->id)
                ->where('class_reference', $validated['class_reference'])
                ->where(fn ($query) => $query->whereHas('termOffering', fn ($query) => $query->where('term_id', $term->id))
                    ->orWhereHas('calendarPackage', fn ($query) => $query->where('term_id', $term->id)))->exists();
            if ($duplicate) {
                throw ValidationException::withMessages(['class_reference' => 'This class reference is already used in the selected Term.']);
            }
            $section->forceFill([
                ...$validated,
                'term_calendar_package_id' => $package->id,
                'course_specification_id' => $specification->id,
            ])->save();

            return $section->fresh();
        }, attempts: 5);
    }
}
