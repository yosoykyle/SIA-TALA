<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RegistrarEnrollmentClearance extends Model
{
    use HasFactory;

    public const ResultCleared = 'Cleared';

    public const ResultActionNeeded = 'ActionNeeded';

    protected $fillable = [
        'admission_application_id', 'application_submission_version_id', 'admission_decision_id',
        'identity_source_hash', 'result', 'safe_instruction', 'reason', 'authority_reference',
        'external_checks_confirmed', 'recorded_by', 'recorded_at', 'supersedes_clearance_id',
    ];

    protected function casts(): array
    {
        return ['recorded_at' => 'datetime', 'external_checks_confirmed' => 'boolean'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(AdmissionApplication::class, 'admission_application_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function successor(): HasOne
    {
        return $this->hasOne(self::class, 'supersedes_clearance_id');
    }

    public static function identitySourceHash(AdmissionApplication $application): string
    {
        return hash('sha256', json_encode($application->only([
            'user_id', 'first_name', 'middle_name', 'last_name', 'extension_name', 'birth_date',
            'citizenship_country_code', 'lrn', 'lrn_availability', 'prior_college_identifier',
            'program_id', 'term_id', 'application_path', 'current_submission_version_id',
        ]), JSON_THROW_ON_ERROR));
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new \LogicException('Registrar enrollment clearances are immutable.'));
        static::deleting(fn (): never => throw new \LogicException('Registrar enrollment clearances cannot be deleted.'));
    }
}
