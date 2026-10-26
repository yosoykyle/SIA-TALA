<?php

namespace App\Actions\Scheduling;

use App\Models\FacultyAvailabilityDeadline;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RecordFacultyAvailabilityDeadline
{
    public function execute(Term $term, User $actor, string $dueAt, ?string $authority, ?string $reason, int $expectedId): FacultyAvailabilityDeadline
    {
        Gate::forUser($actor)->authorize('update', $term);
        Validator::make(['due_at' => $dueAt, 'authority_reference' => $authority], [
            'due_at' => ['required', 'date'],
            'authority_reference' => ['nullable', 'string', 'max:255'],
        ])->validate();

        return DB::transaction(function () use ($term, $actor, $dueAt, $authority, $reason, $expectedId): FacultyAvailabilityDeadline {
            Term::query()->whereKey($term)->lockForUpdate()->firstOrFail();
            $package = TermCalendarPackage::query()->where('term_id', $term->id)->where('state', TermCalendarPackage::StateActive)->first();
            if (! $package) {
                throw ValidationException::withMessages(['due_at' => 'Activate the exact Term core calendar before recording its Faculty deadline.']);
            }
            $prior = FacultyAvailabilityDeadline::query()->where('term_id', $term->id)->latest('id')->first();
            if ((int) ($prior?->id ?? 0) !== $expectedId) {
                throw ValidationException::withMessages(['due_at' => 'The Faculty deadline changed. Reopen this form and review its latest source.']);
            }
            if (($prior || $package->faculty_availability_due_at) && blank($reason)) {
                throw ValidationException::withMessages(['correction_reason' => 'Record why the existing Faculty deadline is being corrected.']);
            }

            return FacultyAvailabilityDeadline::query()->create([
                'term_id' => $term->id,
                'due_at' => $dueAt,
                'authority_reference' => filled($authority) ? trim($authority) : null,
                'correction_reason' => $reason,
                'recorded_by' => $actor->id,
            ]);
        }, attempts: 3);
    }
}
