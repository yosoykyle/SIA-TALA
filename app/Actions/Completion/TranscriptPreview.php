<?php

namespace App\Actions\Completion;

use App\Actions\Academics\OfficialCourseResultProjection;
use App\Actions\StudentLifecycle\HoldEvaluationService;
use App\Filament\Resources\StudentProfiles\StudentProfileResource;
use App\Models\AcademicYear;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\DegreeConferral;
use App\Models\Enrollment;
use App\Models\Hold;
use App\Models\PriorAcademicCredit;
use App\Models\ProgramShiftCreditEntry;
use App\Models\RegistrationCreditEvaluation;
use App\Models\RegistrationCreditLine;
use App\Models\StudentLifecycleChange;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TranscriptRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class TranscriptPreview
{
    public function __construct(private readonly OfficialCourseResultProjection $results) {}

    /** @return array{request: TranscriptRequest, student: array<string, mixed>, institution: array<string, mixed>, academic_years: array<string, list<array<string, mixed>>>, conferral: array<string, mixed>, certification: array<string, mixed>, source_fingerprint: string, status: string} */
    public function forRequest(TranscriptRequest $request, string $status = 'Preview'): array
    {
        $request->loadMissing(['studentProfile.program', 'studentProfile.curriculumVersion', 'studentProfile.entryAcademicYear', 'studentProfile.applicantIntake', 'conferral']);
        $student = $request->studentProfile;
        $signatory = app(TranscriptSignatory::class)->forRequest($request);
        if (! $student instanceof StudentProfile) {
            throw ValidationException::withMessages(['transcript' => 'Registrar must verify the Student record before preparing this TOR.']);
        }
        $conferral = DegreeConferral::query()->where('student_profile_id', $student->id)->whereNotNull('active_scope_key')->first();
        $restrictions = app(OfficialOutputRestrictionService::class)->activeFor($student);
        if ($restrictions->isNotEmpty()) {
            throw ValidationException::withMessages(['transcript' => 'Accounting must resolve the official TOR restriction: '.$restrictions->pluck('reason')->implode('; ')]);
        }
        $recordReleaseHolds = app(HoldEvaluationService::class)->activeBlockingHolds($student, [Hold::BlockingRecordRelease]);
        if ($recordReleaseHolds->isNotEmpty()) {
            throw ValidationException::withMessages(['transcript' => 'Accounting must resolve the official TOR hold: '.$recordReleaseHolds->pluck('reason')->implode('; ')]);
        }

        $missing = collect([
            'Student legal identity' => filled($student->first_name) && filled($student->last_name),
            'Student number' => filled($student->student_number),
            'Program' => filled($student->program?->name),
            'Curriculum Version' => filled($student->curriculumVersion?->name) || filled($student->curriculumVersion?->version_code),
            'External request' => filled($request->external_request_reference),
            'TALA Standard TOR version' => in_array($request->template_version, [TranscriptRequest::TemplateServitechV1, TranscriptRequest::TemplateServitechV2], true),
            'name and title printed on the TOR' => filled($signatory['name']) && filled($signatory['title']),
            'System administrator · school identity' => filled(config('institution.name')),
            'System administrator · school address/contact' => filled(config('institution.address')) && (filled(config('institution.public.support_phone')) || filled(config('institution.public.support_facebook_url'))),
            'Seal input' => $request->template_version === TranscriptRequest::TemplateServitechV2 || ($request->seal_input_type === TranscriptRequest::SealImage && filled($request->seal_path) && filled($request->seal_checksum))
                || ($request->seal_input_type === TranscriptRequest::SealPlacementInstruction && filled($request->seal_placement_instruction)),
        ])->filter(fn (bool $ready): bool => ! $ready)->keys()->values();

        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages([
                'transcript' => 'TOR preview is unavailable. Resolve these sources: '.$missing->implode(', ').'.',
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
                    'period_sort_key' => $this->termSortKey($term),
                    'course_id' => (int) $course->id,
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
        $countedReleasedEventIds = $releasedRows
            ->filter(fn (array $row): bool => (float) $row['earned_units'] > 0.0 && $row['attempt_event_id'] !== null)
            ->pluck('attempt_event_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();
        $priorCreditRows = PriorAcademicCredit::query()
            ->active()
            ->where('student_profile_id', $student->id)
            ->with(['courseSpecification.course', 'term.academicYear'])
            ->orderBy('id')
            ->get()
            ->map(function (PriorAcademicCredit $credit): array {
                $specification = $credit->courseSpecification;
                $course = $specification?->course;

                if (! $specification instanceof CourseSpecification || $course === null) {
                    throw ValidationException::withMessages(['transcript' => 'A prior academic record has an incomplete course specification.']);
                }

                $ayLabel = $credit->displayAcademicYearLabel();
                $termLabel = $credit->displayTermLabel();
                $isExternalTransfer = $credit->isExternalTransfer();
                $remarks = $isExternalTransfer
                    ? (filled($credit->source_school) ? 'External transfer credit ('.$credit->source_school.')' : 'External transfer credit')
                    : (filled($credit->source_school) ? 'Prior record ('.$credit->source_school.')' : 'Prior institutional record');

                return [
                    'academic_year' => $ayLabel,
                    'term_id' => $credit->term_id ?? ('prior:'.$ayLabel.'|'.$termLabel),
                    'term' => $termLabel,
                    'period_sort_key' => $credit->term instanceof Term
                        ? $this->termSortKey($credit->term)
                        : $credit->periodSortKey(),
                    'course_id' => (int) $course->id,
                    'course_code' => $course->code,
                    'course_title' => $specification->title,
                    'units' => number_format((float) $credit->units, 2, '.', ''),
                    'result' => $credit->effectiveResultCode(),
                    'remarks' => $remarks,
                    'attempt_or_credit' => $isExternalTransfer ? 'Transfer credit' : 'Prior record',
                    'earned_units' => $credit->isPassing() ? (float) $credit->units : 0.0,
                    'attempt_event_id' => null,
                    'prior_credit_id' => (int) $credit->id,
                    'is_external_transfer' => $isExternalTransfer,
                ];
            });
        $countedPriorCreditIds = $priorCreditRows
            ->filter(fn (array $row): bool => (float) $row['earned_units'] > 0.0 && $row['prior_credit_id'] !== null)
            ->pluck('prior_credit_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();
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
                'sourcePriorAcademicCredit',
            ])
            ->get()
            ->map(function (ProgramShiftCreditEntry $credit) use ($countedReleasedEventIds, $countedPriorCreditIds): array {
                $term = $credit->lifecycleChange->term;
                $specification = $credit->curriculumEntry->courseSpecification;
                $units = (float) $specification->credit_units;
                $sourceResult = $credit->source_grade_outcome_event_id !== null
                    ? $credit->sourceGradeOutcomeEvent->result_code
                    : ($credit->source_prior_academic_credit_id !== null
                        ? $credit->sourcePriorAcademicCredit?->effectiveResultCode()
                        : null);
                $sourceCourseCode = $credit->source_course_id !== null
                    ? $credit->sourceCourse->code
                    : null;
                $alreadyCounted = ($credit->source_grade_outcome_event_id !== null
                        && $countedReleasedEventIds->contains((int) $credit->source_grade_outcome_event_id))
                    || ($credit->source_prior_academic_credit_id !== null
                        && $countedPriorCreditIds->contains((int) $credit->source_prior_academic_credit_id));

                return [
                    'academic_year' => $term->academicYear->label,
                    'term_id' => $term->id,
                    'term' => $term->label,
                    'period_sort_key' => $this->termSortKey($term),
                    'course_id' => (int) $specification->course_id,
                    'course_code' => $specification->course->code,
                    'course_title' => $specification->title,
                    'units' => number_format($units, 2, '.', ''),
                    'result' => $credit->numeric_grade ?? $sourceResult ?? 'Credited',
                    'remarks' => filled($sourceCourseCode)
                        ? 'Approved credit/equivalency from '.$sourceCourseCode
                        : 'Approved credit/equivalency',
                    'attempt_or_credit' => 'Approved credit/equivalency',
                    'earned_units' => $alreadyCounted ? 0.0 : $units,
                    'attempt_event_id' => $credit->source_grade_outcome_event_id,
                ];
            });
        $transferCreditRows = $this->transferCreditRows($student);

        $runningEarnedUnits = 0.0;
        $earnedCourseIds = [];
        $rowsWithSummaries = [];
        $termGroups = $releasedRows
            ->concat($approvedCreditRows)
            ->concat($transferCreditRows)
            ->concat($priorCreditRows)
            ->sortBy([
                ['period_sort_key', 'asc'],
                ['term_id', 'asc'],
                ['course_code', 'asc'],
                ['attempt_event_id', 'asc'],
            ])
            ->groupBy(fn (array $row): string => $row['academic_year'].'|'.$row['term_id']);
        foreach ($termGroups as $termRows) {
            $termRows = $termRows->values();
            $termUnits = $termRows->sum(fn (array $row): float => (float) $row['units']);
            foreach ($termRows as $row) {
                $earned = (float) $row['earned_units'];
                $courseId = isset($row['course_id']) ? (int) $row['course_id'] : 0;
                if ($earned > 0.0) {
                    if ($courseId > 0 && isset($earnedCourseIds[$courseId])) {
                        $earned = 0.0;
                    } else {
                        if ($courseId > 0) {
                            $earnedCourseIds[$courseId] = true;
                        }
                        $runningEarnedUnits += $earned;
                    }
                }
            }
            foreach ($termRows as $index => $row) {
                $rowsWithSummaries[] = [
                    ...collect($row)->except(['term_id', 'period_sort_key', 'earned_units', 'course_id'])->all(),
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

        if ($academicYears === []) {
            throw ValidationException::withMessages(['transcript' => 'Registrar must verify released official academic history before preparing this TOR.']);
        }

        $content = [
            'request' => $request,
            'student' => [
                'legal_name' => collect([$student->first_name, $student->middle_name, $student->last_name])->filter()->implode(' '),
                'student_number' => $student->student_number,
                'program' => $student->program->name,
                'curriculum_version' => StudentProfileResource::curriculumAndBatchLabel($student)
                    ?? $student->curriculumVersion?->name
                    ?? $student->curriculumVersion?->version_code,
                'admission_basis' => $student->applicant_intake_id ? 'Servitech admission record' : 'Prior institutional record',
                'admission_date' => $request->template_version === TranscriptRequest::TemplateServitechV2 ? null : $student->applicantIntake?->created_at?->toDateString(),
                'prior_school_or_credit' => $request->template_version === TranscriptRequest::TemplateServitechV2 ? null : $student->prior_identifier,
            ],
            'institution' => [
                'name' => config('institution.name'),
                'address' => config('institution.address'),
                'phone' => config('institution.public.support_phone'),
                'facebook_url' => config('institution.public.support_facebook_url'),
            ],
            'academic_years' => $academicYears,
            'conferral' => $conferral instanceof DegreeConferral ? [
                'id' => $conferral->id,
                'degree' => $conferral->degree_name,
                'conferred_on' => $conferral->conferred_on?->toDateString(),
                'honor' => $conferral->honor_text,
            ] : [],
            'certification' => [
                'statement' => 'This transcript reproduces the released academic record held by Servitech Institute Asia.',
                'signatory_name' => $signatory['name'],
                'signatory_title' => $signatory['title'],
                'signatory_source_version' => $signatory['source_version'],
                'seal_input_type' => $request->seal_input_type,
                'seal_placement_instruction' => $request->seal_placement_instruction,
            ],
            'status' => $status,
        ];
        $fingerprintSource = [
            'request_source_fingerprint' => $request->source_fingerprint,
            'output_restriction_source' => app(OfficialOutputRestrictionService::class)->fingerprintFor($student),
            'record_release_hold_ids' => $recordReleaseHolds->pluck('id')->values()->all(),
            'academic_event_ids' => collect($academicYears)->flatten(1)->pluck('attempt_event_id')->filter()->values()->all(),
            'prior_credit_ids' => collect($academicYears)->flatten(1)->pluck('prior_credit_id')->filter()->values()->all(),
            'content' => collect($content)->except(['request', 'status'])->all(),
        ];
        $content['source_fingerprint'] = hash('sha256', json_encode($fingerprintSource, JSON_THROW_ON_ERROR));

        return $content;
    }

    private function termSortKey(Term $term): string
    {
        $startYear = $term->academicYear instanceof AcademicYear
            ? (CurriculumVersion::extractAcademicYearStart($term->academicYear) ?? (int) ($term->starts_on?->year ?? 9999))
            : (int) ($term->starts_on?->year ?? 9999);
        $termOrder = match ($term->type) {
            Term::TypeFirstSemester => 1,
            Term::TypeSecondSemester => 2,
            Term::TypeSpecialTerm => 3,
            default => 4,
        };

        return sprintf(
            '%04d|%d|%s|%010d',
            $startYear,
            $termOrder,
            $term->starts_on?->toDateString() ?? '9999-12-31',
            (int) $term->id,
        );
    }

    /**
     * One TOR row per credited subject from the Student's effective transfer crediting, placed in the
     * term of the enrollment that recorded it. The TOR grade is shown as recorded; averages never read it.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function transferCreditRows(StudentProfile $student): Collection
    {
        $officialEnrollmentIds = Enrollment::query()
            ->where('student_profile_id', $student->id)
            ->where(fn ($query) => $query
                ->where('canonical_outcome', Enrollment::OutcomeOfficiallyEnrolled)
                ->orWhereNotNull('officially_enrolled_at'))
            ->pluck('id');

        if ($officialEnrollmentIds->isEmpty()) {
            return collect();
        }

        $evaluations = RegistrationCreditEvaluation::query()
            ->whereIn('enrollment_id', $officialEnrollmentIds)
            ->with(['enrollment.term.academicYear', 'creditLines'])
            ->latest('id')
            ->get()
            ->unique('curriculum_version_id')
            ->values();

        return $evaluations->flatMap(function (RegistrationCreditEvaluation $evaluation): Collection {
            $entryIds = $evaluation->credited_curriculum_entry_ids ?? [];
            if ($entryIds === [] || $evaluation->enrollment?->term === null) {
                return collect();
            }

            $term = $evaluation->enrollment->term;
            $lines = $evaluation->creditLines->keyBy('curriculum_entry_id');

            return CurriculumEntry::query()->with('courseSpecification.course')
                ->whereIn('id', $entryIds)->get()
                ->map(function (CurriculumEntry $entry) use ($term, $lines): array {
                    $specification = $entry->courseSpecification;
                    $units = (float) $specification->credit_units;
                    $line = $lines->get($entry->id);

                    return [
                        'academic_year' => $term->academicYear->label,
                        'term_id' => $term->id,
                        'term' => $term->label,
                        'period_sort_key' => $this->termSortKey($term),
                        'course_id' => (int) $specification->course_id,
                        'course_code' => $specification->course->code,
                        'course_title' => $specification->title,
                        'units' => number_format($units, 2, '.', ''),
                        'result' => $line?->tor_grade ?? 'Credited',
                        'remarks' => $line instanceof RegistrationCreditLine ? $line->transcriptRemark() : 'Transfer credit',
                        'attempt_or_credit' => 'Transfer credit',
                        'earned_units' => $units,
                        'attempt_event_id' => null,
                    ];
                });
        })->values();
    }
}
