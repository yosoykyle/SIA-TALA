<?php

namespace App\Actions\StudentProfiles;

use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\PriorAcademicCredit;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class RecordPriorAcademicCredits
{
    /**
     * Append prior academic credit rows to a student profile, skipping exact active duplicates.
     *
     * @param  list<array{
     *     course_specification_id: int,
     *     curriculum_entry_id?: int|null,
     *     term_id?: int|null,
     *     academic_year_label?: string|null,
     *     term_label?: string|null,
     *     year_level?: int|null,
     *     units?: float|int|string|null,
     *     final_grade?: string|null,
     *     result_status?: string|null,
     *     source_school?: string|null
     * }>  $items
     * @return array{created: int, skipped_duplicates: int}
     */
    public function appendCredits(StudentProfile $profile, array $items, User $actor): array
    {
        if (! $actor->hasRole(User::StaffRoleRegistrar)) {
            throw new AuthorizationException('Only an authorized Registrar may record prior academic credits.');
        }

        if ($items === []) {
            return ['created' => 0, 'skipped_duplicates' => 0];
        }

        return DB::transaction(function () use ($profile, $items, $actor): array {
            $curriculumEntriesBySpec = $profile->curriculum_version_id !== null
                ? CurriculumEntry::query()
                    ->where('curriculum_version_id', $profile->curriculum_version_id)
                    ->get()
                    ->keyBy('course_specification_id')
                : collect();

            $existingActive = PriorAcademicCredit::query()
                ->where('student_profile_id', $profile->id)
                ->where('state', PriorAcademicCredit::StateActive)
                ->lockForUpdate()
                ->get();

            $existingKeys = $existingActive
                ->map(fn (PriorAcademicCredit $credit): string => $this->dedupeKey(
                    courseSpecId: (int) $credit->course_specification_id,
                    termId: $credit->term_id,
                    academicYearLabel: $credit->academic_year_label,
                    termLabel: $credit->term_label,
                ))
                ->flip()
                ->all();

            $specIds = collect($items)->pluck('course_specification_id')->filter()->unique()->values();
            $specs = CourseSpecification::query()->whereIn('id', $specIds)->get()->keyBy('id');

            $created = 0;
            $skipped = 0;

            foreach ($items as $item) {
                $specId = (int) $item['course_specification_id'];
                $spec = $specs->get($specId);

                if (! $spec instanceof CourseSpecification) {
                    continue;
                }

                $entry = isset($item['curriculum_entry_id']) && $item['curriculum_entry_id'] !== null
                    ? CurriculumEntry::query()->find((int) $item['curriculum_entry_id'])
                    : $curriculumEntriesBySpec->get($specId);

                $termId = isset($item['term_id']) && $item['term_id'] !== null ? (int) $item['term_id'] : null;
                $ayLabel = filled($item['academic_year_label'] ?? null) ? trim((string) $item['academic_year_label']) : null;
                $termLabel = filled($item['term_label'] ?? null)
                    ? trim((string) $item['term_label'])
                    : ($entry instanceof CurriculumEntry && filled($entry->term_label) ? (string) $entry->term_label : null);

                $key = $this->dedupeKey($specId, $termId, $ayLabel, $termLabel);
                if (isset($existingKeys[$key])) {
                    $skipped++;

                    continue;
                }

                $yearLevel = isset($item['year_level']) && $item['year_level'] !== null && $item['year_level'] !== ''
                    ? (int) $item['year_level']
                    : ($entry instanceof CurriculumEntry && is_numeric($entry->year_level) ? (int) $entry->year_level : null);

                $units = isset($item['units']) && $item['units'] !== null && $item['units'] !== ''
                    ? round((float) $item['units'], 2)
                    : round((float) $spec->credit_units, 2);

                $resultStatus = $this->normalizeResultStatus(
                    status: $item['result_status'] ?? null,
                    finalGrade: $item['final_grade'] ?? null,
                );
                $finalGrade = $this->normalizeFinalGrade($item['final_grade'] ?? null, $resultStatus);

                PriorAcademicCredit::query()->create([
                    'student_profile_id' => $profile->id,
                    'course_specification_id' => $specId,
                    'curriculum_entry_id' => $entry?->id,
                    'term_id' => $termId,
                    'academic_year_label' => $ayLabel,
                    'term_label' => $termLabel,
                    'year_level' => $yearLevel,
                    'units' => $units,
                    'final_grade' => $finalGrade,
                    'result_status' => $resultStatus,
                    'source_school' => filled($item['source_school'] ?? null) ? trim((string) $item['source_school']) : null,
                    'state' => PriorAcademicCredit::StateActive,
                    'recorded_by' => $actor->id,
                    'recorded_at' => now(),
                ]);

                $existingKeys[$key] = true;
                $created++;
            }

            if ($created > 0) {
                activity('academic_records')
                    ->performedOn($profile)
                    ->causedBy($actor)
                    ->event('prior_academic_credits_recorded')
                    ->withProperties([
                        'student_number' => $profile->student_number,
                        'created_count' => $created,
                        'skipped_duplicates' => $skipped,
                    ])
                    ->log("Recorded {$created} prior academic credit(s)");
            }

            return ['created' => $created, 'skipped_duplicates' => $skipped];
        }, attempts: 3);
    }

    /**
     * Synchronize prior credited subjects from the 1-by-1 checklist modal while preserving ACTIVE / REVOKED history.
     *
     * @param  list<int>  $selectedEntryIds
     * @return array{added: int, revoked: int}
     */
    public function syncChecklistCredits(
        StudentProfile $profile,
        array $selectedEntryIds,
        User $actor,
        ?string $academicYearLabel = null,
        ?string $sourceSchool = null,
    ): array {
        if (! $actor->hasRole(User::StaffRoleRegistrar)) {
            throw new AuthorizationException('Only an authorized Registrar may record prior academic credits.');
        }

        $selectedIds = array_values(array_unique(array_filter(array_map('intval', $selectedEntryIds), fn (int $id): bool => $id > 0)));

        return DB::transaction(function () use ($profile, $selectedIds, $actor, $academicYearLabel, $sourceSchool): array {
            $entries = CurriculumEntry::query()
                ->with('courseSpecification')
                ->where('curriculum_version_id', $profile->curriculum_version_id)
                ->get()
                ->keyBy('id');

            $activeCredits = PriorAcademicCredit::query()
                ->where('student_profile_id', $profile->id)
                ->where('state', PriorAcademicCredit::StateActive)
                ->lockForUpdate()
                ->get();

            $activeByEntryId = $activeCredits
                ->filter(fn (PriorAcademicCredit $credit): bool => $credit->curriculum_entry_id !== null)
                ->groupBy('curriculum_entry_id');

            $revoked = 0;
            foreach ($activeByEntryId as $entryId => $group) {
                if (! in_array((int) $entryId, $selectedIds, true)) {
                    foreach ($group as $credit) {
                        $credit->forceFill([
                            'state' => PriorAcademicCredit::StateRevoked,
                            'revoked_by' => $actor->id,
                            'revoked_at' => now(),
                        ])->save();
                        $revoked++;
                    }
                }
            }

            $added = 0;
            $cleanAy = filled($academicYearLabel) ? trim((string) $academicYearLabel) : 'Prior Institutional Record';
            $cleanSchool = filled($sourceSchool) ? trim((string) $sourceSchool) : null;

            foreach ($selectedIds as $entryId) {
                if ($activeByEntryId->has($entryId)) {
                    continue;
                }

                $entry = $entries->get($entryId);
                if (! $entry instanceof CurriculumEntry || $entry->courseSpecification === null) {
                    continue;
                }

                $alreadyActiveBySpec = $activeCredits->contains(
                    fn (PriorAcademicCredit $credit): bool => (int) $credit->course_specification_id === (int) $entry->course_specification_id
                        && $credit->state === PriorAcademicCredit::StateActive,
                );
                if ($alreadyActiveBySpec) {
                    continue;
                }

                PriorAcademicCredit::query()->create([
                    'student_profile_id' => $profile->id,
                    'course_specification_id' => (int) $entry->course_specification_id,
                    'curriculum_entry_id' => (int) $entry->id,
                    'term_id' => null,
                    'academic_year_label' => $cleanAy,
                    'term_label' => filled($entry->term_label) ? (string) $entry->term_label : $this->termLabelFromType($entry->term_type),
                    'year_level' => is_numeric($entry->year_level) ? (int) $entry->year_level : null,
                    'units' => round((float) $entry->courseSpecification->credit_units, 2),
                    'final_grade' => 'CREDIT',
                    'result_status' => PriorAcademicCredit::ResultCredited,
                    'source_school' => $cleanSchool,
                    'state' => PriorAcademicCredit::StateActive,
                    'recorded_by' => $actor->id,
                    'recorded_at' => now(),
                ]);
                $added++;
            }

            if ($added > 0 || $revoked > 0) {
                activity('academic_records')
                    ->performedOn($profile)
                    ->causedBy($actor)
                    ->event('prior_academic_credits_updated')
                    ->withProperties([
                        'student_number' => $profile->student_number,
                        'added' => $added,
                        'revoked' => $revoked,
                    ])
                    ->log("Updated prior academic credits (+{$added}, -{$revoked})");
            }

            return ['added' => $added, 'revoked' => $revoked];
        }, attempts: 3);
    }

    private function dedupeKey(int $courseSpecId, ?int $termId, ?string $academicYearLabel, ?string $termLabel): string
    {
        if ($termId !== null) {
            return $courseSpecId.'|term:'.$termId;
        }

        return $courseSpecId.'|prior:'.strtolower(trim((string) ($academicYearLabel ?? ''))).'|'.strtolower(trim((string) ($termLabel ?? '')));
    }

    private function normalizeResultStatus(?string $status, ?string $finalGrade): string
    {
        $cleanStatus = strtolower(trim((string) ($status ?? '')));
        if (in_array($cleanStatus, PriorAcademicCredit::resultStatuses(), true)) {
            return $cleanStatus;
        }

        $cleanGrade = strtoupper(trim((string) ($finalGrade ?? '')));
        if (in_array($cleanGrade, ['CREDIT', 'TC', 'CREDITED'], true)) {
            return PriorAcademicCredit::ResultCredited;
        }
        if ($cleanGrade === 'INC') {
            return PriorAcademicCredit::ResultInc;
        }
        if (in_array($cleanGrade, ['DRP', 'W', 'DROPPED'], true)) {
            return PriorAcademicCredit::ResultDropped;
        }
        if (is_numeric($cleanGrade)) {
            return (float) $cleanGrade <= 4.00 ? PriorAcademicCredit::ResultPassed : PriorAcademicCredit::ResultFailed;
        }

        return PriorAcademicCredit::ResultPassed;
    }

    private function normalizeFinalGrade(?string $finalGrade, string $resultStatus): ?string
    {
        $clean = trim((string) ($finalGrade ?? ''));
        if ($clean === '') {
            return match ($resultStatus) {
                PriorAcademicCredit::ResultCredited => 'CREDIT',
                PriorAcademicCredit::ResultInc => 'INC',
                PriorAcademicCredit::ResultDropped => 'DRP',
                PriorAcademicCredit::ResultFailed => '5.00',
                default => 'CREDIT',
            };
        }

        $upper = strtoupper($clean);
        if (in_array($upper, ['CREDIT', 'TC', 'INC', 'DRP', 'W'], true)) {
            return $upper;
        }

        if (is_numeric($clean)) {
            return number_format((float) $clean, 2, '.', '');
        }

        return $clean;
    }

    private function termLabelFromType(?string $termType): string
    {
        return match ($termType) {
            Term::TypeFirstSemester => 'First Semester',
            Term::TypeSecondSemester => 'Second Semester',
            Term::TypeSpecialTerm => 'Summer',
            default => 'Prior Term',
        };
    }
}
