<?php

namespace App\Models;

use Database\Factories\RegistrationCreditEvaluationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class RegistrationCreditEvaluation extends Model
{
    /** @use HasFactory<RegistrationCreditEvaluationFactory> */
    use HasFactory;

    protected $fillable = ['enrollment_id', 'application_submission_version_id', 'curriculum_version_id', 'same_curriculum',
        'credited_curriculum_entry_ids', 'authority_reference', 'approved_on', 'recorded_by', 'recorded_at'];

    protected function casts(): array
    {
        return ['credited_curriculum_entry_ids' => 'array', 'same_curriculum' => 'boolean', 'approved_on' => 'date', 'recorded_at' => 'datetime'];
    }

    /** @return HasMany<RegistrationCreditLine, $this> */
    public function creditLines(): HasMany
    {
        return $this->hasMany(RegistrationCreditLine::class);
    }

    /** @return BelongsTo<Enrollment, $this> */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public static function latestCurrentFor(Enrollment $enrollment, int $curriculumVersionId): ?self
    {
        $latest = self::query()->where('enrollment_id', $enrollment->id)->latest('id')->first();

        return $latest !== null
            && (int) $latest->application_submission_version_id === (int) $enrollment->admissionApplication?->current_submission_version_id
            && (int) $latest->curriculum_version_id === $curriculumVersionId ? $latest : null;
    }

    /**
     * The crediting that counts for a Student: the latest evaluation recorded for one of the Student's
     * officially enrolled cases on the Student's current curriculum. At official enrollment this is the
     * evaluation the confirmed proposal used; a later Registrar correction replaces it.
     */
    public static function effectiveForStudent(StudentProfile $profile): ?self
    {
        return self::query()->where('curriculum_version_id', $profile->curriculum_version_id)
            ->whereIn('enrollment_id', Enrollment::query()->select('id')->where('student_profile_id', $profile->id)
                ->where('canonical_outcome', Enrollment::OutcomeOfficiallyEnrolled))
            ->latest('id')->first();
    }

    /** @return list<int> */
    public static function creditedEntryIdsForStudent(StudentProfile $profile): array
    {
        return array_values(array_map(fn ($id): int => (int) $id, self::effectiveForStudent($profile)?->credited_curriculum_entry_ids ?? []));
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Academic evaluation records are immutable. Record a replacement.'));
        static::deleting(fn (): never => throw new LogicException('Academic evaluation history cannot be deleted.'));
    }
}
