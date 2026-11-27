<?php

namespace App\Actions\Enrollment;

use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\RegistrationCreditEvaluation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Validates and stores one immutable transfer crediting record: the evaluation header plus one
 * credit line per credited SIA curriculum subject. Callers own authorization, locking and freshness.
 */
class StoreRegistrationCreditEvaluation
{
    public const SourceCodeMaxLength = 64;

    public const SourceTitleMaxLength = 255;

    public const TorGradeMaxLength = 16;

    public const RemarksMaxLength = 255;

    /**
     * @param  array{enrollment_id: int, application_submission_version_id: int}  $owner
     * @param  array<int, array{source_course_code?: ?string, source_course_title?: ?string, source_units?: mixed, tor_grade?: ?string, remarks?: ?string}>  $creditLines  keyed by credited curriculum entry ID
     */
    public function store(array $owner, CurriculumVersion $curriculum, array $creditLines, ?bool $sameCurriculum,
        string $authorityReference, CarbonImmutable $approvedOn, User $actor): RegistrationCreditEvaluation
    {
        $entryIds = array_map(fn ($id): int => (int) $id, array_keys($creditLines));
        $entries = CurriculumEntry::query()->with('courseSpecification.course')
            ->where('curriculum_version_id', $curriculum->id)->whereIn('id', $entryIds)->get()->keyBy('id');
        if ($entries->count() !== count($entryIds)) {
            throw ValidationException::withMessages(['credited_curriculum_entry_ids' => 'Choose each credited subject once from the selected curriculum.']);
        }
        if (trim($authorityReference) === '' || mb_strlen($authorityReference) > 255 || $approvedOn->startOfDay()->isAfter(today())) {
            throw ValidationException::withMessages(['authority_reference' => 'Record the approval reference and an approval date that is not in the future.']);
        }

        $lines = [];
        foreach ($creditLines as $entryId => $line) {
            $entry = $entries->get((int) $entryId);
            $subjectUnits = (float) $entry->courseSpecification->credit_units;
            $subject = $entry->courseSpecification->course->code;
            $sourceCode = $this->text($line['source_course_code'] ?? null) ?? ($sameCurriculum === true ? $subject : null);
            $sourceTitle = $this->text($line['source_course_title'] ?? null) ?? ($sameCurriculum === true ? $entry->courseSpecification->title : null);
            $sourceUnits = $this->units($line['source_units'] ?? null, $subject, 'source units') ?? ($sameCurriculum === true ? $subjectUnits : null);
            $torGrade = $this->text($line['tor_grade'] ?? null);
            $remarks = $this->text($line['remarks'] ?? null);

            if ($sourceCode === null || $sourceTitle === null || mb_strlen($sourceCode) > self::SourceCodeMaxLength || mb_strlen($sourceTitle) > self::SourceTitleMaxLength) {
                throw ValidationException::withMessages(['credit_lines' => "Record the previous school's course code and title for {$subject}."]);
            }
            if ($torGrade === null || mb_strlen($torGrade) > self::TorGradeMaxLength) {
                throw ValidationException::withMessages(['credit_lines' => "Record the TOR grade for {$subject} as printed (up to ".self::TorGradeMaxLength.' characters).']);
            }
            if ($remarks !== null && mb_strlen($remarks) > self::RemarksMaxLength) {
                throw ValidationException::withMessages(['credit_lines' => "Keep the remarks for {$subject} within ".self::RemarksMaxLength.' characters.']);
            }

            $lines[] = [
                'curriculum_entry_id' => (int) $entryId,
                'source_course_code' => $sourceCode,
                'source_course_title' => $sourceTitle,
                'source_units' => $sourceUnits,
                'tor_grade' => $torGrade,
                'remarks' => $remarks,
            ];
        }
        sort($entryIds);

        $evaluation = RegistrationCreditEvaluation::query()->create([
            'enrollment_id' => $owner['enrollment_id'],
            'application_submission_version_id' => $owner['application_submission_version_id'],
            'curriculum_version_id' => $curriculum->id,
            'same_curriculum' => $sameCurriculum,
            'credited_curriculum_entry_ids' => $entryIds,
            'authority_reference' => trim($authorityReference),
            'approved_on' => $approvedOn,
            'recorded_by' => $actor->id,
            'recorded_at' => now(),
        ]);
        $evaluation->creditLines()->createMany($lines);

        return $evaluation;
    }

    private function text(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === '' ? null : $value;
    }

    private function units(mixed $value, string $subject, string $label): ?float
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        if (! is_numeric($value) || (float) $value < 0 || (float) $value > 30) {
            throw ValidationException::withMessages(['credit_lines' => "Enter {$label} for {$subject} as a number from 0 to 30."]);
        }

        return round((float) $value, 2);
    }
}
