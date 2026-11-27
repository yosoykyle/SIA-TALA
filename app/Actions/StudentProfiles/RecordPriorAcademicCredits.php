<?php

namespace App\Actions\StudentProfiles;

use App\Actions\Academics\IrregularStudentReasons;
use App\Actions\Completion\CompletionReadinessProjection;
use App\Actions\Completion\SupersedeTranscriptSnapshots;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\PriorAcademicCredit;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordPriorAcademicCredits
{
    public function __construct(
        private readonly CompletionReadinessProjection $completionReadiness,
        private readonly SupersedeTranscriptSnapshots $supersedeTranscripts,
    ) {}

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
     *     source_school?: string|null,
     *     is_external_transfer?: bool|null
     * }>  $items
     * @return array{created: int, skipped_duplicates: int}
     */
    public function appendCredits(StudentProfile $profile, array $items, User $actor): array
    {
        $this->authorizeRegistrar($actor);

        if ($items === []) {
            return ['created' => 0, 'skipped_duplicates' => 0];
        }

        $result = DB::transaction(function () use ($profile, $items, $actor): array {
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

            foreach ($items as $index => $item) {
                $specId = (int) $item['course_specification_id'];
                $spec = $specs->get($specId);

                if (! $spec instanceof CourseSpecification) {
                    continue;
                }

                $this->assertAllowedSpecification($spec, "prior_credits.{$index}.course_specification_id");

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

                $isExternalTransfer = (bool) ($item['is_external_transfer'] ?? false);
                [$resultStatus, $finalGrade] = $this->validateAndNormalizeOutcome(
                    status: $item['result_status'] ?? null,
                    finalGrade: $item['final_grade'] ?? null,
                    isExternalTransfer: $isExternalTransfer,
                    fieldPrefix: "prior_credits.{$index}",
                );

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
                    'is_external_transfer' => $isExternalTransfer,
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

        if ($result['created'] > 0) {
            $this->syncDownstreamProjections($profile, $actor, 'Prior academic credits recorded.');
        }

        return $result;
    }

    /**
     * Create or update a single prior academic credit row (e.g., upgrading CREDIT to an exact grade or editing a record).
     *
     * @param  array{
     *     id?: int|null,
     *     course_specification_id?: int|null,
     *     curriculum_entry_id?: int|null,
     *     term_id?: int|null,
     *     academic_year_label?: string|null,
     *     term_label?: string|null,
     *     year_level?: int|null,
     *     units?: float|int|string|null,
     *     final_grade?: string|null,
     *     result_status?: string|null,
     *     source_school?: string|null,
     *     is_external_transfer?: bool|null
     * }  $item
     */
    public function upsertPriorCredit(StudentProfile $profile, array $item, User $actor): PriorAcademicCredit
    {
        $this->authorizeRegistrar($actor);

        $credit = DB::transaction(function () use ($profile, $item, $actor): PriorAcademicCredit {
            $existing = null;
            if (filled($item['id'] ?? null)) {
                $existing = PriorAcademicCredit::query()
                    ->where('student_profile_id', $profile->id)
                    ->where('state', PriorAcademicCredit::StateActive)
                    ->lockForUpdate()
                    ->find((int) $item['id']);

                if (! $existing instanceof PriorAcademicCredit) {
                    throw ValidationException::withMessages([
                        'id' => 'The selected active prior academic credit could not be found for this student.',
                    ]);
                }
            }

            $entry = filled($item['curriculum_entry_id'] ?? null)
                ? CurriculumEntry::query()->with('courseSpecification')->find((int) $item['curriculum_entry_id'])
                : $existing?->curriculumEntry;

            $specId = filled($item['course_specification_id'] ?? null)
                ? (int) $item['course_specification_id']
                : (int) ($entry?->course_specification_id ?? $existing?->course_specification_id ?? 0);

            $spec = CourseSpecification::query()->find($specId);
            if (! $spec instanceof CourseSpecification) {
                throw ValidationException::withMessages([
                    'course_specification_id' => 'Choose a valid course specification for the prior academic credit.',
                ]);
            }

            $this->assertAllowedSpecification($spec, 'course_specification_id');

            if (! $entry instanceof CurriculumEntry && $profile->curriculum_version_id !== null) {
                $entry = CurriculumEntry::query()
                    ->where('curriculum_version_id', $profile->curriculum_version_id)
                    ->where('course_specification_id', $spec->id)
                    ->first();
            }

            $termId = array_key_exists('term_id', $item)
                ? (filled($item['term_id']) ? (int) $item['term_id'] : null)
                : $existing?->term_id;

            $ayLabel = array_key_exists('academic_year_label', $item)
                ? (filled($item['academic_year_label']) ? trim((string) $item['academic_year_label']) : null)
                : ($existing?->academic_year_label ?? 'Prior Institutional Record');

            $termLabel = array_key_exists('term_label', $item)
                ? (filled($item['term_label']) ? trim((string) $item['term_label']) : null)
                : ($existing?->term_label
                    ?? ($entry instanceof CurriculumEntry && filled($entry->term_label)
                        ? (string) $entry->term_label
                        : ($entry instanceof CurriculumEntry ? $this->termLabelFromType($entry->term_type) : null)));

            $yearLevel = array_key_exists('year_level', $item)
                ? (filled($item['year_level']) ? (int) $item['year_level'] : null)
                : ($existing?->year_level
                    ?? ($entry instanceof CurriculumEntry && is_numeric($entry->year_level) ? (int) $entry->year_level : null));

            $units = array_key_exists('units', $item) && $item['units'] !== null && $item['units'] !== ''
                ? round((float) $item['units'], 2)
                : ($existing instanceof PriorAcademicCredit ? round((float) $existing->units, 2) : round((float) $spec->credit_units, 2));

            $isExternalTransfer = array_key_exists('is_external_transfer', $item)
                ? (bool) $item['is_external_transfer']
                : ($existing?->isExternalTransfer() ?? false);

            $sourceSchool = array_key_exists('source_school', $item)
                ? (filled($item['source_school']) ? trim((string) $item['source_school']) : null)
                : $existing?->source_school;

            [$resultStatus, $finalGrade] = $this->validateAndNormalizeOutcome(
                status: $item['result_status'] ?? null,
                finalGrade: $item['final_grade'] ?? null,
                isExternalTransfer: $isExternalTransfer,
            );

            if (! $existing instanceof PriorAcademicCredit) {
                $candidates = PriorAcademicCredit::query()
                    ->where('student_profile_id', $profile->id)
                    ->where('course_specification_id', $spec->id)
                    ->where('state', PriorAcademicCredit::StateActive)
                    ->lockForUpdate()
                    ->get();

                $targetKey = $this->dedupeKey($spec->id, $termId, $ayLabel, $termLabel);
                $existing = $candidates->first(fn (PriorAcademicCredit $candidate): bool => $this->dedupeKey(
                    courseSpecId: (int) $candidate->course_specification_id,
                    termId: $candidate->term_id,
                    academicYearLabel: $candidate->academic_year_label,
                    termLabel: $candidate->term_label,
                ) === $targetKey)
                    ?? $candidates->first(fn (PriorAcademicCredit $candidate): bool => $candidate->result_status === PriorAcademicCredit::ResultCredited
                        && strtoupper((string) ($candidate->final_grade ?? '')) === 'CREDIT');
            }

            if ($existing instanceof PriorAcademicCredit) {
                $previousGrade = $existing->final_grade;
                $previousStatus = $existing->result_status;

                $existing->forceFill([
                    'course_specification_id' => $spec->id,
                    'curriculum_entry_id' => $entry?->id,
                    'term_id' => $termId,
                    'academic_year_label' => $ayLabel,
                    'term_label' => $termLabel,
                    'year_level' => $yearLevel,
                    'units' => $units,
                    'final_grade' => $finalGrade,
                    'result_status' => $resultStatus,
                    'source_school' => $sourceSchool,
                    'is_external_transfer' => $isExternalTransfer,
                ])->save();

                activity('academic_records')
                    ->performedOn($profile)
                    ->causedBy($actor)
                    ->event('prior_academic_credit_upserted')
                    ->withProperties([
                        'student_number' => $profile->student_number,
                        'prior_academic_credit_id' => $existing->id,
                        'course_specification_id' => $spec->id,
                        'previous_grade' => $previousGrade,
                        'new_grade' => $finalGrade,
                        'previous_status' => $previousStatus,
                        'new_status' => $resultStatus,
                        'is_external_transfer' => $isExternalTransfer,
                    ])
                    ->log('Updated prior academic credit');

                return $existing->refresh();
            }

            $created = PriorAcademicCredit::query()->create([
                'student_profile_id' => $profile->id,
                'course_specification_id' => $spec->id,
                'curriculum_entry_id' => $entry?->id,
                'term_id' => $termId,
                'academic_year_label' => $ayLabel,
                'term_label' => $termLabel,
                'year_level' => $yearLevel,
                'units' => $units,
                'final_grade' => $finalGrade,
                'result_status' => $resultStatus,
                'source_school' => $sourceSchool,
                'is_external_transfer' => $isExternalTransfer,
                'state' => PriorAcademicCredit::StateActive,
                'recorded_by' => $actor->id,
                'recorded_at' => now(),
            ]);

            activity('academic_records')
                ->performedOn($profile)
                ->causedBy($actor)
                ->event('prior_academic_credit_upserted')
                ->withProperties([
                    'student_number' => $profile->student_number,
                    'prior_academic_credit_id' => $created->id,
                    'course_specification_id' => $spec->id,
                    'new_grade' => $finalGrade,
                    'new_status' => $resultStatus,
                    'is_external_transfer' => $isExternalTransfer,
                ])
                ->log('Recorded prior academic credit');

            return $created;
        }, attempts: 3);

        $this->syncDownstreamProjections($profile, $actor, 'Prior academic credit updated.');

        return $credit;
    }

    /**
     * Settle an active pre-TALA INC record to a completed numeric grade (1.00–4.00) or lapsed failing grade (5.00).
     */
    public function settlePriorInc(
        PriorAcademicCredit $credit,
        string $finalGrade,
        User $actor,
        ?string $resultStatus = null,
        ?string $reason = null,
    ): PriorAcademicCredit {
        $this->authorizeRegistrar($actor);

        $settled = DB::transaction(function () use ($credit, $finalGrade, $actor, $resultStatus, $reason): PriorAcademicCredit {
            $locked = PriorAcademicCredit::query()
                ->with('studentProfile')
                ->lockForUpdate()
                ->findOrFail($credit->id);

            if ($locked->state !== PriorAcademicCredit::StateActive) {
                throw ValidationException::withMessages([
                    'final_grade' => 'Only an active prior INC record can be settled.',
                ]);
            }

            if ($locked->result_status !== PriorAcademicCredit::ResultInc && $locked->effectiveResultCode() !== 'INC') {
                throw ValidationException::withMessages([
                    'final_grade' => 'This prior academic record is not currently marked INC.',
                ]);
            }

            $cleanGrade = strtoupper(trim($finalGrade));
            if ($cleanGrade === '' || $cleanGrade === 'INC') {
                throw ValidationException::withMessages([
                    'final_grade' => 'Enter a final passing grade (1.00–4.00) or 5.00 to settle this prior INC record.',
                ]);
            }

            [$normalizedStatus, $normalizedGrade] = $this->validateAndNormalizeOutcome(
                status: $resultStatus,
                finalGrade: $finalGrade,
                isExternalTransfer: $locked->isExternalTransfer(),
            );

            if ($normalizedStatus === PriorAcademicCredit::ResultInc) {
                throw ValidationException::withMessages([
                    'result_status' => 'Settling a prior INC record requires a resolved outcome (passed, failed, credited, or dropped).',
                ]);
            }

            $previousGrade = $locked->final_grade;

            $locked->forceFill([
                'final_grade' => $normalizedGrade,
                'result_status' => $normalizedStatus,
            ])->save();

            activity('academic_records')
                ->performedOn($locked->studentProfile)
                ->causedBy($actor)
                ->event('prior_inc_settled')
                ->withProperties([
                    'student_number' => $locked->studentProfile?->student_number,
                    'prior_academic_credit_id' => $locked->id,
                    'previous_grade' => $previousGrade,
                    'new_grade' => $normalizedGrade,
                    'result_status' => $normalizedStatus,
                    'reason' => filled($reason) ? trim((string) $reason) : null,
                ])
                ->log("Settled prior INC record to {$normalizedGrade}");

            return $locked->refresh();
        }, attempts: 3);

        $profile = $settled->studentProfile;
        if ($profile instanceof StudentProfile) {
            $this->syncDownstreamProjections($profile, $actor, 'Pre-TALA INC record settled.');
        }

        return $settled;
    }

    /**
     * Revoke an active prior academic credit while preserving immutable audit history.
     */
    public function revokePriorCredit(PriorAcademicCredit $credit, User $actor, ?string $reason = null): PriorAcademicCredit
    {
        $this->authorizeRegistrar($actor);

        $wasActive = false;
        $revoked = DB::transaction(function () use ($credit, $actor, $reason, &$wasActive): PriorAcademicCredit {
            $locked = PriorAcademicCredit::query()
                ->with('studentProfile')
                ->lockForUpdate()
                ->findOrFail($credit->id);

            if ($locked->state === PriorAcademicCredit::StateRevoked) {
                return $locked;
            }

            $wasActive = true;
            $locked->forceFill([
                'state' => PriorAcademicCredit::StateRevoked,
                'revoked_by' => $actor->id,
                'revoked_at' => now(),
            ])->save();

            activity('academic_records')
                ->performedOn($locked->studentProfile)
                ->causedBy($actor)
                ->event('prior_academic_credit_revoked')
                ->withProperties([
                    'student_number' => $locked->studentProfile?->student_number,
                    'prior_academic_credit_id' => $locked->id,
                    'course_specification_id' => $locked->course_specification_id,
                    'reason' => filled($reason) ? trim((string) $reason) : null,
                ])
                ->log('Revoked prior academic credit');

            return $locked->refresh();
        }, attempts: 3);

        if ($wasActive && $revoked->studentProfile instanceof StudentProfile) {
            $this->syncDownstreamProjections($revoked->studentProfile, $actor, 'Prior academic credit revoked.');
        }

        return $revoked;
    }

    /**
     * Idempotently credit all curriculum subjects up to and including (yearLevel, termType) with CREDIT.
     *
     * @return array{created: int, skipped_duplicates: int}
     */
    public function applyCompletedThroughTerm(
        StudentProfile $profile,
        int $yearLevel,
        string $termType,
        User $actor,
        ?string $academicYearLabel = null,
        ?string $sourceSchool = null,
    ): array {
        $this->authorizeRegistrar($actor);

        $targetTermOrder = $this->termTypeOrder($termType);
        if ($yearLevel < 1 || $targetTermOrder === null) {
            throw ValidationException::withMessages([
                'completed_through_term_type' => 'Specify a valid completed-through year level and term type.',
            ]);
        }

        if ($profile->curriculum_version_id === null) {
            throw ValidationException::withMessages([
                'curriculum_version_id' => 'Student profile must have an assigned curriculum version before applying cohort credits.',
            ]);
        }

        $result = DB::transaction(function () use ($profile, $yearLevel, $termType, $targetTermOrder, $actor, $academicYearLabel, $sourceSchool): array {
            $entries = CurriculumEntry::query()
                ->with('courseSpecification')
                ->where('curriculum_version_id', $profile->curriculum_version_id)
                ->orderBy('year_level')
                ->orderBy('sequence')
                ->orderBy('id')
                ->get()
                ->filter(function (CurriculumEntry $entry) use ($yearLevel, $targetTermOrder): bool {
                    $entryYear = $this->parseYearLevel($entry->year_level);
                    $entryTermOrder = $this->termTypeOrder($entry->term_type) ?? 1;

                    if ($entryYear === null) {
                        return false;
                    }

                    return $entryYear < $yearLevel
                        || ($entryYear === $yearLevel && $entryTermOrder <= $targetTermOrder);
                })
                ->values();

            $activeCredits = PriorAcademicCredit::query()
                ->where('student_profile_id', $profile->id)
                ->where('state', PriorAcademicCredit::StateActive)
                ->lockForUpdate()
                ->get();

            $passingSpecIds = $activeCredits
                ->filter(fn (PriorAcademicCredit $credit): bool => $credit->isPassing())
                ->pluck('course_specification_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->flip()
                ->all();

            $created = 0;
            $skipped = 0;
            $cleanAy = filled($academicYearLabel) ? trim((string) $academicYearLabel) : 'Prior Institutional Record';
            $cleanSchool = filled($sourceSchool) ? trim((string) $sourceSchool) : null;

            foreach ($entries as $entry) {
                $spec = $entry->courseSpecification;
                if (! $spec instanceof CourseSpecification || $spec->state === CourseSpecification::StateDraft) {
                    continue;
                }

                if (isset($passingSpecIds[(int) $spec->id])) {
                    $skipped++;

                    continue;
                }

                PriorAcademicCredit::query()->create([
                    'student_profile_id' => $profile->id,
                    'course_specification_id' => (int) $spec->id,
                    'curriculum_entry_id' => (int) $entry->id,
                    'term_id' => null,
                    'academic_year_label' => $cleanAy,
                    'term_label' => filled($entry->term_label) ? (string) $entry->term_label : $this->termLabelFromType($entry->term_type),
                    'year_level' => $this->parseYearLevel($entry->year_level),
                    'units' => round((float) $spec->credit_units, 2),
                    'final_grade' => 'CREDIT',
                    'result_status' => PriorAcademicCredit::ResultCredited,
                    'source_school' => $cleanSchool,
                    'is_external_transfer' => false,
                    'state' => PriorAcademicCredit::StateActive,
                    'recorded_by' => $actor->id,
                    'recorded_at' => now(),
                ]);

                $passingSpecIds[(int) $spec->id] = true;
                $created++;
            }

            if ($created > 0) {
                activity('academic_records')
                    ->performedOn($profile)
                    ->causedBy($actor)
                    ->event('prior_cohort_credits_applied')
                    ->withProperties([
                        'student_number' => $profile->student_number,
                        'completed_through_year_level' => $yearLevel,
                        'completed_through_term_type' => $termType,
                        'created_count' => $created,
                        'skipped_duplicates' => $skipped,
                    ])
                    ->log("Applied {$created} prior cohort credit(s) through Year {$yearLevel}");
            }

            return ['created' => $created, 'skipped_duplicates' => $skipped];
        }, attempts: 3);

        if ($result['created'] > 0) {
            $this->syncDownstreamProjections($profile, $actor, 'Prior cohort credits applied.');
        }

        return $result;
    }

    /**
     * Synchronize prior credited subjects from the checklist while preserving ACTIVE / REVOKED history.
     * Only touches passing/credited rows and never revokes historical failed, INC, or dropped records.
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
        bool $isExternalTransfer = false,
    ): array {
        $this->authorizeRegistrar($actor);

        $selectedIds = array_values(array_unique(array_filter(array_map('intval', $selectedEntryIds), fn (int $id): bool => $id > 0)));

        $result = DB::transaction(function () use ($profile, $selectedIds, $actor, $academicYearLabel, $sourceSchool, $isExternalTransfer): array {
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

            $activePassingCredits = $activeCredits
                ->filter(fn (PriorAcademicCredit $credit): bool => $credit->isPassing());

            $activePassingByEntryId = $activePassingCredits
                ->filter(fn (PriorAcademicCredit $credit): bool => $credit->curriculum_entry_id !== null)
                ->groupBy('curriculum_entry_id');

            $revoked = 0;
            foreach ($activePassingByEntryId as $entryId => $group) {
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
                if ($activePassingByEntryId->has($entryId)) {
                    continue;
                }

                $entry = $entries->get($entryId);
                if (! $entry instanceof CurriculumEntry || $entry->courseSpecification === null) {
                    continue;
                }

                if ($entry->courseSpecification->state === CourseSpecification::StateDraft) {
                    continue;
                }

                $alreadyPassingBySpec = $activePassingCredits->contains(
                    fn (PriorAcademicCredit $credit): bool => (int) $credit->course_specification_id === (int) $entry->course_specification_id
                        && $credit->state === PriorAcademicCredit::StateActive,
                );
                if ($alreadyPassingBySpec) {
                    continue;
                }

                PriorAcademicCredit::query()->create([
                    'student_profile_id' => $profile->id,
                    'course_specification_id' => (int) $entry->course_specification_id,
                    'curriculum_entry_id' => (int) $entry->id,
                    'term_id' => null,
                    'academic_year_label' => $cleanAy,
                    'term_label' => filled($entry->term_label) ? (string) $entry->term_label : $this->termLabelFromType($entry->term_type),
                    'year_level' => $this->parseYearLevel($entry->year_level),
                    'units' => round((float) $entry->courseSpecification->credit_units, 2),
                    'final_grade' => $isExternalTransfer ? 'TC' : 'CREDIT',
                    'result_status' => PriorAcademicCredit::ResultCredited,
                    'source_school' => $cleanSchool,
                    'is_external_transfer' => $isExternalTransfer,
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

        if ($result['added'] > 0 || $result['revoked'] > 0) {
            $this->syncDownstreamProjections($profile, $actor, 'Prior academic credits updated.');
        }

        return $result;
    }

    private function authorizeRegistrar(User $actor): void
    {
        if (! $actor->hasRole(User::StaffRoleRegistrar)) {
            throw new AuthorizationException('Only an authorized Registrar may record prior academic credits.');
        }
    }

    private function assertAllowedSpecification(CourseSpecification $spec, string $field): void
    {
        if ($spec->state === CourseSpecification::StateDraft) {
            throw ValidationException::withMessages([
                $field => 'Draft course specifications cannot be used for prior academic credits. Use an Active or Retired course specification.',
            ]);
        }
    }

    /**
     * Validate and normalize a (result_status, final_grade) pair, rejecting contradictory combinations.
     *
     * @return array{0: string, 1: string}
     */
    private function validateAndNormalizeOutcome(
        ?string $status,
        ?string $finalGrade,
        bool $isExternalTransfer = false,
        string $fieldPrefix = '',
    ): array {
        $gradeField = $fieldPrefix !== '' ? "{$fieldPrefix}.final_grade" : 'final_grade';
        $statusField = $fieldPrefix !== '' ? "{$fieldPrefix}.result_status" : 'result_status';

        $rawStatus = strtolower(trim((string) ($status ?? '')));
        $rawGrade = trim((string) ($finalGrade ?? ''));
        $upperGrade = strtoupper($rawGrade);

        if ($rawStatus !== '' && ! in_array($rawStatus, PriorAcademicCredit::resultStatuses(), true)) {
            throw ValidationException::withMessages([
                $statusField => "Unsupported prior credit status '{$status}'.",
            ]);
        }

        $isNumericGrade = $rawGrade !== '' && is_numeric($rawGrade);
        if ($isNumericGrade) {
            $numericValue = (float) $rawGrade;
            if ($numericValue < 1.00 || $numericValue > 5.00) {
                throw ValidationException::withMessages([
                    $gradeField => 'Numeric grades must be within the institutional 1.00 to 5.00 scale.',
                ]);
            }
        } elseif ($rawGrade !== '' && ! in_array($upperGrade, ['CREDIT', 'CREDITED', 'TC', 'INC', 'DRP', 'W', 'DROPPED'], true)) {
            throw ValidationException::withMessages([
                $gradeField => "Unsupported prior grade code '{$rawGrade}'.",
            ]);
        }

        $normalizedStatus = $rawStatus !== ''
            ? $rawStatus
            : $this->inferResultStatusFromGrade($rawGrade, $isExternalTransfer);

        // Enforce strict consistency between status and grade when both are supplied.
        if ($rawGrade !== '') {
            if ($normalizedStatus === PriorAcademicCredit::ResultPassed) {
                if (($isNumericGrade && (float) $rawGrade > 4.00)
                    || in_array($upperGrade, ['INC', 'DRP', 'W', 'DROPPED'], true)) {
                    throw ValidationException::withMessages([
                        $gradeField => "Grade '{$rawGrade}' contradicts a 'passed' result status.",
                    ]);
                }
            } elseif ($normalizedStatus === PriorAcademicCredit::ResultCredited) {
                if (($isNumericGrade && (float) $rawGrade > 4.00)
                    || in_array($upperGrade, ['INC', 'DRP', 'W', 'DROPPED'], true)) {
                    throw ValidationException::withMessages([
                        $gradeField => "Grade '{$rawGrade}' contradicts a 'credited' result status.",
                    ]);
                }
            } elseif ($normalizedStatus === PriorAcademicCredit::ResultFailed) {
                if (($isNumericGrade && (float) $rawGrade <= 4.00)
                    || in_array($upperGrade, ['CREDIT', 'CREDITED', 'TC', 'INC', 'DRP', 'W', 'DROPPED'], true)) {
                    throw ValidationException::withMessages([
                        $gradeField => "Grade '{$rawGrade}' contradicts a 'failed' result status.",
                    ]);
                }
            } elseif ($normalizedStatus === PriorAcademicCredit::ResultInc) {
                if ($upperGrade !== 'INC') {
                    throw ValidationException::withMessages([
                        $gradeField => "Grade '{$rawGrade}' contradicts an 'inc' result status.",
                    ]);
                }
            } elseif ($normalizedStatus === PriorAcademicCredit::ResultDropped) {
                if (! in_array($upperGrade, ['DRP', 'W', 'DROPPED'], true)) {
                    throw ValidationException::withMessages([
                        $gradeField => "Grade '{$rawGrade}' contradicts a 'dropped' result status.",
                    ]);
                }
            }
        }

        $normalizedGrade = $this->normalizeFinalGrade($rawGrade, $normalizedStatus, $isExternalTransfer);

        return [$normalizedStatus, $normalizedGrade];
    }

    private function inferResultStatusFromGrade(string $rawGrade, bool $isExternalTransfer): string
    {
        $upper = strtoupper(trim($rawGrade));
        if ($upper === '') {
            return PriorAcademicCredit::ResultCredited;
        }
        if (in_array($upper, ['CREDIT', 'TC', 'CREDITED'], true)) {
            return PriorAcademicCredit::ResultCredited;
        }
        if ($upper === 'INC') {
            return PriorAcademicCredit::ResultInc;
        }
        if (in_array($upper, ['DRP', 'W', 'DROPPED'], true)) {
            return PriorAcademicCredit::ResultDropped;
        }
        if (is_numeric($upper)) {
            return (float) $upper <= 4.00
                ? ($isExternalTransfer ? PriorAcademicCredit::ResultCredited : PriorAcademicCredit::ResultPassed)
                : PriorAcademicCredit::ResultFailed;
        }

        return PriorAcademicCredit::ResultPassed;
    }

    private function normalizeFinalGrade(string $clean, string $resultStatus, bool $isExternalTransfer): string
    {
        if ($clean === '') {
            return match ($resultStatus) {
                PriorAcademicCredit::ResultCredited => $isExternalTransfer ? 'TC' : 'CREDIT',
                PriorAcademicCredit::ResultInc => 'INC',
                PriorAcademicCredit::ResultDropped => 'DRP',
                PriorAcademicCredit::ResultFailed => '5.00',
                default => $isExternalTransfer ? 'TC' : 'CREDIT',
            };
        }

        $upper = strtoupper($clean);
        if ($upper === 'CREDITED') {
            return $isExternalTransfer ? 'TC' : 'CREDIT';
        }
        if ($upper === 'DROPPED') {
            return 'DRP';
        }
        if (in_array($upper, ['CREDIT', 'TC', 'INC', 'DRP', 'W'], true)) {
            return $upper;
        }

        if (is_numeric($clean)) {
            return number_format((float) $clean, 2, '.', '');
        }

        return $clean;
    }

    private function syncDownstreamProjections(StudentProfile $profile, User $actor, string $reason): void
    {
        Cache::forget(IrregularStudentReasons::ReviewCountCacheKey);

        $profile->loadMissing('user');
        $cause = $profile->user?->email_verified_at !== null ? 'source-change' : 'prior-credit-change';

        $this->completionReadiness->persist($profile, $actor, $cause);
        $this->supersedeTranscripts->execute($profile, $actor, null, $reason);
    }

    private function dedupeKey(int $courseSpecId, ?int $termId, ?string $academicYearLabel, ?string $termLabel): string
    {
        if ($termId !== null) {
            return $courseSpecId.'|term:'.$termId;
        }

        return $courseSpecId.'|prior:'.strtolower(trim((string) ($academicYearLabel ?? ''))).'|'.strtolower(trim((string) ($termLabel ?? '')));
    }

    private function termTypeOrder(?string $termType): ?int
    {
        return match ($termType) {
            Term::TypeFirstSemester => 1,
            Term::TypeSecondSemester => 2,
            Term::TypeSpecialTerm => 3,
            default => null,
        };
    }

    private function parseYearLevel(mixed $yearLevel): ?int
    {
        if (is_numeric($yearLevel)) {
            return (int) $yearLevel;
        }

        if (is_string($yearLevel) && preg_match('/(\d+)/', $yearLevel, $matches) === 1) {
            return (int) $matches[1];
        }

        $lower = strtolower(trim((string) $yearLevel));

        return match (true) {
            str_contains($lower, 'first') => 1,
            str_contains($lower, 'second') => 2,
            str_contains($lower, 'third') => 3,
            str_contains($lower, 'fourth') => 4,
            default => null,
        };
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
