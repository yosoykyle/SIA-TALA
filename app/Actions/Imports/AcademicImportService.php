<?php

namespace App\Actions\Imports;

use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseRequirement;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\ImportBatch;
use App\Models\Program;
use App\Models\Term;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AcademicImportService
{
    public const Disk = 'local';

    public const CourseSpecificationDirectory = 'imports/course-specifications/uploads';

    public const CurriculumDirectory = 'imports/curricula/uploads';

    /**
     * @return array{directory:string, accepted_file_types:list<string>, max_size_kb:int}
     */
    public static function uploadContract(string $type): array
    {
        return [
            'directory' => $type === ImportBatch::TypeCourseSpecification
                ? self::CourseSpecificationDirectory
                : self::CurriculumDirectory,
            'accepted_file_types' => [
                'text/csv',
                'text/plain',
                'application/csv',
                'application/vnd.ms-excel',
            ],
            'max_size_kb' => 5120,
        ];
    }

    public function validationFindingsCsv(ImportBatch $importBatch, User $actor): string
    {
        $this->authorizeImportDownload($importBatch, $actor);
        $details = $this->validationDetails($importBatch);
        $rows = [['severity', 'source_row', 'message', 'source_values']];

        foreach (['errors' => 'ERROR', 'warnings' => 'WARNING'] as $key => $severity) {
            $findings = $details[$key] ?? [];

            if (! is_array($findings)) {
                continue;
            }

            foreach ($findings as $finding) {
                if (! is_array($finding)) {
                    continue;
                }

                $values = $finding['values'] ?? [];
                $rows[] = [
                    $severity,
                    (string) ($finding['row'] ?? 'batch'),
                    (string) ($finding['message'] ?? 'Review required.'),
                    json_encode(is_array($values) ? $values : [], JSON_UNESCAPED_SLASHES) ?: '{}',
                ];
            }
        }

        $this->recordActivity('import_batch_validation_findings_downloaded', $actor, $importBatch, [
            'import_batch_id' => $importBatch->id,
            'error_count' => $importBatch->error_count,
            'warning_count' => $importBatch->warning_count,
        ]);

        return AcademicImportCsv::toCsv($rows);
    }

    public function sourceCsv(ImportBatch $importBatch, User $actor): string
    {
        $this->authorizeImportDownload($importBatch, $actor);

        if (! Storage::disk((string) $importBatch->source_disk)->exists((string) $importBatch->source_path)) {
            throw ValidationException::withMessages([
                'file' => 'The private source file is no longer available.',
            ]);
        }

        $this->recordActivity('import_batch_source_downloaded', $actor, $importBatch, [
            'import_batch_id' => $importBatch->id,
            'source_checksum' => $importBatch->source_checksum,
        ]);

        return Storage::disk((string) $importBatch->source_disk)->get((string) $importBatch->source_path);
    }

    public function createPreview(string $type, string $filePath, User $actor): ImportBatch
    {
        $this->authorizeImportManagement($actor);
        $this->assertSupportedPath($type, $filePath);

        $preview = $this->preview($type, $filePath);

        return DB::transaction(function () use ($type, $filePath, $actor, $preview): ImportBatch {
            $batch = ImportBatch::query()->create([
                'type' => $type,
                'template_version' => (string) $preview['template_version'],
                'source_disk' => self::Disk,
                'source_path' => $filePath,
                'source_checksum' => hash('sha256', Storage::disk(self::Disk)->get($filePath)),
                'uploaded_by' => $actor->id,
                'row_count' => (int) $preview['summary']['row_count'],
                'error_count' => (int) $preview['summary']['error_count'],
                'warning_count' => (int) $preview['summary']['warning_count'],
                'state' => ImportBatch::StatePendingReview,
                'validation_details' => $preview,
            ]);

            $this->recordActivity('import_batch_preview_created', $actor, $batch, [
                'import_batch_id' => $batch->id,
                'type' => $batch->type,
                'row_count' => $batch->row_count,
                'error_count' => $batch->error_count,
                'warning_count' => $batch->warning_count,
            ]);

            return $batch->fresh();
        });
    }

    public function acknowledgeWarnings(ImportBatch $importBatch, User $actor): ImportBatch
    {
        $this->authorizeImportManagement($actor, $importBatch);

        return DB::transaction(function () use ($importBatch, $actor): ImportBatch {
            $locked = $this->lockedPendingBatch($importBatch);

            if ((int) $locked->warning_count < 1) {
                throw ValidationException::withMessages([
                    'warnings' => 'Only import batches with warnings require acknowledgement.',
                ]);
            }

            $locked->forceFill([
                'acknowledged_at' => CarbonImmutable::now(config('app.timezone')),
            ])->save();

            $this->recordActivity('import_batch_warnings_acknowledged', $actor, $locked, [
                'import_batch_id' => $locked->id,
                'warning_count' => $locked->warning_count,
            ]);

            return $locked->fresh();
        });
    }

    public function post(ImportBatch $importBatch, User $actor): ImportBatch
    {
        $this->authorizeImportManagement($actor, $importBatch);

        return DB::transaction(function () use ($importBatch, $actor): ImportBatch {
            $locked = $this->lockedPendingBatch($importBatch);

            if ((int) $locked->error_count > 0) {
                throw ValidationException::withMessages([
                    'errors' => 'Import batches with validation errors cannot be posted.',
                ]);
            }

            if ((int) $locked->warning_count > 0 && $locked->acknowledged_at === null) {
                throw ValidationException::withMessages([
                    'warnings' => 'Import warnings must be acknowledged before Draft creation.',
                ]);
            }

            $preview = $this->validationDetails($locked);
            $validRows = $this->validRowsFromPreview($preview);

            if ($validRows === []) {
                throw ValidationException::withMessages([
                    'rows' => 'Import batches must contain at least one valid row before posting.',
                ]);
            }

            $this->assertPreviewStillPostable($locked, $validRows);

            if ($locked->type === ImportBatch::TypeCourseSpecification) {
                $summary = $this->postCourseSpecifications($validRows);
            } elseif ($locked->type === ImportBatch::TypeCurriculum) {
                $summary = $this->postCurriculum($validRows);
            } else {
                throw ValidationException::withMessages([
                    'type' => 'Unsupported import batch type.',
                ]);
            }

            $locked->forceFill([
                'state' => ImportBatch::StatePosted,
                'posted_at' => CarbonImmutable::now(config('app.timezone')),
                'validation_details' => [
                    ...$preview,
                    'post_summary' => $summary,
                ],
            ])->save();

            $this->recordActivity('import_batch_posted', $actor, $locked, [
                'import_batch_id' => $locked->id,
                'type' => $locked->type,
                ...$summary,
            ]);

            return $locked->fresh();
        });
    }

    public function cancel(ImportBatch $importBatch, User $actor): ImportBatch
    {
        $this->authorizeImportManagement($actor, $importBatch);

        return DB::transaction(function () use ($importBatch, $actor): ImportBatch {
            $locked = $this->lockedPendingBatch($importBatch);

            $locked->forceFill([
                'state' => ImportBatch::StateCancelled,
            ])->save();

            $this->recordActivity('import_batch_cancelled', $actor, $locked, [
                'import_batch_id' => $locked->id,
                'type' => $locked->type,
            ]);

            return $locked->fresh();
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function preview(string $type, string $filePath): array
    {
        $rows = $this->readCsvRows($filePath);
        $header = array_shift($rows) ?? [];
        $expectedHeaders = $this->headersFor($type);
        $templateVersion = $this->versionFor($type);

        $headerErrors = $this->validateHeaders($header, $expectedHeaders);
        $validRows = [];
        $previewRows = [];
        $errors = $headerErrors;
        $warnings = [];
        $skippedRows = 0;
        $processedRows = 0;

        if ($headerErrors !== []) {
            return $this->previewPayload($type, $templateVersion, $expectedHeaders, 0, $skippedRows, $previewRows, $validRows, $errors, $warnings);
        }

        foreach ($rows as $index => $row) {
            $lineNumber = $index + 2;

            if ($this->blankRow($row)) {
                $skippedRows++;

                continue;
            }

            $processedRows++;
            $normalized = $this->normalizeRow($expectedHeaders, $row);
            [$typedRow, $rowErrors, $rowWarnings] = $type === ImportBatch::TypeCourseSpecification
                ? $this->validateCourseSpecificationRow($normalized)
                : $this->validateCurriculumRow($normalized);
            $typedRow['_source_row'] = $lineNumber;

            $previewRows[] = [
                'row' => $lineNumber,
                'status' => $rowErrors !== [] ? 'ERROR' : ($rowWarnings !== [] ? 'WARNING' : 'VALID'),
                'values' => $normalized,
                'errors' => $rowErrors,
                'warnings' => $rowWarnings,
            ];

            foreach ($rowErrors as $message) {
                $errors[] = $this->validationMessage($lineNumber, $message, $normalized);
            }

            foreach ($rowWarnings as $message) {
                $warnings[] = $this->validationMessage($lineNumber, $message, $normalized);
            }

            if ($rowErrors === []) {
                $validRows[] = $typedRow;
            }
        }

        [$crossErrors, $crossWarnings] = $type === ImportBatch::TypeCourseSpecification
            ? $this->validateCourseSpecificationBatch($validRows)
            : $this->validateCurriculumBatch($validRows);

        return $this->previewPayload(
            type: $type,
            templateVersion: $templateVersion,
            headers: $expectedHeaders,
            rowCount: $processedRows,
            skippedRows: $skippedRows,
            rows: $previewRows,
            validRows: $validRows,
            errors: [...$errors, ...$crossErrors],
            warnings: [...$warnings, ...$crossWarnings],
        );
    }

    /**
     * @param  list<array<string, mixed>>  $validRows
     * @return array<string, int>
     */
    private function postCourseSpecifications(array $validRows): array
    {
        $coursesTouched = [];
        $specificationsTouched = [];
        $componentsTouched = 0;
        $requirementsTouched = 0;

        foreach (collect($validRows)->groupBy(fn (array $row): string => $row['course_code'].'|'.$row['revision_code']) as $rows) {
            /** @var array<string, mixed> $firstRow */
            $firstRow = $rows->first();
            $course = Course::query()
                ->where('code', $firstRow['course_code'])
                ->lockForUpdate()
                ->first();

            if (! $course instanceof Course) {
                $course = Course::query()->create([
                    'code' => $firstRow['course_code'],
                    'state' => $firstRow['course_state'],
                ]);
            }

            $coursesTouched[$course->id] = true;

            $attributes = [
                'title' => $firstRow['title'],
                'description' => $firstRow['description'],
                'credit_units' => $firstRow['credit_units'],
                'grading_profile_key' => $firstRow['grading_profile_key'],
                'grading_profile_version' => $firstRow['grading_profile_version'],
                'scheduling_treatment' => $firstRow['scheduling_treatment'],
                'allowed_modalities' => $firstRow['allowed_modalities'],
                'same_faculty_default' => $firstRow['same_faculty_default'],
                'effective_term_id' => $firstRow['effective_term_id'],
                'state' => CourseSpecification::StateDraft,
            ];
            $specification = CourseSpecification::query()
                ->where('course_id', $course->id)
                ->where('revision_code', $firstRow['revision_code'])
                ->lockForUpdate()
                ->first();

            if ($specification instanceof CourseSpecification && $specification->state !== CourseSpecification::StateDraft) {
                throw ValidationException::withMessages([
                    'stale_preview' => "Course Specification {$firstRow['course_code']} {$firstRow['revision_code']} is no longer Draft and cannot be changed by import.",
                ]);
            }

            if ($specification instanceof CourseSpecification) {
                $specification->fill($attributes)->save();
            } else {
                $specification = CourseSpecification::query()->create([
                    'course_id' => $course->id,
                    'revision_code' => $firstRow['revision_code'],
                    ...$attributes,
                ]);
            }
            $specificationsTouched[$specification->id] = true;

            $specification->components()->delete();
            $specification->requirements()->delete();

            foreach ($rows as $row) {
                if ($row['scheduling_treatment'] === CourseSpecification::SchedulingExternallyArranged) {
                    continue;
                }

                $specification->components()->create([
                    'component_type' => $row['component_type'],
                    'weekly_contact_hours' => $row['weekly_contact_hours'],
                    'meeting_pattern' => $row['meeting_pattern'],
                    'room_type_default' => $row['room_type_default'],
                    'required_room_feature_keys' => $row['required_room_feature_keys'],
                    'modality_restriction' => $row['modality_restriction'],
                    'requires_consecutive_block' => $row['requires_consecutive_block'],
                    'same_faculty' => $row['same_faculty'],
                    'sequence' => $row['component_sequence'],
                ]);
                $componentsTouched++;
            }

            foreach ($this->courseRequirementRows($specification, $firstRow) as $requirementRow) {
                $specification->requirements()->create($requirementRow);
                $requirementsTouched++;
            }
        }

        return [
            'courses_touched' => count($coursesTouched),
            'course_specifications_touched' => count($specificationsTouched),
            'course_components_touched' => $componentsTouched,
            'course_requirements_touched' => $requirementsTouched,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $validRows
     * @return array<string, int>
     */
    private function postCurriculum(array $validRows): array
    {
        $versionsTouched = [];
        $specificationsTouched = [];
        $entriesTouched = 0;

        foreach (collect($validRows)->groupBy(fn (array $row): string => $row['program_code'].'|'.$row['curriculum_version_code']) as $rows) {
            /** @var array<string, mixed> $firstRow */
            $firstRow = $rows->first();
            $program = Program::query()->where('code', $firstRow['program_code'])->lockForUpdate()->firstOrFail();
            $version = CurriculumVersion::query()
                ->where('program_id', $program->id)
                ->where('version_code', $firstRow['curriculum_version_code'])
                ->lockForUpdate()
                ->first();

            if ($version instanceof CurriculumVersion && $version->state !== CurriculumVersion::StateDraft) {
                throw ValidationException::withMessages([
                    'stale_preview' => "Curriculum {$firstRow['program_code']} {$firstRow['curriculum_version_code']} is no longer Draft and cannot be changed by import.",
                ]);
            }

            $versionAttributes = [
                'name' => $firstRow['curriculum_name'],
                'effective_entry_term_id' => $firstRow['effective_entry_term_id'],
                'state' => CurriculumVersion::StateDraft,
                'approval_reference' => null,
                'approved_by' => null,
                'approved_at' => null,
            ];

            if ($version instanceof CurriculumVersion) {
                $version->fill($versionAttributes)->save();
            } else {
                $version = CurriculumVersion::query()->create([
                    'program_id' => $program->id,
                    'version_code' => $firstRow['curriculum_version_code'],
                    ...$versionAttributes,
                ]);
            }

            $versionsTouched[$version->id] = true;
            $version->entries()->delete();

            foreach ($rows as $row) {
                $courseSpecification = $this->curriculumCourseSpecificationForPosting($row);
                $specificationsTouched[$courseSpecification->id] = true;
                $version->entries()->create([
                    'course_specification_id' => $courseSpecification->id,
                    'year_level' => $row['year_level'],
                    'term_label' => $row['term_label'],
                    'term_type' => $row['term_type'],
                    'sequence' => $row['sequence'],
                    'requirement_group' => $row['requirement_group'],
                ]);
                $entriesTouched++;
            }
        }

        return [
            'curriculum_versions_touched' => count($versionsTouched),
            'course_specifications_touched' => count($specificationsTouched),
            'curriculum_entries_touched' => $entriesTouched,
        ];
    }

    /**
     * @param  array<string, string|null>  $row
     * @return array{0:array<string, mixed>,1:list<string>,2:list<string>}
     */
    private function validateCourseSpecificationRow(array $row): array
    {
        $schedulingTreatment = $this->schedulingTreatmentValue($row['scheduling_treatment'] ?? null);
        $requiredFields = [
            'template_version',
            'template_type',
            'course_code',
            'revision_code',
            'title',
            'credit_units',
            'grading_profile_key',
            'grading_profile_version',
            'scheduling_treatment',
            'allowed_modalities',
            'state',
        ];

        if ($schedulingTreatment === CourseSpecification::SchedulingRecurring) {
            array_push($requiredFields, 'component_type', 'weekly_contact_hours', 'meeting_pattern', 'component_sequence');
        }

        $errors = $this->requiredErrors($row, $requiredFields);
        $warnings = [];

        if (($row['template_version'] ?? null) !== CourseSpecificationImportTemplate::Version) {
            $errors[] = 'Template Version must be '.CourseSpecificationImportTemplate::Version.'.';
        }

        if (($row['template_type'] ?? null) !== ImportBatch::TypeCourseSpecification) {
            $errors[] = 'Template Type must be COURSE_SPECIFICATION.';
        }

        $courseState = $this->choiceValue($row['course_state'] ?? Course::StateActive);
        if (! array_key_exists($courseState, Course::stateOptions())) {
            $errors[] = 'Course State must be ACTIVE or RETIRED.';
        }

        if (filled($row['state'] ?? null) && $this->choiceValue($row['state']) !== CourseSpecification::StateDraft) {
            $warnings[] = 'Course Specification import always creates or updates Draft revisions; the supplied State will not activate the course.';
        }

        $gradingProfileKey = $this->gradingProfileValue($row['grading_profile_key'] ?? null);
        if (! array_key_exists($gradingProfileKey, CourseSpecification::gradingProfileOptions())) {
            $errors[] = 'Grading Profile Key is not an approved value.';
        }

        if (! array_key_exists($schedulingTreatment, CourseSpecification::schedulingTreatmentOptions())) {
            $errors[] = 'Scheduling Treatment must be Recurring or ExternallyArranged.';
        }

        $componentType = filled($row['component_type'] ?? null) ? $this->choiceValue($row['component_type']) : null;
        if ($schedulingTreatment === CourseSpecification::SchedulingRecurring
            && ! array_key_exists((string) $componentType, CourseComponent::typeOptions())) {
            $errors[] = 'Component Type must be LECTURE or LABORATORY.';
        }

        $roomType = filled($row['room_type_default'] ?? null) ? $this->choiceValue($row['room_type_default']) : null;
        if ($roomType !== null && ! array_key_exists($roomType, CourseComponent::roomTypeOptions())) {
            $errors[] = 'Room Type Default is not an approved value.';
        }

        $allowedModalities = $this->listValue($row['allowed_modalities'] ?? null);
        if ($allowedModalities === [] || array_diff($allowedModalities, array_keys(CourseSpecification::modalityOptions())) !== []) {
            $errors[] = 'Allowed Modalities must contain approved modality values.';
        }

        $modalityRestriction = filled($row['modality_restriction'] ?? null) ? $this->choiceValue($row['modality_restriction']) : null;
        if ($modalityRestriction !== null && ! array_key_exists($modalityRestriction, CourseSpecification::modalityOptions())) {
            $errors[] = 'Modality Restriction is not an approved modality.';
        }

        foreach (['credit_units'] as $decimalField) {
            if (filled($row[$decimalField] ?? null) && (! is_numeric($row[$decimalField]) || (float) $row[$decimalField] <= 0)) {
                $errors[] = str($decimalField)->replace('_', ' ')->headline().' must be greater than zero.';
            }
        }

        if ($schedulingTreatment === CourseSpecification::SchedulingRecurring
            && filled($row['weekly_contact_hours'] ?? null)
            && (! is_numeric($row['weekly_contact_hours']) || (float) $row['weekly_contact_hours'] <= 0)) {
            $errors[] = 'Weekly Contact Hours must be greater than zero.';
        }

        if ($schedulingTreatment === CourseSpecification::SchedulingExternallyArranged
            && collect([
                'component_type', 'weekly_contact_hours', 'meeting_pattern', 'room_type_default',
                'required_room_feature_keys', 'modality_restriction', 'requires_consecutive_block',
                'same_faculty', 'component_sequence',
            ])->contains(fn (string $field): bool => filled($row[$field] ?? null))) {
            $errors[] = 'Externally arranged courses must leave recurring component fields blank.';
        }

        $meetingPattern = CourseComponent::parseMeetingPattern($row['meeting_pattern'] ?? null);
        $weeklyMinutes = filled($row['weekly_contact_hours'] ?? null) && is_numeric($row['weekly_contact_hours'])
            ? (int) round((float) $row['weekly_contact_hours'] * 60)
            : null;

        if ($schedulingTreatment === CourseSpecification::SchedulingRecurring && $meetingPattern === null) {
            $errors[] = 'Meeting Pattern must be an approved value such as 1x180, 2x90, or 3x60.';
        } elseif ($schedulingTreatment === CourseSpecification::SchedulingRecurring && $weeklyMinutes !== null
            && ($meetingPattern['count'] * $meetingPattern['duration_minutes']) !== $weeklyMinutes) {
            $errors[] = 'Meeting Pattern must equal Weekly Contact Hours.';
        }

        if (filled($row['grading_profile_version'] ?? null) && ! ctype_digit((string) $row['grading_profile_version'])) {
            $errors[] = 'Grading Profile Version must be a whole number.';
        }

        if ($schedulingTreatment === CourseSpecification::SchedulingRecurring
            && filled($row['component_sequence'] ?? null)
            && ! ctype_digit((string) $row['component_sequence'])) {
            $errors[] = 'Component Sequence must be a whole number.';
        }

        foreach (['same_faculty_default', 'requires_consecutive_block', 'same_faculty'] as $booleanField) {
            if (filled($row[$booleanField] ?? null) && $this->booleanValue($row[$booleanField]) === null) {
                $errors[] = str($booleanField)->replace('_', ' ')->headline().' must be yes/no, true/false, or 1/0.';
            }
        }

        foreach (['prerequisite_course_codes', 'corequisite_course_codes'] as $requirementField) {
            if ($this->hasAmbiguousRequirementSyntax($row[$requirementField] ?? null)) {
                $errors[] = str($requirementField)->replace('_', ' ')->headline().' contains ambiguous logic. Replace it with comma-separated course codes before posting.';
            }
        }

        $term = $this->termForLabel($row['effective_term_label'] ?? null);
        if (filled($row['effective_term_label'] ?? null) && $term === null) {
            $errors[] = 'Effective Term Label does not match an existing term.';
        }

        foreach ($this->simpleRequirementCodes($row['prerequisite_course_codes'] ?? null) as $code) {
            if (! Course::query()->where('code', $code)->exists()) {
                $errors[] = "Prerequisite Course Code {$code} does not match an existing course.";
            }
        }

        foreach ($this->simpleRequirementCodes($row['corequisite_course_codes'] ?? null) as $code) {
            if (! Course::query()->where('code', $code)->exists()) {
                $errors[] = "Corequisite Course Code {$code} does not match an existing course.";
            }
        }

        return [
            [
                'template_version' => (string) $row['template_version'],
                'template_type' => ImportBatch::TypeCourseSpecification,
                'course_code' => strtoupper((string) $row['course_code']),
                'course_state' => $courseState,
                'revision_code' => (string) $row['revision_code'],
                'title' => (string) $row['title'],
                'description' => filled($row['description'] ?? null) ? (string) $row['description'] : null,
                'credit_units' => number_format((float) $row['credit_units'], 2, '.', ''),
                'grading_profile_key' => $gradingProfileKey,
                'grading_profile_version' => (int) ($row['grading_profile_version'] ?? 1),
                'scheduling_treatment' => $schedulingTreatment,
                'allowed_modalities' => $allowedModalities,
                'same_faculty_default' => $this->booleanValue($row['same_faculty_default'] ?? null) ?? true,
                'effective_term_id' => $term?->id,
                'state' => CourseSpecification::StateDraft,
                'component_type' => $componentType,
                'weekly_contact_hours' => $schedulingTreatment === CourseSpecification::SchedulingRecurring
                    ? number_format((float) $row['weekly_contact_hours'], 2, '.', '')
                    : null,
                'meeting_pattern' => $schedulingTreatment === CourseSpecification::SchedulingRecurring
                    ? (string) $row['meeting_pattern']
                    : null,
                'room_type_default' => $roomType,
                'required_room_feature_keys' => $this->listValue($row['required_room_feature_keys'] ?? null),
                'modality_restriction' => $modalityRestriction,
                'requires_consecutive_block' => $this->booleanValue($row['requires_consecutive_block'] ?? null) ?? false,
                'same_faculty' => $this->booleanValue($row['same_faculty'] ?? null) ?? true,
                'component_sequence' => $schedulingTreatment === CourseSpecification::SchedulingRecurring
                    ? (int) ($row['component_sequence'] ?? 1)
                    : null,
                'prerequisite_course_codes' => $this->simpleRequirementCodes($row['prerequisite_course_codes'] ?? null),
                'corequisite_course_codes' => $this->simpleRequirementCodes($row['corequisite_course_codes'] ?? null),
            ],
            $errors,
            $warnings,
        ];
    }

    /**
     * @param  array<string, string|null>  $row
     * @return array{0:array<string, mixed>,1:list<string>,2:list<string>}
     */
    private function validateCurriculumRow(array $row): array
    {
        $errors = $this->requiredErrors($row, [
            'template_version',
            'template_type',
            'program_code',
            'curriculum_version_code',
            'curriculum_name',
            'state',
            'course_code',
            'course_revision_code',
            'course_title',
            'course_units',
            'year_level',
            'term_label',
            'term_type',
            'sequence',
            'requirement_group',
        ]);
        $warnings = [];

        if (($row['template_version'] ?? null) !== CurriculumImportTemplate::Version) {
            $errors[] = 'Template Version must be '.CurriculumImportTemplate::Version.'.';
        }

        if (($row['template_type'] ?? null) !== ImportBatch::TypeCurriculum) {
            $errors[] = 'Template Type must be CURRICULUM.';
        }

        if (filled($row['state'] ?? null) && $this->choiceValue($row['state']) !== CurriculumVersion::StateDraft) {
            $warnings[] = 'Curriculum import always creates or updates Draft curriculum versions; the supplied State will not activate the curriculum.';
        }

        $courseCode = strtoupper((string) ($row['course_code'] ?? ''));
        $course = filled($courseCode) ? Course::query()->where('code', $courseCode)->first() : null;

        if (filled($row['program_code'] ?? null) && ! Program::query()->where('code', strtoupper((string) $row['program_code']))->exists()) {
            $errors[] = 'Program Code does not match an existing program.';
        }

        if (filled($courseCode) && ! $course instanceof Course) {
            $errors[] = "Course Code {$courseCode} does not match an existing course identity. Import a complete Course Specification template first.";
        }

        if (filled($row['course_units'] ?? null) && (! is_numeric($row['course_units']) || (float) $row['course_units'] <= 0)) {
            $errors[] = 'Course Units must be greater than zero.';
        }

        [$prerequisiteGroups, $prerequisiteErrors] = $this->curriculumPrerequisiteGroups($row['prerequisite_course_codes'] ?? null);
        $errors = [...$errors, ...$prerequisiteErrors];

        foreach (collect($prerequisiteGroups)->flatten()->unique() as $prerequisiteCode) {
            if ($prerequisiteCode === $courseCode) {
                $errors[] = "Course {$courseCode} cannot require itself as a prerequisite.";

                continue;
            }

            if (! Course::query()->where('code', $prerequisiteCode)->exists()) {
                $errors[] = "Prerequisite Course Code {$prerequisiteCode} does not match an existing course identity.";
            }
        }

        if ($course instanceof Course && filled($row['course_revision_code'] ?? null) && is_numeric($row['course_units'] ?? null)) {
            $target = $this->courseSpecificationFor($courseCode, (string) $row['course_revision_code']);
            $materialValues = [
                'course_title' => (string) ($row['course_title'] ?? ''),
                'course_units' => number_format((float) $row['course_units'], 2, '.', ''),
                'prerequisite_groups' => $prerequisiteGroups,
            ];

            if ($target instanceof CourseSpecification) {
                if ($target->state === CourseSpecification::StateRetired) {
                    $errors[] = "Retired Course Specification {$courseCode} {$row['course_revision_code']} cannot be used for a new curriculum entry.";
                } elseif ($target->state !== CourseSpecification::StateDraft
                    && $this->curriculumSpecificationMateriallyDiffers($target, $materialValues)) {
                    $errors[] = "Course Specification {$courseCode} {$row['course_revision_code']} is {$target->state} and cannot be overwritten. Supply a new Draft revision code.";
                } elseif ($target->state === CourseSpecification::StateDraft
                    && $this->curriculumSpecificationMateriallyDiffers($target, $materialValues)) {
                    $warnings[] = "Curriculum source values will update Draft Course Specification {$courseCode} {$row['course_revision_code']}; review the proposed material changes before posting.";
                }
            } else {
                $activeBase = $this->activeCourseSpecificationFor($courseCode);

                if (! $activeBase instanceof CourseSpecification) {
                    $errors[] = "Course {$courseCode} has no complete Active revision to clone. Import a complete Course Specification template for {$row['course_revision_code']} first.";
                } else {
                    $warnings[] = "Curriculum source values will propose Draft Course Specification {$courseCode} {$row['course_revision_code']}. TALA will inherit components, grading, modality, and other operational enrichment from preserved Active revision {$activeBase->revision_code}; the Registrar must review that enrichment before activation.";
                }
            }
        }

        $term = $this->termForLabel($row['effective_entry_term_label'] ?? null);
        if (filled($row['effective_entry_term_label'] ?? null) && $term === null) {
            $errors[] = 'Effective Entry Term Label does not match an existing term.';
        }

        $termType = $this->choiceValue($row['term_type'] ?? null);
        if (! array_key_exists($termType, Term::typeOptions())) {
            $errors[] = 'Term Type is not an approved term type.';
        }

        $requirementGroup = strtolower((string) ($row['requirement_group'] ?? ''));
        if (! array_key_exists($requirementGroup, CurriculumEntry::requirementGroupOptions())) {
            $errors[] = 'Requirement Group must be required or elective.';
        }

        if (filled($row['sequence'] ?? null) && ! ctype_digit((string) $row['sequence'])) {
            $errors[] = 'Sequence must be a whole number.';
        }

        if (filled($row['client_total_units'] ?? null) && ! is_numeric($row['client_total_units'])) {
            $errors[] = 'Client Total Units must be numeric when supplied.';
        }

        return [
            [
                'template_version' => (string) $row['template_version'],
                'template_type' => ImportBatch::TypeCurriculum,
                'program_code' => strtoupper((string) $row['program_code']),
                'curriculum_version_code' => (string) $row['curriculum_version_code'],
                'curriculum_name' => (string) $row['curriculum_name'],
                'effective_entry_term_id' => $term?->id,
                'state' => CurriculumVersion::StateDraft,
                'course_code' => strtoupper((string) $row['course_code']),
                'course_revision_code' => (string) $row['course_revision_code'],
                'course_title' => (string) $row['course_title'],
                'course_units' => number_format((float) $row['course_units'], 2, '.', ''),
                'prerequisite_course_codes' => filled($row['prerequisite_course_codes'] ?? null) ? (string) $row['prerequisite_course_codes'] : null,
                'prerequisite_groups' => $prerequisiteGroups,
                'year_level' => (string) $row['year_level'],
                'term_label' => (string) $row['term_label'],
                'term_type' => $termType,
                'sequence' => (int) ($row['sequence'] ?? 1),
                'requirement_group' => $requirementGroup,
                'client_total_units' => filled($row['client_total_units'] ?? null) && is_numeric($row['client_total_units'])
                    ? number_format((float) $row['client_total_units'], 2, '.', '')
                    : null,
            ],
            $errors,
            $warnings,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{0:list<array<string, mixed>>,1:list<array<string, mixed>>}
     */
    private function validateCourseSpecificationBatch(array $rows): array
    {
        $errors = [];
        $warnings = [];
        $components = [];
        $contactHours = [];

        foreach (collect($rows)->groupBy(fn (array $row): string => $row['course_code'].'|'.$row['revision_code']) as $groupRows) {
            /** @var array<string, mixed> $firstRow */
            $firstRow = $groupRows->first();

            foreach ($groupRows->skip(1) as $row) {
                foreach ([
                    'course_state',
                    'title',
                    'description',
                    'credit_units',
                    'grading_profile_key',
                    'grading_profile_version',
                    'scheduling_treatment',
                    'allowed_modalities',
                    'same_faculty_default',
                    'effective_term_id',
                    'prerequisite_course_codes',
                    'corequisite_course_codes',
                ] as $field) {
                    if ($row[$field] !== $firstRow[$field]) {
                        $label = str($field)->replace('_', ' ')->headline();
                        $errors[] = $this->validationMessage(
                            is_int($row['_source_row'] ?? null) ? $row['_source_row'] : null,
                            "{$label} must be consistent for every {$row['course_code']} {$row['revision_code']} component row.",
                            $row,
                        );
                    }
                }
            }
        }

        foreach ($rows as $row) {
            $key = $row['course_code'].'|'.$row['revision_code'];

            if ($this->activeCourseSpecificationExists($row['course_code'], $row['revision_code'])) {
                $errors[] = $this->validationMessage(is_int($row['_source_row'] ?? null) ? $row['_source_row'] : null, "Active Course Specification {$row['course_code']} {$row['revision_code']} already exists and cannot be overwritten.", $row);
            }

            if ($row['scheduling_treatment'] === CourseSpecification::SchedulingRecurring) {
                $componentKey = $key.'|'.$row['component_type'];
                if (isset($components[$componentKey])) {
                    $errors[] = $this->validationMessage(is_int($row['_source_row'] ?? null) ? $row['_source_row'] : null, "Duplicate component {$row['component_type']} for {$row['course_code']} {$row['revision_code']}.", $row);
                }

                $components[$componentKey] = true;
                $contactHours[$key] = ($contactHours[$key] ?? 0.0) + (float) $row['weekly_contact_hours'];
            }
        }

        foreach ($rows as $row) {
            $key = $row['course_code'].'|'.$row['revision_code'];

            if ($row['scheduling_treatment'] === CourseSpecification::SchedulingRecurring
                && abs(($contactHours[$key] ?? 0.0) - (float) $row['credit_units']) > 0.001) {
                $warnings[] = $this->validationMessage(is_int($row['_source_row'] ?? null) ? $row['_source_row'] : null, "Total component contact hours do not match Credit Units for {$row['course_code']} {$row['revision_code']}; review before posting.", $row);
                unset($contactHours[$key]);
            }
        }

        return [$errors, $warnings];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{0:list<array<string, mixed>>,1:list<array<string, mixed>>}
     */
    private function validateCurriculumBatch(array $rows): array
    {
        $errors = [];
        $warnings = [];
        $entries = [];

        foreach (collect($rows)->groupBy(fn (array $row): string => $row['program_code'].'|'.$row['curriculum_version_code']) as $groupRows) {
            /** @var array<string, mixed> $firstRow */
            $firstRow = $groupRows->first();
            $hasInconsistentClientTotal = false;

            foreach ($groupRows->skip(1) as $row) {
                foreach (['curriculum_name', 'effective_entry_term_id', 'client_total_units'] as $field) {
                    if ($row[$field] !== $firstRow[$field]) {
                        $label = str($field)->replace('_', ' ')->headline();
                        $errors[] = $this->validationMessage(
                            is_int($row['_source_row'] ?? null) ? $row['_source_row'] : null,
                            "{$label} must be consistent for every {$row['program_code']} {$row['curriculum_version_code']} entry row.",
                            $row,
                        );

                        if ($field === 'client_total_units') {
                            $hasInconsistentClientTotal = true;
                        }
                    }
                }
            }

            if ($firstRow['client_total_units'] !== null && ! $hasInconsistentClientTotal) {
                $systemSubtotal = $groupRows->sum(fn (array $row): float => (float) $row['course_units']);
                $clientTotal = (float) $firstRow['client_total_units'];

                if (abs($systemSubtotal - $clientTotal) > 0.001) {
                    $warnings[] = $this->validationMessage(
                        is_int($firstRow['_source_row'] ?? null) ? $firstRow['_source_row'] : null,
                        sprintf(
                            'Client Total Units %.2f does not match the system-computed subtotal %.2f for %s %s; the system subtotal remains authoritative.',
                            $clientTotal,
                            $systemSubtotal,
                            $firstRow['program_code'],
                            $firstRow['curriculum_version_code'],
                        ),
                        $firstRow,
                    );
                }
            }
        }

        foreach (collect($rows)->groupBy(fn (array $row): string => $row['course_code'].'|'.$row['course_revision_code']) as $groupRows) {
            /** @var array<string, mixed> $firstRow */
            $firstRow = $groupRows->first();

            foreach ($groupRows->skip(1) as $row) {
                foreach (['course_title', 'course_units', 'prerequisite_groups'] as $field) {
                    if ($row[$field] !== $firstRow[$field]) {
                        $label = str($field)->replace('_', ' ')->headline();
                        $errors[] = $this->validationMessage(
                            is_int($row['_source_row'] ?? null) ? $row['_source_row'] : null,
                            "{$label} must be consistent for every {$row['course_code']} {$row['course_revision_code']} curriculum row.",
                            $row,
                        );
                    }
                }
            }
        }

        foreach ($rows as $row) {
            $program = Program::query()->where('code', $row['program_code'])->first();

            if ($program instanceof Program && CurriculumVersion::query()
                ->where('program_id', $program->id)
                ->where('version_code', $row['curriculum_version_code'])
                ->where('state', '!=', CurriculumVersion::StateDraft)
                ->exists()) {
                $errors[] = $this->validationMessage(is_int($row['_source_row'] ?? null) ? $row['_source_row'] : null, "Non-Draft Curriculum {$row['program_code']} {$row['curriculum_version_code']} already exists and cannot be overwritten.", $row);
            }

            foreach ($this->curriculumSpecificationCurrentErrors($row) as $message) {
                $errors[] = $this->validationMessage(is_int($row['_source_row'] ?? null) ? $row['_source_row'] : null, $message, $row);
            }

            $entryKey = implode('|', [
                $row['program_code'],
                $row['curriculum_version_code'],
                $row['year_level'],
                $row['term_label'],
                $row['course_code'],
                $row['course_revision_code'],
            ]);

            if (isset($entries[$entryKey])) {
                $errors[] = $this->validationMessage(is_int($row['_source_row'] ?? null) ? $row['_source_row'] : null, 'Duplicate curriculum entry row detected.', $row);
            }

            $entries[$entryKey] = true;
        }

        return [$errors, $warnings];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<array<string, mixed>>
     */
    private function courseRequirementRows(CourseSpecification $specification, array $row): array
    {
        $requirements = [];

        foreach ([
            CourseRequirement::TypePrerequisite => $row['prerequisite_course_codes'],
            CourseRequirement::TypeCorequisite => $row['corequisite_course_codes'],
        ] as $type => $courseCodes) {
            foreach ($courseCodes as $index => $courseCode) {
                $course = Course::query()->where('code', $courseCode)->first();

                if (! $course instanceof Course) {
                    continue;
                }

                $requirements[] = [
                    'course_specification_id' => $specification->id,
                    'rule_type' => $type,
                    'group_key' => $type.'-'.($index + 1),
                    'related_course_id' => $course->id,
                    'direction' => CourseRequirement::DirectionRequires,
                    'required_outcome' => CourseRequirement::RequiredOutcomePassed,
                    'authority' => 'CSV_IMPORT',
                    'state' => CourseRequirement::StateActive,
                    'sequence' => $index + 1,
                ];
            }
        }

        return $requirements;
    }

    /**
     * @return array{0:list<list<string>>,1:list<string>}
     */
    private function curriculumPrerequisiteGroups(?string $source): array
    {
        if (blank($source)) {
            return [[], []];
        }

        if (preg_match('/[\/;|]|\band\b/i', (string) $source) === 1) {
            return [[], ['Prerequisite Course Codes contains ambiguous syntax. Use commas for AND groups and the word or for alternatives.']];
        }

        $groups = [];

        foreach (explode(',', (string) $source) as $groupSource) {
            $codes = collect(preg_split('/\s+or\s+/i', trim($groupSource)) ?: [])
                ->map(fn (string $code): string => strtoupper(trim($code)))
                ->filter()
                ->values()
                ->all();

            if ($codes === []) {
                return [[], ['Prerequisite Course Codes contains an empty requirement group.']];
            }

            $groups[] = $codes;
        }

        return [$groups, []];
    }

    /**
     * @param  array<string, mixed>  $materialValues
     */
    private function curriculumSpecificationMateriallyDiffers(CourseSpecification $specification, array $materialValues): bool
    {
        return $specification->title !== $materialValues['course_title']
            || number_format((float) $specification->credit_units, 2, '.', '') !== $materialValues['course_units']
            || $this->specificationPrerequisiteGroups($specification) !== $materialValues['prerequisite_groups'];
    }

    /**
     * @return list<list<string>>
     */
    private function specificationPrerequisiteGroups(CourseSpecification $specification): array
    {
        return $specification->requirements()
            ->where('rule_type', CourseRequirement::TypePrerequisite)
            ->with('relatedCourse:id,code')
            ->orderBy('sequence')
            ->get()
            ->groupBy('group_key')
            ->map(fn ($requirements): array => $requirements
                ->map(fn (CourseRequirement $requirement): string => (string) $requirement->relatedCourse?->code)
                ->filter()
                ->values()
                ->all())
            ->values()
            ->all();
    }

    private function activeCourseSpecificationFor(string $courseCode): ?CourseSpecification
    {
        return CourseSpecification::query()
            ->where('state', CourseSpecification::StateActive)
            ->whereHas('course', fn ($query) => $query->where('code', $courseCode))
            ->latest('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function curriculumSpecificationCurrentErrors(array $row): array
    {
        $course = Course::query()->where('code', $row['course_code'])->first();

        if (! $course instanceof Course) {
            return ["Course Code {$row['course_code']} no longer matches an existing course identity."];
        }

        $target = $this->courseSpecificationFor($row['course_code'], $row['course_revision_code']);
        $materialValues = [
            'course_title' => $row['course_title'],
            'course_units' => $row['course_units'],
            'prerequisite_groups' => $row['prerequisite_groups'],
        ];

        if ($target instanceof CourseSpecification) {
            if ($target->state === CourseSpecification::StateRetired) {
                return ["Retired Course Specification {$row['course_code']} {$row['course_revision_code']} cannot be used for a new curriculum entry."];
            }

            if ($target->state === CourseSpecification::StateActive
                && $this->curriculumSpecificationMateriallyDiffers($target, $materialValues)) {
                return ["Active Course Specification {$row['course_code']} {$row['course_revision_code']} changed or conflicts with the curriculum source and cannot be overwritten."];
            }

            return [];
        }

        if (! $this->activeCourseSpecificationFor($row['course_code']) instanceof CourseSpecification) {
            return ["Course {$row['course_code']} has no complete Active revision to clone. Import a complete Course Specification template for {$row['course_revision_code']} first."];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function curriculumCourseSpecificationForPosting(array $row): CourseSpecification
    {
        $errors = $this->curriculumSpecificationCurrentErrors($row);

        if ($errors !== []) {
            throw ValidationException::withMessages([
                'stale_preview' => implode(' ', $errors),
            ]);
        }

        $course = Course::query()->where('code', $row['course_code'])->lockForUpdate()->firstOrFail();
        $target = CourseSpecification::query()
            ->where('course_id', $course->id)
            ->where('revision_code', $row['course_revision_code'])
            ->lockForUpdate()
            ->first();

        if ($target instanceof CourseSpecification) {
            $materialValues = [
                'course_title' => $row['course_title'],
                'course_units' => $row['course_units'],
                'prerequisite_groups' => $row['prerequisite_groups'],
            ];

            if ($target->state === CourseSpecification::StateActive
                && ! $this->curriculumSpecificationMateriallyDiffers($target, $materialValues)) {
                return $target;
            }

            if ($target->state !== CourseSpecification::StateDraft) {
                throw ValidationException::withMessages([
                    'stale_preview' => "Course Specification {$row['course_code']} {$row['course_revision_code']} is no longer Draft and cannot be changed by import.",
                ]);
            }

            $target->fill([
                'title' => $row['course_title'],
                'credit_units' => $row['course_units'],
            ])->save();
            $this->replaceCurriculumPrerequisites($target, $row['prerequisite_groups']);

            return $target;
        }

        $base = CourseSpecification::query()
            ->where('course_id', $course->id)
            ->where('state', CourseSpecification::StateActive)
            ->latest('id')
            ->lockForUpdate()
            ->firstOrFail();
        $target = CourseSpecification::query()->create([
            'course_id' => $course->id,
            'revision_code' => $row['course_revision_code'],
            'title' => $row['course_title'],
            'description' => $base->description,
            'credit_units' => $row['course_units'],
            'grading_profile_key' => $base->grading_profile_key,
            'grading_profile_version' => $base->grading_profile_version,
            'authority_reference' => $base->authority_reference,
            'effective_from' => $base->effective_from,
            'effective_until' => $base->effective_until,
            'academic_classification' => $base->academic_classification,
            'scheduling_treatment' => $base->scheduling_treatment,
            'allowed_modalities' => $base->allowed_modalities,
            'same_faculty_default' => $base->same_faculty_default,
            'effective_term_id' => $base->effective_term_id,
            'state' => CourseSpecification::StateDraft,
        ]);

        foreach ($base->components()->orderBy('sequence')->get() as $component) {
            $target->components()->create([
                'component_type' => $component->component_type,
                'weekly_contact_hours' => $component->weekly_contact_hours,
                'meeting_pattern' => $component->meeting_pattern,
                'room_type_default' => $component->room_type_default,
                'required_room_feature_keys' => $component->required_room_feature_keys,
                'modality_restriction' => $component->modality_restriction,
                'requires_consecutive_block' => $component->requires_consecutive_block,
                'same_faculty' => $component->same_faculty,
                'sequence' => $component->sequence,
            ]);
        }

        foreach ($base->requirements()->where('rule_type', '!=', CourseRequirement::TypePrerequisite)->orderBy('sequence')->get() as $requirement) {
            $target->requirements()->create([
                'rule_type' => $requirement->rule_type,
                'group_key' => $requirement->group_key,
                'related_course_id' => $requirement->related_course_id,
                'direction' => $requirement->direction,
                'equivalency_scope' => $requirement->equivalency_scope,
                'required_outcome' => $requirement->required_outcome,
                'minimum_grade' => $requirement->minimum_grade,
                'accepts_transfer_credit' => $requirement->accepts_transfer_credit,
                'effective_from' => $requirement->effective_from,
                'effective_until' => $requirement->effective_until,
                'authority' => $requirement->authority,
                'state' => $requirement->state,
                'sequence' => $requirement->sequence,
            ]);
        }

        $this->replaceCurriculumPrerequisites($target, $row['prerequisite_groups']);

        return $target;
    }

    /**
     * @param  list<list<string>>  $groups
     */
    private function replaceCurriculumPrerequisites(CourseSpecification $specification, array $groups): void
    {
        $specification->requirements()
            ->where('rule_type', CourseRequirement::TypePrerequisite)
            ->delete();
        $sequence = 1;

        foreach ($groups as $groupIndex => $courseCodes) {
            foreach ($courseCodes as $courseCode) {
                $relatedCourse = Course::query()->where('code', $courseCode)->firstOrFail();
                $specification->requirements()->create([
                    'rule_type' => CourseRequirement::TypePrerequisite,
                    'group_key' => 'prerequisite-'.($groupIndex + 1),
                    'related_course_id' => $relatedCourse->id,
                    'direction' => CourseRequirement::DirectionRequires,
                    'required_outcome' => CourseRequirement::RequiredOutcomePassed,
                    'authority' => 'CURRICULUM_CSV_IMPORT',
                    'state' => CourseRequirement::StateActive,
                    'sequence' => $sequence++,
                ]);
            }
        }
    }

    private function activeCourseSpecificationExists(string $courseCode, string $revisionCode): bool
    {
        return CourseSpecification::query()
            ->where('revision_code', $revisionCode)
            ->where('state', CourseSpecification::StateActive)
            ->whereHas('course', fn ($query) => $query->where('code', $courseCode))
            ->exists();
    }

    private function courseSpecificationFor(string $courseCode, string $revisionCode): ?CourseSpecification
    {
        return CourseSpecification::query()
            ->where('revision_code', $revisionCode)
            ->whereHas('course', fn ($query) => $query->where('code', $courseCode))
            ->first();
    }

    /**
     * @return list<list<string|null>>
     */
    private function readCsvRows(string $filePath): array
    {
        if (! Storage::disk(self::Disk)->exists($filePath)) {
            throw ValidationException::withMessages([
                'file' => 'Uploaded import file was not found in private storage.',
            ]);
        }

        if (! in_array(strtolower(pathinfo($filePath, PATHINFO_EXTENSION)), ['csv', 'txt'], true)) {
            throw ValidationException::withMessages([
                'file' => 'Only CSV import files are supported for TAL-82D.',
            ]);
        }

        $contents = Storage::disk(self::Disk)->get($filePath);

        if (function_exists('mb_check_encoding') && ! mb_check_encoding($contents, 'UTF-8')) {
            throw ValidationException::withMessages([
                'file' => 'CSV imports must be encoded as UTF-8.',
            ]);
        }

        $stream = fopen('php://temp', 'r+');

        if ($stream === false) {
            throw ValidationException::withMessages([
                'file' => 'Uploaded import file could not be read.',
            ]);
        }

        fwrite($stream, $contents);
        rewind($stream);

        $rows = [];

        while (($row = fgetcsv($stream)) !== false) {
            $rows[] = array_map(
                fn ($value): ?string => is_string($value) ? trim($value) : null,
                $row,
            );
        }

        fclose($stream);

        return $rows;
    }

    /**
     * @param  list<string|null>  $header
     * @param  list<string>  $expectedHeaders
     * @return list<array<string, mixed>>
     */
    private function validateHeaders(array $header, array $expectedHeaders): array
    {
        $errors = [];

        if ($header !== $expectedHeaders) {
            $errors[] = $this->validationMessage(1, 'CSV headers must match the current template exactly.', [
                'expected_headers' => $expectedHeaders,
                'actual_headers' => $header,
            ]);
        }

        $duplicates = collect($header)
            ->filter(fn (?string $value): bool => filled($value))
            ->countBy()
            ->filter(fn (int $count): bool => $count > 1)
            ->keys()
            ->values()
            ->all();

        if ($duplicates !== []) {
            $errors[] = $this->validationMessage(1, 'CSV headers must not contain duplicates: '.implode(', ', $duplicates).'.', [
                'duplicate_headers' => $duplicates,
            ]);
        }

        return $errors;
    }

    /**
     * @param  list<string>  $headers
     * @param  list<string|null>  $row
     * @return array<string, string|null>
     */
    private function normalizeRow(array $headers, array $row): array
    {
        $normalized = [];

        foreach ($headers as $index => $header) {
            $normalized[$header] = isset($row[$index]) && filled($row[$index])
                ? trim((string) $row[$index])
                : null;
        }

        return $normalized;
    }

    /**
     * @param  list<string|null>  $row
     */
    private function blankRow(array $row): bool
    {
        return collect($row)->every(fn (?string $value): bool => blank($value));
    }

    /**
     * @param  array<string, string|null>  $row
     * @param  list<string>  $fields
     * @return list<string>
     */
    private function requiredErrors(array $row, array $fields): array
    {
        $errors = [];

        foreach ($fields as $field) {
            if (blank($row[$field] ?? null)) {
                $errors[] = str($field)->replace('_', ' ')->headline().' is required.';
            }
        }

        return $errors;
    }

    private function choiceValue(?string $value): string
    {
        return str($value ?? '')
            ->trim()
            ->upper()
            ->replace([' ', '-'], '_')
            ->toString();
    }

    private function gradingProfileValue(?string $value): string
    {
        return str($value ?? '')
            ->trim()
            ->lower()
            ->toString();
    }

    private function schedulingTreatmentValue(?string $value): string
    {
        return match (str($value ?? '')->trim()->lower()->replace([' ', '-', '_'], '')->toString()) {
            'recurring' => CourseSpecification::SchedulingRecurring,
            'externallyarranged' => CourseSpecification::SchedulingExternallyArranged,
            default => '',
        };
    }

    /**
     * @return list<string>
     */
    private function listValue(?string $value): array
    {
        return collect(preg_split('/[|,]/', (string) $value) ?: [])
            ->map(fn (string $item): string => $this->choiceValue($item))
            ->filter()
            ->values()
            ->all();
    }

    private function booleanValue(?string $value): ?bool
    {
        return match (strtolower(trim((string) $value))) {
            '1', 'yes', 'y', 'true' => true,
            '0', 'no', 'n', 'false' => false,
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    private function simpleRequirementCodes(?string $value): array
    {
        if (blank($value) || $this->hasAmbiguousRequirementSyntax($value)) {
            return [];
        }

        return collect(explode(',', (string) $value))
            ->map(fn (string $courseCode): string => strtoupper(trim($courseCode)))
            ->filter()
            ->values()
            ->all();
    }

    private function hasAmbiguousRequirementSyntax(?string $value): bool
    {
        if (blank($value)) {
            return false;
        }

        return str_contains((string) $value, ';')
            || str_contains((string) $value, '/')
            || str_contains((string) $value, '|')
            || preg_match('/\b(or|and)\b/i', (string) $value) === 1;
    }

    private function termForLabel(?string $label): ?Term
    {
        if (blank($label)) {
            return null;
        }

        return Term::query()->where('label', trim((string) $label))->first();
    }

    /**
     * @return list<string>
     */
    private function headersFor(string $type): array
    {
        return match ($type) {
            ImportBatch::TypeCourseSpecification => CourseSpecificationImportTemplate::headers(),
            ImportBatch::TypeCurriculum => CurriculumImportTemplate::headers(),
            default => throw ValidationException::withMessages([
                'type' => 'Unsupported import type.',
            ]),
        };
    }

    private function versionFor(string $type): string
    {
        return match ($type) {
            ImportBatch::TypeCourseSpecification => CourseSpecificationImportTemplate::Version,
            ImportBatch::TypeCurriculum => CurriculumImportTemplate::Version,
            default => throw ValidationException::withMessages([
                'type' => 'Unsupported import type.',
            ]),
        };
    }

    private function assertSupportedPath(string $type, string $filePath): void
    {
        $directory = self::uploadContract($type)['directory'];

        if (! str_starts_with($filePath, $directory.'/')) {
            throw ValidationException::withMessages([
                'file' => 'Imports must be uploaded through the approved private import directory.',
            ]);
        }
    }

    private function authorizeImportManagement(User $actor, ?ImportBatch $importBatch = null): void
    {
        if ($importBatch instanceof ImportBatch) {
            Gate::forUser($actor)->authorize('update', $importBatch);

            return;
        }

        Gate::forUser($actor)->authorize('manage', ImportBatch::class);
    }

    private function authorizeImportDownload(ImportBatch $importBatch, User $actor): void
    {
        Gate::forUser($actor)->authorize('download', $importBatch);
    }

    private function lockedPendingBatch(ImportBatch $importBatch): ImportBatch
    {
        $locked = ImportBatch::query()
            ->lockForUpdate()
            ->findOrFail($importBatch->getKey());

        if (! $locked->isPendingReview()) {
            throw ValidationException::withMessages([
                'state' => 'Only pending import batches can be changed.',
            ]);
        }

        return $locked;
    }

    /**
     * @param  list<array<string, mixed>>  $validRows
     */
    private function assertPreviewStillPostable(ImportBatch $importBatch, array $validRows): void
    {
        if (! Storage::disk((string) $importBatch->source_disk)->exists((string) $importBatch->source_path)) {
            throw ValidationException::withMessages([
                'stale_preview' => 'The source file is no longer available. Create a new preview before posting.',
            ]);
        }

        $currentChecksum = hash('sha256', Storage::disk((string) $importBatch->source_disk)->get((string) $importBatch->source_path));

        if (! hash_equals((string) $importBatch->source_checksum, $currentChecksum)) {
            throw ValidationException::withMessages([
                'stale_preview' => 'The source file changed after preview. Upload it again and review a new preview.',
            ]);
        }

        [$errors] = $importBatch->type === ImportBatch::TypeCourseSpecification
            ? $this->validateCourseSpecificationBatch($validRows)
            : $this->validateCurriculumBatch($validRows);

        if ($errors === []) {
            return;
        }

        $messages = collect($errors)
            ->map(fn (array $finding): string => (string) ($finding['message'] ?? 'Authoritative records changed after preview.'))
            ->unique()
            ->implode(' ');

        throw ValidationException::withMessages([
            'stale_preview' => $messages.' Create a new preview before posting.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validationDetails(ImportBatch $importBatch): array
    {
        $details = $importBatch->getAttribute('validation_details');

        return is_array($details) ? $details : [];
    }

    /**
     * @param  array<string, mixed>  $preview
     * @return list<array<string, mixed>>
     */
    private function validRowsFromPreview(array $preview): array
    {
        $validRows = $preview['valid_rows'] ?? [];

        if (! is_array($validRows)) {
            return [];
        }

        return collect($validRows)
            ->filter(fn (mixed $row): bool => is_array($row))
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $headers
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $validRows
     * @param  list<array<string, mixed>>  $errors
     * @param  list<array<string, mixed>>  $warnings
     * @return array<string, mixed>
     */
    private function previewPayload(
        string $type,
        string $templateVersion,
        array $headers,
        int $rowCount,
        int $skippedRows,
        array $rows,
        array $validRows,
        array $errors,
        array $warnings,
    ): array {
        return [
            'schema' => 'tala_academic_import_preview_v1',
            'type' => $type,
            'template_version' => $templateVersion,
            'headers' => $headers,
            'summary' => [
                'row_count' => $rowCount,
                'valid_row_count' => count($validRows),
                'error_count' => count($errors),
                'warning_count' => count($warnings),
                'skipped_row_count' => $skippedRows,
            ],
            'rows' => $rows,
            'valid_rows' => $validRows,
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function validationMessage(?int $row, string $message, array $values): array
    {
        return [
            'row' => $row,
            'message' => $message,
            'values' => $values,
        ];
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function recordActivity(string $event, User $actor, ImportBatch $importBatch, array $properties): void
    {
        $timestamp = CarbonImmutable::now(config('app.timezone'));

        DB::table('activity_log')->insert([
            'log_name' => 'imports',
            'description' => 'Import batch state changed.',
            'subject_type' => ImportBatch::class,
            'subject_id' => null,
            'event' => $event,
            'causer_type' => User::class,
            'causer_id' => $actor->id,
            'properties' => json_encode($properties, JSON_UNESCAPED_SLASHES),
            'created_at' => $timestamp->toDateTimeString(),
            'updated_at' => $timestamp->toDateTimeString(),
        ]);
    }
}
