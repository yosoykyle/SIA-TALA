<?php

namespace App\Actions\Scheduling;

use App\Models\AcademicYear;
use App\Models\CurriculumVersion;
use App\Models\Section;
use App\Models\Term;
use App\Models\TermCohort;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class RecordTermCohort
{
    public static function normalizeReference(string $reference): string
    {
        $normalized = strtoupper((string) preg_replace('/\s+/', ' ', trim($reference)));

        if ($normalized === '') {
            return '';
        }

        if (preg_match('/^([A-Z]{2,})([1-8](?:-[A-Z0-9]+|[A-Z](?:-[A-Z0-9]+)?)?)$/', $normalized, $matches) === 1) {
            return $matches[1].' '.$matches[2];
        }

        return $normalized;
    }

    /** @param array<string, mixed> $data */
    public function execute(Term $term, User $actor, array $data, ?int $entryAcademicYearId = null): TermCohort
    {
        abort_unless($actor->hasRole(User::StaffRoleRegistrar), 403);
        Gate::forUser($actor)->authorize('create', Section::class);

        return DB::transaction(function () use ($term, $actor, $data, $entryAcademicYearId): TermCohort {
            $term = Term::query()->with('academicYear')->lockForUpdate()->findOrFail($term->id);
            if ($term->state === Term::StateClosed) {
                throw ValidationException::withMessages(['term_id' => 'A Closed Term cannot receive new confirmed cohorts.']);
            }
            $rawEntryAcademicYearId = $entryAcademicYearId ?? (filled($data['entry_academic_year_id'] ?? null) ? (int) $data['entry_academic_year_id'] : null);
            $validated = Validator::make([
                'curriculum_version_id' => $data['curriculum_version_id'] ?? null,
                'entry_academic_year_id' => $rawEntryAcademicYearId,
                'reference' => self::normalizeReference((string) ($data['reference'] ?? '')),
                'year_level' => filled($data['year_level'] ?? null) ? (int) $data['year_level'] : null,
                'confirmed_count' => $data['confirmed_count'] ?? null,
            ], [
                'curriculum_version_id' => ['required', 'integer', 'exists:curriculum_versions,id'],
                'entry_academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
                'reference' => ['required', 'string', 'max:64', Rule::unique('term_cohorts')->where('term_id', $term->id)],
                'year_level' => ['nullable', 'integer', 'min:1', 'max:8'],
                'confirmed_count' => ['required', 'integer', 'min:1'],
            ])->validate();
            $curriculum = CurriculumVersion::query()->with('academicYears')->lockForUpdate()->findOrFail($validated['curriculum_version_id']);
            if (! in_array($curriculum->state, [CurriculumVersion::StateActive, CurriculumVersion::StateSuperseded], true)) {
                throw ValidationException::withMessages(['curriculum_version_id' => 'Choose a published (Active or Retired) curriculum for this confirmed cohort.']);
            }

            if ($validated['entry_academic_year_id'] === null && $validated['year_level'] !== null) {
                $termStartYear = $term->academicYear instanceof AcademicYear
                    ? CurriculumVersion::extractAcademicYearStart($term->academicYear)
                    : $term->starts_on?->year;

                if ($termStartYear !== null) {
                    $targetStartYear = $termStartYear - ((int) $validated['year_level'] - 1);
                    $matchedBatch = $curriculum->academicYears->first(
                        fn (AcademicYear $academicYear): bool => CurriculumVersion::extractAcademicYearStart($academicYear) === $targetStartYear,
                    );

                    if ($matchedBatch instanceof AcademicYear) {
                        $validated['entry_academic_year_id'] = $matchedBatch->id;
                    }
                }
            }

            return TermCohort::query()->create([
                ...$validated,
                'term_id' => $term->id,
                'program_id' => $curriculum->program_id,
                'source' => 'Confirmed',
                'forecast_count' => null,
                'state' => 'Confirmed',
                'confirmed_by' => $actor->id,
                'confirmed_at' => now(),
            ]);
        }, attempts: 5);
    }
}
