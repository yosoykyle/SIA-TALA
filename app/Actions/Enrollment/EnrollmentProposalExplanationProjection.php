<?php

namespace App\Actions\Enrollment;

use App\Actions\Academics\AcademicEnrollmentEffect;
use App\Filament\Resources\StudentProfiles\StudentProfileResource;
use App\Filament\Student\Pages\Academics;
use App\Models\AcademicDecision;
use App\Models\AdmissionApplication;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\RegistrationCreditEvaluation;
use App\Models\RegistrationProposalVersion;
use App\Models\StudentProfile;
use Illuminate\Support\Str;

class EnrollmentProposalExplanationProjection
{
    public const AudienceRegistrar = 'registrar';

    public const AudienceStudent = 'student';

    public const AudienceApplicant = 'applicant';

    public function __construct(private readonly AcademicEnrollmentEffect $academicEffect) {}

    /**
     * @return array{
     *     heading: string,
     *     selection_basis: string|null,
     *     basis_label: string,
     *     summary: string,
     *     curriculum_label: string|null,
     *     curriculum_note: string|null,
     *     unit_load_label: string|null,
     *     overload_note: string|null,
     *     transfer_credit_summary: string|null,
     *     transfer_credit_authority: string|null,
     *     credited_course_codes: list<string>,
     *     academic_note: string|null,
     *     academic_source: string|null,
     *     evaluation_url: string|null,
     *     evaluation_label: string|null,
     *     has_student_profile: bool
     * }
     */
    public function forEnrollment(
        Enrollment $enrollment,
        ?RegistrationProposalVersion $proposal = null,
        string $audience = self::AudienceStudent,
    ): array {
        $enrollment->loadMissing([
            'term.academicYear',
            'studentProfile.program',
            'studentProfile.curriculumVersion.program',
            'admissionApplication.program',
        ]);
        $proposal = $proposal ?? $enrollment->currentProposalVersion;
        if ($proposal instanceof RegistrationProposalVersion) {
            $proposal->loadMissing(['curriculumVersion.program', 'items']);
        }

        $profile = $enrollment->studentProfile;
        $hasStudentProfile = $profile instanceof StudentProfile;
        $selectionBasis = $proposal?->selection_basis ?: $enrollment->selection_basis;
        $isAdvised = $selectionBasis === Enrollment::SelectionIndividuallyAdvised;

        $basisLabel = match ($selectionBasis) {
            Enrollment::SelectionStandardCurriculum => 'Standard curriculum',
            Enrollment::SelectionIndividuallyAdvised => 'Individually advised',
            default => filled($selectionBasis) ? Str::headline((string) $selectionBasis) : 'Curriculum basis pending',
        };

        $summary = match (true) {
            $isAdvised && $audience === self::AudienceRegistrar => 'Individually advised by the Registrar using the assigned curriculum, released academic results, and available classes.',
            $isAdvised => 'The Registrar selected these subjects using your curriculum requirements, released academic results or credits, and available classes.',
            $selectionBasis === Enrollment::SelectionStandardCurriculum && $audience === self::AudienceRegistrar => 'Derived from the learner’s assigned program curriculum block for this Term.',
            $selectionBasis === Enrollment::SelectionStandardCurriculum => 'These subjects follow your assigned program curriculum for this Term.',
            default => 'The Registrar confirms your subjects from the assigned program curriculum and available classes.',
        };

        $curriculum = $proposal?->curriculumVersion
            ?? $profile?->curriculumVersion
            ?? $this->activeCurriculumForApplication($enrollment->admissionApplication);
        $curriculumLabel = $curriculum instanceof CurriculumVersion
            ? collect([$curriculum->program?->name ?? $enrollment->admissionApplication?->program?->name, $curriculum->version_code])->filter()->implode(' · ')
            : ($profile?->program?->name ?? $enrollment->admissionApplication?->program?->name);
        $curriculumNote = $hasStudentProfile
            && $curriculum instanceof CurriculumVersion
            && $curriculum->state === CurriculumVersion::StateSuperseded
            ? 'Assigned continuing-student curriculum version preserved.'
            : null;

        $unitLoad = is_array($proposal?->source_snapshot) ? data_get($proposal->source_snapshot, 'unit_load') : null;
        $unitLoadLabel = null;
        $overloadNote = null;
        if (is_array($unitLoad)) {
            $positionParts = collect([
                $unitLoad['year_level'] ?? null,
                $unitLoad['term_label'] ?? null,
            ])->filter()->implode(' · ');
            $requested = $unitLoad['requested_total'] ?? null;
            $normal = $unitLoad['normal_total'] ?? null;
            $loadPart = $requested !== null && $normal !== null
                ? "{$requested} of {$normal} normal units"
                : ($requested !== null ? "{$requested} units" : null);
            $unitLoadLabel = collect([$positionParts !== '' ? $positionParts : null, $loadPart])->filter()->implode(' · ') ?: null;
            if ((bool) ($unitLoad['requires_graduating_overload'] ?? false)) {
                $overloadNote = 'Includes recorded graduating overload authority.';
            }
        }

        $transferCredit = $this->resolveTransferCredit($enrollment, $proposal, $curriculum, $audience);

        $academicNote = null;
        $academicSource = null;
        if ($hasStudentProfile) {
            $effect = $this->academicEffect->forStudent($profile, $enrollment->term);
            if ($effect['effect'] !== AcademicDecision::EffectAllowed) {
                $academicNote = $audience === self::AudienceRegistrar
                    ? $effect['reason']
                    : ($effect['student_reason'] ?? $effect['reason']);
                $academicSource = $effect['source'];
            }
        }

        [$evaluationUrl, $evaluationLabel] = $this->resolveEvaluationLink($enrollment, $profile, $audience);

        return [
            'heading' => 'Why these subjects',
            'selection_basis' => $selectionBasis,
            'basis_label' => $basisLabel,
            'summary' => $summary,
            'curriculum_label' => $curriculumLabel !== '' ? $curriculumLabel : null,
            'curriculum_note' => $curriculumNote,
            'unit_load_label' => $unitLoadLabel,
            'overload_note' => $overloadNote,
            'transfer_credit_summary' => $transferCredit['summary'],
            'transfer_credit_authority' => $transferCredit['authority'],
            'credited_course_codes' => $transferCredit['codes'],
            'academic_note' => $academicNote,
            'academic_source' => $academicSource,
            'evaluation_url' => $evaluationUrl,
            'evaluation_label' => $evaluationLabel,
            'has_student_profile' => $hasStudentProfile,
        ];
    }

