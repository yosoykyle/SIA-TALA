<?php

namespace App\Actions\Completion;

use App\Actions\Academics\OfficialCourseResultProjection;
use App\Models\CourseSpecification;
use App\Models\DegreeConferral;
use App\Models\OfficialOutputPaymentClearance;
use App\Models\ProgramShiftCreditEntry;
use App\Models\StudentLifecycleChange;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TranscriptRequest;
use Illuminate\Validation\ValidationException;

class TranscriptPreview
{
    public function __construct(private readonly OfficialCourseResultProjection $results) {}

    /** @return array{request: TranscriptRequest, student: array<string, mixed>, institution: array<string, mixed>, academic_years: array<string, list<array<string, mixed>>>, conferral: array<string, mixed>, certification: array<string, mixed>, source_fingerprint: string, status: string} */
    public function forRequest(TranscriptRequest $request, string $status = 'Preview'): array
    {
        $request->loadMissing(['studentProfile.program', 'studentProfile.curriculumVersion', 'studentProfile.applicantIntake', 'conferral']);
        $student = $request->studentProfile;
        $conferral = $request->conferral;
        if (! $student instanceof StudentProfile || ! $conferral instanceof DegreeConferral) {
            throw ValidationException::withMessages(['transcript' => 'TOR preview requires a valid Student and conferral source.']);
        }

        $missing = collect([
            'Student legal identity' => filled($student->first_name) && filled($student->last_name),
            'Student number' => filled($student->student_number),
            'Program' => filled($student->program->name),
            'Curriculum Version' => filled($student->curriculumVersion->version_code),
            'External request' => filled($request->external_request_reference),
            'Accounting clearance' => in_array($request->clearanceState(), [OfficialOutputPaymentClearance::StateCleared, OfficialOutputPaymentClearance::StateNotRequired], true),
            'TALA Standard TOR version' => $request->template_version === TranscriptRequest::TemplateServitechV1,
            'Registrar signatory' => filled($request->signatory_name) && filled($request->signatory_title),
            'Institution identity' => filled(config('institution.name')),
            'Institution address/contact' => filled(config('institution.address')) && (filled(config('institution.public.support_phone')) || filled(config('institution.public.support_facebook_url'))),
            'Seal input' => ($request->seal_input_type === TranscriptRequest::SealImage && filled($request->seal_path) && filled($request->seal_checksum))
                || ($request->seal_input_type === TranscriptRequest::SealPlacementInstruction && filled($request->seal_placement_instruction)),
        ])->filter(fn (bool $ready): bool => ! $ready)->keys()->values();

        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages([
                'transcript' => 'TOR preview is unavailable. Registrar must resolve: '.$missing->implode(', ').'.',
            ]);
        }

