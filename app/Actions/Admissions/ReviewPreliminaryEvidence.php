<?php

namespace App\Actions\Admissions;

use App\Models\DocumentEvidence;
use App\Models\PreliminaryEvidenceReview;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReviewPreliminaryEvidence
{
    public function execute(
        DocumentEvidence $evidence,
        User $actor,
        string $result,
        ?string $reason,
        ?int $expectedCurrentReviewId = null,
    ): PreliminaryEvidenceReview {
        $this->authorize($actor);
        $validated = Validator::make([
            'result' => $result,
            'reason' => filled($reason) ? trim((string) $reason) : null,
        ], [
            'result' => ['required', Rule::in([
                PreliminaryEvidenceReview::ResultUnderReview,
                PreliminaryEvidenceReview::ResultAccepted,
                PreliminaryEvidenceReview::ResultActionNeeded,
            ])],
            'reason' => [
                Rule::requiredIf($result === PreliminaryEvidenceReview::ResultActionNeeded),
                'nullable',
                'string',
                'max:2000',
            ],
        ])->validate();

        return DB::transaction(function () use (
            $evidence,
            $actor,
            $validated,
            $expectedCurrentReviewId,
        ): PreliminaryEvidenceReview {
            $lockedEvidence = DocumentEvidence::query()->lockForUpdate()->findOrFail($evidence->id);

            if ($lockedEvidence->admission_application_id === null
                || $lockedEvidence->admission_requirement_id === null) {
                throw ValidationException::withMessages([
                    'evidence' => 'Only canonical admission evidence can receive a preliminary review.',
                ]);
            }

            $current = $lockedEvidence->preliminaryReviews()
                ->whereDoesntHave('successor')
                ->lockForUpdate()
                ->first();

            if (($current?->id) !== $expectedCurrentReviewId) {
                throw ValidationException::withMessages([
                    'result' => 'The current preliminary review changed. Refresh before recording a successor.',
                ]);
            }

            return $lockedEvidence->preliminaryReviews()->create([
                'result' => $validated['result'],
                'reason' => $validated['reason'],
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(config('app.timezone')),
                'supersedes_preliminary_evidence_review_id' => $current?->id,
            ]);
        }, attempts: 3);
    }

    private function authorize(User $actor): void
    {
        if (! $actor->hasRole(User::StaffRoleRegistrar)
            || ! $actor->canAuthenticate()
            || ! $actor->can('approve-documents')) {
            throw new AuthorizationException('Only an authorized Registrar may review preliminary evidence.');
        }
    }
}
