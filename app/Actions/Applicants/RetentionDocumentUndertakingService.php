<?php

namespace App\Actions\Applicants;

use App\Models\ApplicantDocumentRequirement;
use App\Models\ApplicantIntake;
use App\Models\DocumentRequirementItem;
use App\Models\DocumentUpload;
use App\Models\Enrollment;
use App\Models\RetentionDocumentUndertaking;
use App\Models\StudentProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class RetentionDocumentUndertakingService
{
    public function openForApprovedIntake(
        ApplicantIntake $intake,
        User $registrar,
        ?CarbonImmutable $issuedAt = null,
    ): void {
        $timestamp = $issuedAt ?? CarbonImmutable::now(config('app.timezone'));

        $requirements = $intake->applicantDocumentRequirements()
            ->where('gate_type', DocumentRequirementItem::GateTypeRetention)
            ->whereNotIn('evidence_state', [
                ApplicantDocumentRequirement::EvidenceStateSatisfied,
            ])
            ->orderBy('id')
            ->get();

        foreach ($requirements as $requirement) {
            $undertaking = RetentionDocumentUndertaking::query()->firstOrCreate(
                [
                    'applicant_document_requirement_id' => $requirement->id,
                ],
                [
                    'applicant_intake_id' => $intake->id,
                    'status' => RetentionDocumentUndertaking::StatusActive,
                    'issued_by' => $registrar->id,
                    'issued_at' => $timestamp,
                    'due_at' => $this->dueAtFor($requirement, $timestamp),
                    'meta' => [
                        'item_key' => $requirement->item_key,
                        'label' => $requirement->label,
                        'deadline_strategy' => $requirement->deadline_strategy ?? 'default_30_days',
                    ],
                ],
            );

            if ($undertaking->wasRecentlyCreated) {
                $this->recordActivity(
                    undertaking: $undertaking,
                    event: 'retention_document_undertaking_created',
                    causer: $registrar,
                    properties: [
                        'applicant_intake_id' => $intake->id,
                        'applicant_document_requirement_id' => $requirement->id,
                        'item_key' => $requirement->item_key,
                        'due_at' => $undertaking->due_at?->toDateTimeString(),
                    ],
                    timestamp: $timestamp,
                );
            }
        }
    }

    public function attachEnrollmentContext(ApplicantIntake $intake, StudentProfile $studentProfile, Enrollment $enrollment): void
    {
        RetentionDocumentUndertaking::query()
            ->where('applicant_intake_id', $intake->id)
            ->whereNull('student_profile_id')
            ->update([
                'student_profile_id' => $studentProfile->id,
                'enrollment_id' => $enrollment->id,
                'updated_at' => CarbonImmutable::now(config('app.timezone'))->toDateTimeString(),
            ]);
    }

    public function resolveForRequirement(
        ApplicantDocumentRequirement $requirement,
        DocumentUpload $documentUpload,
        User $registrar,
        ?CarbonImmutable $resolvedAt = null,
    ): void {
        $timestamp = $resolvedAt ?? CarbonImmutable::now(config('app.timezone'));

        $undertaking = RetentionDocumentUndertaking::query()
            ->where('applicant_document_requirement_id', $requirement->id)
            ->whereIn('status', [
                RetentionDocumentUndertaking::StatusActive,
                RetentionDocumentUndertaking::StatusOverdue,
            ])
            ->first();

        if (! $undertaking instanceof RetentionDocumentUndertaking) {
            return;
        }

        $undertaking->forceFill([
            'status' => RetentionDocumentUndertaking::StatusResolved,
            'resolved_by' => $registrar->id,
            'resolved_at' => $timestamp,
            'resolved_by_document_upload_id' => $documentUpload->id,
        ])->save();

        $this->recordActivity(
            undertaking: $undertaking,
            event: 'retention_document_undertaking_resolved',
            causer: $registrar,
            properties: [
                'applicant_document_requirement_id' => $requirement->id,
                'document_upload_id' => $documentUpload->id,
                'status_after' => RetentionDocumentUndertaking::StatusResolved,
            ],
            timestamp: $timestamp,
        );
    }

    public function processDeadlines(?CarbonImmutable $asOf = null): int
    {
        $timestamp = $asOf ?? CarbonImmutable::now(config('app.timezone'));
        $processed = 0;

        RetentionDocumentUndertaking::query()
            ->where('status', RetentionDocumentUndertaking::StatusActive)
            ->where('due_at', '<', $timestamp)
            ->orderBy('id')
            ->chunkById(100, function ($undertakings) use (&$processed, $timestamp): void {
                foreach ($undertakings as $undertaking) {
                    DB::transaction(function () use ($undertaking, $timestamp, &$processed): void {
                        $locked = RetentionDocumentUndertaking::query()
                            ->lockForUpdate()
                            ->findOrFail($undertaking->id);

                        if ($locked->status !== RetentionDocumentUndertaking::StatusActive || $locked->due_at >= $timestamp) {
                            return;
                        }

                        $locked->forceFill([
                            'status' => RetentionDocumentUndertaking::StatusOverdue,
                            'overdue_marked_at' => $timestamp,
                            'hold_applied_at' => $timestamp,
                            'hold_reason' => 'retention_document_overdue',
                        ])->save();

                        $this->recordActivity(
                            undertaking: $locked,
                            event: 'retention_document_undertaking_overdue',
                            causer: null,
                            properties: [
                                'status_after' => RetentionDocumentUndertaking::StatusOverdue,
                                'hold_reason' => 'retention_document_overdue',
                            ],
                            timestamp: $timestamp,
                        );

                        $processed++;
                    });
                }
            });

        return $processed;
    }

    private function dueAtFor(ApplicantDocumentRequirement $requirement, CarbonImmutable $issuedAt): CarbonImmutable
    {
        $strategy = str((string) ($requirement->deadline_strategy ?? '30_days'))->lower()->squish()->toString();

        $days = match (true) {
            preg_match('/^days:(\d{1,3})$/', $strategy, $matches) === 1 => (int) $matches[1],
            preg_match('/^(\d{1,3})_days$/', $strategy, $matches) === 1 => (int) $matches[1],
            default => 30,
        };

        $days = max(1, min(60, $days));

        return $issuedAt->addDays($days);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function recordActivity(
        RetentionDocumentUndertaking $undertaking,
        string $event,
        ?User $causer,
        array $properties,
        CarbonImmutable $timestamp,
    ): void {
        DB::table('activity_log')->insert([
            'log_name' => 'retention_document_undertaking',
            'description' => 'Retention document undertaking lifecycle transition.',
            'subject_type' => RetentionDocumentUndertaking::class,
            'subject_id' => $undertaking->id,
            'event' => $event,
            'causer_type' => $causer instanceof User ? User::class : null,
            'causer_id' => $causer?->id,
            'properties' => json_encode($properties, JSON_UNESCAPED_SLASHES),
            'created_at' => $timestamp->toDateTimeString(),
            'updated_at' => $timestamp->toDateTimeString(),
        ]);
    }
}