    /** @return array{summary: string|null, authority: string|null, codes: list<string>} */
    private function resolveTransferCredit(
        Enrollment $enrollment,
        ?RegistrationProposalVersion $proposal,
        ?CurriculumVersion $curriculum,
        string $audience,
    ): array {
        $isTransferee = $enrollment->admissionApplication?->application_path === AdmissionApplication::PathTransferee;
        $evaluationId = is_array($proposal?->source_snapshot) ? data_get($proposal->source_snapshot, 'credit_evaluation_id') : null;
        $evaluation = null;

        if ($evaluationId !== null) {
            $evaluation = RegistrationCreditEvaluation::query()->find($evaluationId);
        } elseif ($isTransferee && $curriculum instanceof CurriculumVersion) {
            $evaluation = RegistrationCreditEvaluation::latestCurrentFor($enrollment, $curriculum->id);
        }

        if ($evaluation instanceof RegistrationCreditEvaluation) {
            $entryIds = collect($evaluation->credited_curriculum_entry_ids ?? [])
                ->map(fn (mixed $id): int => (int) $id)
                ->filter()
                ->unique()
                ->values();
            $codes = $entryIds->isNotEmpty()
                ? CurriculumEntry::query()
                    ->with('courseSpecification.course')
                    ->whereIn('id', $entryIds)
                    ->orderBy('sequence')
                    ->get()
                    ->map(fn (CurriculumEntry $entry): ?string => $entry->courseSpecification?->course?->code)
                    ->filter()
                    ->unique()
                    ->values()
                    ->all()
                : [];
            $count = count($codes) > 0 ? count($codes) : $entryIds->count();
            $summary = $count > 0
                ? 'Approved transfer evaluation credits '.$count.' '.Str::plural('subject', $count)
                    .($codes !== [] ? ' ('.implode(', ', $codes).')' : '')
                    .'; credited subjects are excluded from enrollment.'
                : 'Approved transfer evaluation recorded with no credited subjects.';

            return [
                'summary' => $summary,
                'authority' => $audience === self::AudienceRegistrar ? $evaluation->authority_reference : null,
                'codes' => $codes,
            ];
        }

        if ($isTransferee) {
            return [
                'summary' => 'Transfer credit evaluation is pending Registrar recording before subject selection.',
                'authority' => null,
                'codes' => [],
            ];
        }

        return [
            'summary' => null,
            'authority' => null,
            'codes' => [],
        ];
    }

    /** @return array{0: string|null, 1: string|null} */
    private function resolveEvaluationLink(
        Enrollment $enrollment,
        ?StudentProfile $profile,
        string $audience,
    ): array {
        if (! $profile instanceof StudentProfile) {
            return [null, null];
        }

        if ($audience === self::AudienceRegistrar && StudentProfileResource::canView($profile)) {
            return [
                StudentProfileResource::getUrl('view', [
                    'record' => $profile,
                    'recordTab' => 'academic',
                    'fromEnrollment' => $enrollment->id,
                ]),
                'View academic record & curriculum progress',
            ];
        }

        if ($audience === self::AudienceStudent) {
            return [
                Academics::getUrl(array_filter([
                    'view' => 'plan',
                    'enrollmentTerm' => $enrollment->term_id,
                ]), panel: 'student'),
                'View full study plan',
            ];
        }

        return [null, null];
    }

    private function activeCurriculumForApplication(?AdmissionApplication $application): ?CurriculumVersion
    {
        if (! $application instanceof AdmissionApplication || $application->program_id === null) {
            return null;
        }

        return CurriculumVersion::query()
            ->with('program')
            ->where('program_id', $application->program_id)
            ->where('state', CurriculumVersion::StateActive)
            ->latest('id')
            ->first();
    }
}
