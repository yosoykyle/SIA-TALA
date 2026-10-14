<?php

namespace App\Actions\Admissions;

use App\Models\AdmissionApplication;
use App\Models\IdentityMatchReview;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ResolveAdmissionIdentity
{
    public function execute(
        IdentityMatchReview $review,
        User $actor,
        string $outcome,
        string $evidenceReference,
        ?string $correctedIdentifier = null,
        ?int $expectedSubmissionVersionId = null,
    ): IdentityMatchReview {
        $this->authorize($actor);
        $expectedSubmissionVersionId ??= $review->application?->current_submission_version_id;
        $validated = Validator::make([
            'outcome' => $outcome,
            'evidence_reference' => trim($evidenceReference),
            'corrected_identifier' => filled($correctedIdentifier) ? trim((string) $correctedIdentifier) : null,
        ], [
            'outcome' => ['required', Rule::in([
                IdentityMatchReview::OutcomeSamePerson,
                IdentityMatchReview::OutcomeDifferentPerson,
                IdentityMatchReview::OutcomeCorrectedIdentifier,
            ])],
            'evidence_reference' => ['required', 'string', 'max:255'],
            'corrected_identifier' => [
                Rule::requiredIf($outcome === IdentityMatchReview::OutcomeCorrectedIdentifier),
                'nullable',
                'string',
                'max:64',
            ],
        ])->validate();

        return DB::transaction(function () use ($review, $actor, $validated, $expectedSubmissionVersionId): IdentityMatchReview {
            $application = AdmissionApplication::query()->lockForUpdate()->findOrFail($review->admission_application_id);
            $application->assertReviewSource($actor, $expectedSubmissionVersionId);
            $locked = IdentityMatchReview::query()->lockForUpdate()->findOrFail($review->id);

            if ($locked->outcome !== IdentityMatchReview::OutcomePending) {
                throw ValidationException::withMessages([
                    'outcome' => 'This identity warning was already resolved. Refresh before taking another action.',
                ]);
            }

            $locked->forceFill([
                'outcome' => $validated['outcome'],
                'evidence_reference' => $validated['evidence_reference'],
                'corrected_identifier' => $validated['corrected_identifier'],
                'resolved_by' => $actor->id,
                'resolved_at' => now(config('app.timezone')),
            ])->save();

            return $locked->refresh();
        }, attempts: 3);
    }

    private function authorize(User $actor): void
    {
        if (! $actor->hasRole(User::StaffRoleRegistrar)
            || ! $actor->canAuthenticate()
            || ! $actor->can('approve-documents')
            || ! $actor->can('evaluate-transferees')) {
            throw new AuthorizationException('Only an authorized Registrar may resolve a private identity warning.');
        }
    }
}
