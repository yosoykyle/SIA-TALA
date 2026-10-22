<?php

namespace App\Models;

use Database\Factories\RegistrationCreditEvaluationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class RegistrationCreditEvaluation extends Model
{
    /** @use HasFactory<RegistrationCreditEvaluationFactory> */
    use HasFactory;

    protected $fillable = ['enrollment_id', 'application_submission_version_id', 'curriculum_version_id',
        'credited_curriculum_entry_ids', 'authority_reference', 'approved_on', 'recorded_by', 'recorded_at'];

    protected function casts(): array
    {
        return ['credited_curriculum_entry_ids' => 'array', 'approved_on' => 'date', 'recorded_at' => 'datetime'];
    }

    public static function latestCurrentFor(Enrollment $enrollment, int $curriculumVersionId): ?self
    {
        $latest = self::query()->where('enrollment_id', $enrollment->id)->latest('id')->first();

        return $latest !== null
            && (int) $latest->application_submission_version_id === (int) $enrollment->admissionApplication?->current_submission_version_id
            && (int) $latest->curriculum_version_id === $curriculumVersionId ? $latest : null;
    }

    /** @return list<int> */
    public static function creditedEntryIdsForStudent(StudentProfile $profile): array
    {
        return self::query()->where('curriculum_version_id', $profile->curriculum_version_id)
            ->whereIn('id', Enrollment::query()->with('currentProposalVersion:id,source_snapshot')->where('student_profile_id', $profile->id)
                ->where('canonical_outcome', Enrollment::OutcomeOfficiallyEnrolled)
                ->whereNotNull('current_proposal_version_id')
                ->get()->map(fn (Enrollment $case): mixed => data_get($case->currentProposalVersion?->source_snapshot, 'credit_evaluation_id'))->filter())
            ->get()->flatMap(fn (self $evaluation): array => $evaluation->credited_curriculum_entry_ids)->unique()->map(fn ($id): int => (int) $id)->values()->all();
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Academic evaluation records are immutable. Record a replacement.'));
        static::deleting(fn (): never => throw new LogicException('Academic evaluation history cannot be deleted.'));
    }
}
