<?php

namespace App\Actions\StudentProfiles;

use App\Models\StudentProfile;
use App\Models\StudentProfileEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RecordStudentProfileCorrection
{
    /** @param array<string, mixed> $facts */
    public function execute(
        StudentProfile $studentProfile,
        array $facts,
        User $actor,
        string $authorityReference,
        string $reason,
        ?CarbonImmutable $effectiveAt = null,
    ): StudentProfileEvent {
        Gate::forUser($actor)->authorize('update', $studentProfile);
        $allowed = [
            'first_name', 'middle_name', 'last_name', 'birth_date', 'prior_identifier',
            'email', 'phone', 'address', 'entry_term_id',
        ];

        if (array_diff(array_keys($facts), $allowed) !== []) {
            throw ValidationException::withMessages([
                'facts' => 'Program, curriculum, lifecycle, and other producer-owned facts cannot be corrected here.',
            ]);
        }

        $validated = Validator::make($facts, [
            'first_name' => ['sometimes', 'required', 'string', 'max:255'],
            'middle_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'last_name' => ['sometimes', 'required', 'string', 'max:255'],
            'birth_date' => ['sometimes', 'nullable', 'date', 'before:today'],
            'prior_identifier' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'entry_term_id' => ['sometimes', 'nullable', 'integer', 'exists:terms,id'],
        ])->validate();

        if ($validated === [] || blank($authorityReference) || blank($reason)) {
            throw ValidationException::withMessages([
                'correction' => 'A factual change, authority reference, and reason are required.',
            ]);
        }

        return DB::transaction(function () use ($studentProfile, $validated, $actor, $authorityReference, $reason, $effectiveAt, $allowed): StudentProfileEvent {
            $locked = StudentProfile::query()->whereKey($studentProfile->id)->lockForUpdate()->firstOrFail();
            $before = Arr::only($locked->getAttributes(), $allowed);
            $locked->fill($validated);
            $changedFields = array_keys($locked->getDirty());

            if ($changedFields === []) {
                throw ValidationException::withMessages(['facts' => 'The proposed correction does not change the current Student Profile.']);
            }

            $locked->save();
            $after = Arr::only($locked->fresh()->getAttributes(), $allowed);

            return StudentProfileEvent::query()->create([
                'student_profile_id' => $locked->id,
                'event_type' => StudentProfileEvent::TypeCorrection,
                'source' => 'RegistrarCorrection',
                'authority_reference' => $authorityReference,
                'reason' => $reason,
                'before_snapshot' => $before,
                'after_snapshot' => $after,
                'changed_fields' => $changedFields,
                'actor_id' => $actor->id,
                'effective_at' => $effectiveAt ?? CarbonImmutable::now(config('app.timezone')),
            ]);
        }, attempts: 3);
    }
}
