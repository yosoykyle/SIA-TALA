<?php

namespace App\Actions\Enrollment;

use App\Models\Enrollment;
use App\Models\EnrollmentException;
use App\Models\RegistrationProposalVersion;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RecordGraduatingOverloadAuthority
{
    public function __construct(private readonly StudentUnitLoadService $unitLoad) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(
        RegistrationProposalVersion $proposal,
        User $actor,
        array $data,
    ): EnrollmentException {
        if (! $actor->canAuthenticate() || ! $actor->hasRole(User::StaffRoleRegistrar)) {
            throw new AuthorizationException('Only authorized Registrar staff may record an external graduating-overload authority.');
        }

        $validated = Validator::make($data, [
            'authority_reference' => ['required', 'string', 'max:255'],
            'authority_date' => ['required', 'date', 'before_or_equal:today'],
            'evidence_reference' => ['required', 'string', 'max:255'],
            'reason' => ['required', 'string', 'max:1000'],
        ])->validate();

        return DB::transaction(function () use ($proposal, $actor, $validated): EnrollmentException {
            $lockedProposal = RegistrationProposalVersion::query()
                ->with('items')
                ->whereKey($proposal->id)
                ->lockForUpdate()
                ->firstOrFail();
            $enrollment = Enrollment::query()
                ->with(['studentProfile', 'term'])
                ->whereKey($lockedProposal->enrollment_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedProposal->state !== RegistrationProposalVersion::StateDraft
                || (int) $enrollment->current_proposal_version_id !== (int) $lockedProposal->id
                || $enrollment->studentProfile?->academic_standing !== StudentProfile::StandingGraduationCandidate) {
                throw ValidationException::withMessages([
                    'authority' => 'A current Draft proposal for a Graduation Candidate is required.',
                ]);
            }

            $snapshot = $this->unitLoad->currentProposalLoad($enrollment, $lockedProposal, lockForUpdate: true);
            if (! $snapshot['requires_graduating_overload']) {
                throw ValidationException::withMessages([
                    'authority' => 'This proposal does not exceed its exact curriculum-term total.',
                ]);
            }

            $payload = [
                'proposal_id' => (int) $lockedProposal->id,
                'proposal_version' => (int) $lockedProposal->version,
                'proposal_content_hash' => $lockedProposal->content_hash,
                'selection_hash' => $snapshot['selection_hash'],
                'curriculum_version_id' => $snapshot['curriculum_version_id'],
                'year_level' => $snapshot['year_level'],
                'term_label' => $snapshot['term_label'],
                'term_type' => $snapshot['term_type'],
                'term_offering_ids' => $snapshot['term_offering_ids'],
                'section_ids' => $snapshot['section_ids'],
                'normal_total' => $snapshot['normal_total'],
                'requested_total' => $snapshot['requested_total'],
                'authority_reference' => trim((string) $validated['authority_reference']),
                'authority_date' => (string) $validated['authority_date'],
                'recorded_by_role' => User::StaffRoleRegistrar,
                'recorded_by_user_id' => (int) $actor->id,
            ];
            $payloadHash = hash('sha256', json_encode([
                ...$payload,
                'evidence_reference' => trim((string) $validated['evidence_reference']),
                'reason' => trim((string) $validated['reason']),
            ], JSON_THROW_ON_ERROR));
            $scopeKey = 'graduating_overload:proposal:'.$lockedProposal->id.':'.substr($payloadHash, 0, 24);
            $existing = EnrollmentException::query()
                ->where('enrollment_id', $enrollment->id)
                ->where('exception_type', EnrollmentException::TypeGraduatingOverload)
                ->where('scope_key', $scopeKey)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof EnrollmentException) {
                return $existing;
            }

            EnrollmentException::query()
                ->where('enrollment_id', $enrollment->id)
                ->where('exception_type', EnrollmentException::TypeGraduatingOverload)
                ->where('state', EnrollmentException::StateActive)
                ->where('scope_key', 'like', 'graduating_overload:proposal:'.$lockedProposal->id.':%')
                ->lockForUpdate()
                ->update(['state' => EnrollmentException::StateSuperseded]);

            return EnrollmentException::query()->create([
                'enrollment_id' => $enrollment->id,
                'student_profile_id' => $enrollment->student_profile_id,
                'term_id' => $enrollment->term_id,
                'exception_type' => EnrollmentException::TypeGraduatingOverload,
                'scope_key' => $scopeKey,
                'requested_values' => [
                    'proposal_id' => (int) $lockedProposal->id,
                    'requested_total' => $snapshot['requested_total'],
                    'normal_total' => $snapshot['normal_total'],
                ],
                'approved_values' => $payload,
                'reason' => trim((string) $validated['reason']),
                'evidence_reference' => trim((string) $validated['evidence_reference']),
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'state' => EnrollmentException::StateActive,
            ]);
        }, attempts: 3);
    }
}
