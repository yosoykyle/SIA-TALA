<?php

namespace App\Actions\Applicants;

use App\Models\ApplicantIntake;
use App\Models\ChecklistItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApplicantReviewService
{
    public function __construct(
        private readonly ApplicantStatusNotificationService $statusNotifications,
    ) {}

    public function markForEvaluation(ApplicantIntake $intake, User $actor): ApplicantIntake
    {
        $this->authorizeActor($actor);

        return DB::transaction(function () use ($intake, $actor): ApplicantIntake {
            $locked = ApplicantIntake::query()->lockForUpdate()->findOrFail($intake->id);

            if (! in_array($locked->status, [ApplicantIntake::StatusPending, ApplicantIntake::StatusActionRequired], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Only a pending or corrected application can be marked for evaluation.',
                ]);
            }

            $items = $locked->checklistItems()->with('documentEvidence')->get();

            if ($items->contains(fn (ChecklistItem $item): bool => $item->status === ChecklistItem::StatusRejected)) {
                throw ValidationException::withMessages([
                    'checklist' => 'Resolve every rejected requirement before evaluation.',
                ]);
            }

            $missingDigitalEvidence = $items->contains(
                fn (ChecklistItem $item): bool => $item->evidence_method === ChecklistItem::EvidenceMethodDigitalUpload
                    && $item->documentEvidence->isEmpty(),
            );

            if ($missingDigitalEvidence) {
                throw ValidationException::withMessages([
                    'checklist' => 'Every digital-upload requirement must have evidence before evaluation.',
                ]);
            }

            return $this->transition(
                intake: $locked,
                actor: $actor,
                status: ApplicantIntake::StatusForEvaluation,
                event: 'applicant_intake_marked_for_evaluation',
            );
        }, attempts: 3);
    }

    public function approve(ApplicantIntake $intake, User $actor): ApplicantIntake
    {
        $this->authorizeActor($actor);

        return DB::transaction(function () use ($intake, $actor): ApplicantIntake {
            $locked = ApplicantIntake::query()->lockForUpdate()->findOrFail($intake->id);

            if ($locked->status !== ApplicantIntake::StatusForEvaluation) {
                throw ValidationException::withMessages([
                    'status' => 'Only an application under evaluation can be approved.',
                ]);
            }

            $hasBlocker = $locked->checklistItems()
                ->where('blocking_level', ChecklistItem::BlockingHandover)
                ->get()
                ->contains(fn (ChecklistItem $item): bool => ! $item->isResolved());

            if ($hasBlocker) {
                throw ValidationException::withMessages([
                    'checklist' => 'Accept or otherwise resolve every handover-blocking requirement before approval.',
                ]);
            }

            $approved = $this->transition(
                intake: $locked,
                actor: $actor,
                status: ApplicantIntake::StatusApproved,
                event: 'applicant_intake_approved',
                approved: true,
            );

            $this->statusNotifications->record($approved);

            return $approved;
        }, attempts: 3);
    }

    private function authorizeActor(User $actor): void
    {
        if (! $actor->hasRole(User::StaffRoleRegistrar)
            || ! $actor->can('approve-documents')
            || ! $actor->canAuthenticate()) {
            throw new AuthorizationException('Only Registrar staff with document-approval permission may review applications.');
        }
    }

    private function transition(
        ApplicantIntake $intake,
        User $actor,
        string $status,
        string $event,
        bool $approved = false,
    ): ApplicantIntake {
        $timestamp = CarbonImmutable::now(config('app.timezone'));
        $before = $intake->status;
        $attributes = [
            'status' => $status,
            'reviewed_at' => $timestamp,
            'reviewed_by' => $actor->id,
        ];

        if ($approved) {
            $attributes += [
                'approved_at' => $timestamp,
                'approved_by' => $actor->id,
            ];
        }

        $intake->forceFill($attributes)->save();
        $intake->user()->lockForUpdate()->firstOrFail()->forceFill([
            'status' => match ($status) {
                ApplicantIntake::StatusForEvaluation => User::StatusApplicantForEvaluation,
                ApplicantIntake::StatusApproved => User::StatusApplicantApproved,
                default => User::StatusApplicantPending,
            },
        ])->save();

        DB::table('activity_log')->insert([
            'log_name' => 'applicant_intake',
            'description' => 'Registrar applicant review transition.',
            'subject_type' => ApplicantIntake::class,
            'subject_id' => $intake->id,
            'event' => $event,
            'causer_type' => User::class,
            'causer_id' => $actor->id,
            'properties' => json_encode([
                'status_before' => $before,
                'status_after' => $status,
            ], JSON_UNESCAPED_SLASHES),
            'created_at' => $timestamp->toDateTimeString(),
            'updated_at' => $timestamp->toDateTimeString(),
        ]);

        return $intake->refresh()->load(['checklistItems.documentEvidence', 'user']);
    }
}
