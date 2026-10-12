<?php

namespace App\Actions\Scheduling;

use App\Models\CourseSpecification;
use App\Models\PublishedTimetableMeeting;
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
            $section->load('calendarPackage');
            if ((int) $offering->term_id !== (int) $term->id
                || ($section->calendarPackage && (int) $section->calendarPackage->term_id !== (int) $term->id)) {
                throw ValidationException::withMessages(['section_id' => 'Choose a source section belonging to this exact Term.']);
            }
            if ($term->state === Term::StateClosed || $section->confirmed_at !== null
                || in_array($section->state, [Section::StateClosed, Section::StateCancelled], true)
                || PublishedTimetableMeeting::query()->where('section_id', $section->id)->exists()) {
                throw ValidationException::withMessages(['section_id' => 'Only an unconfirmed, unpublished source section in an open Term can be prepared.']);
            }
            $package = TermCalendarPackage::query()->where('term_id', $term->id)
                ->where('state', TermCalendarPackage::StateActive)->lockForUpdate()->first();
            if (! $package || ! $package->isActivatedCoreSource()) {
                throw ValidationException::withMessages(['calendar_package' => 'Activate the approved calendar for this exact Term first.']);
            }
            $specification = $offering->courseSpecification();
            $specification = $specification ? CourseSpecification::query()->lockForUpdate()->findOrFail($specification->id) : null;
            if (! $specification || $specification->state !== CourseSpecification::StateActive) {
                throw ValidationException::withMessages(['course_revision' => 'The source offering must use an Active Course Revision.']);
            }
            $validated = Validator::make([
                'class_reference' => trim((string) ($data['class_reference'] ?? '')),
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