        $attemptNumbers = [];
        $releasedRows = $this->results->forStudent($student)
            ->filter(fn (array $result): bool => $result['event'] !== null)
            ->map(function (array $result) use (&$attemptNumbers): array {
                $specification = $result['course_specification'];
                $term = $result['term'];
                $event = $result['event'];
                if (! $specification instanceof CourseSpecification || ! $term instanceof Term) {
                    throw ValidationException::withMessages(['transcript' => 'A released academic attempt has an incomplete authoritative source.']);
                }
                $course = $specification->course;
                $attemptNumbers[$specification->id] = ($attemptNumbers[$specification->id] ?? 0) + 1;
                $units = (float) $result['units'];
                $earnedUnits = is_numeric($result['result']) && (float) $result['result'] <= 4.00 ? $units : 0.0;

                return [
                    'academic_year' => $term->academicYear->label,
                    'term_id' => $term->id,
                    'term' => $term->label,
                    'term_starts_on' => $term->starts_on->toDateString(),
                    'course_code' => $course->code,
                    'course_title' => $specification->title,
                    'units' => $result['units'],
                    'result' => $result['result'] ?? 'Not released',
                    'remarks' => $result['course_enrollment']->status,
                    'attempt_or_credit' => 'Attempt '.$attemptNumbers[$specification->id],
                    'earned_units' => $earnedUnits,
                    'attempt_event_id' => $event->id,
                ];
            });
        $approvedCreditRows = ProgramShiftCreditEntry::query()
            ->where('treatment', ProgramShiftCreditEntry::TreatmentAccepted)
            ->where('state', ProgramShiftCreditEntry::StateRecorded)
            ->whereHas('lifecycleChange', fn ($query) => $query
                ->where('student_profile_id', $student->id)
                ->where('state', StudentLifecycleChange::StateApplied))
            ->with([
                'lifecycleChange.term.academicYear',
                'curriculumEntry.courseSpecification.course',
                'sourceCourse',
                'sourceGradeOutcomeEvent',
            ])
            ->get()
            ->map(function (ProgramShiftCreditEntry $credit): array {
                $term = $credit->lifecycleChange->term;
                $specification = $credit->curriculumEntry->courseSpecification;
                $units = (float) $specification->credit_units;
                $sourceResult = $credit->source_grade_outcome_event_id !== null
                    ? $credit->sourceGradeOutcomeEvent->result_code
                    : null;
                $sourceCourseCode = $credit->source_course_id !== null
                    ? $credit->sourceCourse->code
                    : null;

                return [
                    'academic_year' => $term->academicYear->label,
                    'term_id' => $term->id,
                    'term' => $term->label,
                    'term_starts_on' => $term->starts_on->toDateString(),
                    'course_code' => $specification->course->code,
                    'course_title' => $specification->title,
                    'units' => number_format($units, 2, '.', ''),
                    'result' => $credit->numeric_grade ?? $sourceResult ?? 'Credited',
                    'remarks' => filled($sourceCourseCode)
                        ? 'Approved credit/equivalency from '.$sourceCourseCode
                        : 'Approved credit/equivalency',
                    'attempt_or_credit' => 'Approved credit/equivalency',
                    'earned_units' => $units,
                    'attempt_event_id' => $credit->source_grade_outcome_event_id,
                ];
            });
        $runningEarnedUnits = 0.0;
        $rowsWithSummaries = [];
        $termGroups = $releasedRows
            ->concat($approvedCreditRows)
            ->sortBy([
                ['term_starts_on', 'asc'],
                ['term_id', 'asc'],
                ['course_code', 'asc'],
                ['attempt_event_id', 'asc'],
            ])
            ->groupBy(fn (array $row): string => $row['academic_year'].'|'.$row['term_id']);
        foreach ($termGroups as $termRows) {
            $termRows = $termRows->values();
            $termUnits = $termRows->sum(fn (array $row): float => (float) $row['units']);
            $runningEarnedUnits += $termRows->sum(fn (array $row): float => (float) $row['earned_units']);
            foreach ($termRows as $index => $row) {
                $rowsWithSummaries[] = [
                    ...collect($row)->except(['term_id', 'term_starts_on', 'earned_units'])->all(),
                    'term_summary' => $index === $termRows->count() - 1
                        ? [
                            'term_units' => number_format($termUnits, 2, '.', ''),
                            'cumulative_earned_units' => number_format($runningEarnedUnits, 2, '.', ''),
                        ]
                        : null,
                ];
            }
        }
        $academicYears = collect($rowsWithSummaries)
            ->groupBy('academic_year')
            ->map(fn ($rows): array => $rows->values()->all())
            ->all();

        $content = [
            'request' => $request,
            'student' => [
                'legal_name' => collect([$student->first_name, $student->middle_name, $student->last_name])->filter()->implode(' '),
                'student_number' => $student->student_number,
                'program' => $student->program->name,
                'curriculum_version' => $student->curriculumVersion->version_code,
                'admission_basis' => $student->applicant_intake_id ? 'Servitech admission record' : 'Prior institutional record',
                'admission_date' => $student->applicantIntake?->created_at?->toDateString(),
                'prior_school_or_credit' => $student->prior_identifier,
            ],
            'institution' => [
                'name' => config('institution.name'),
                'address' => config('institution.address'),
                'phone' => config('institution.public.support_phone'),
                'facebook_url' => config('institution.public.support_facebook_url'),
            ],
            'academic_years' => $academicYears,
            'conferral' => [
                'degree' => $conferral->degree_name,
                'conferred_on' => $conferral->conferred_on->toDateString(),
                'honor' => $conferral->honor_text,
            ],
            'certification' => [
                'statement' => 'This transcript reproduces the released academic record held by Servitech Institute Asia.',
                'signatory_name' => $request->signatory_name,
                'signatory_title' => $request->signatory_title,
                'seal_input_type' => $request->seal_input_type,
                'seal_placement_instruction' => $request->seal_placement_instruction,
            ],
            'status' => $status,
        ];
        $fingerprintSource = [
            'request_source_fingerprint' => $request->source_fingerprint,
            'clearance_id' => $request->currentClearance()?->id,
            'academic_event_ids' => collect($academicYears)->flatten(1)->pluck('attempt_event_id')->filter()->values()->all(),
            'content' => collect($content)->except('request')->all(),
        ];
        $content['source_fingerprint'] = hash('sha256', json_encode($fingerprintSource, JSON_THROW_ON_ERROR));

        return $content;
    }
}
