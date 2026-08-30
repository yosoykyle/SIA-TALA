<?php

namespace App\Models;

use Database\Factories\EnrollmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $registered_at
 * @property Carbon|null $officially_enrolled_at
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $dropped_at
 * @property Carbon|null $withdrawn_at
 */
class Enrollment extends Model
{
    public const SelectionStandardCurriculum = 'StandardCurriculum';

    public const SelectionIndividuallyAdvised = 'IndividuallyAdvised';

    public const OutcomeInProgress = 'InProgress';

    public const OutcomeCancelled = 'Cancelled';

    public const OutcomeCancelledByLearner = 'CancelledByLearner';

    public const OutcomeCancelledByRegistrar = 'CancelledByRegistrar';

    public const OutcomeNotEnrolled = 'NotEnrolled';

    public const OutcomeOfficiallyEnrolled = 'OfficiallyEnrolled';

    /** @return list<string> */
    public static function cancelledOutcomes(): array
    {
        return [
            self::OutcomeCancelledByLearner,
            self::OutcomeCancelledByRegistrar,
            self::OutcomeCancelled,
        ];
    }

    /** @return list<string> */
    public static function reopenableOutcomes(): array
    {
        return [...self::cancelledOutcomes(), self::OutcomeNotEnrolled];
    }

    /** @use HasFactory<EnrollmentFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'student_profile_id',
        'credential_user_id',
        'admission_application_id',
        'case_reference',
        'term_id',
        'status',
        'student_type',
        'selection_basis',
        'canonical_outcome',
        'current_proposal_version_id',
        'current_cor_version_id',
        'started_by',
        'start_method',
        'started_at',
        'registered_at',
        'officially_enrolled_at',
        'finalized_by',
        'cancelled_at',
        'dropped_at',
        'withdrawn_at',
        'status_reason',
        'lock_version',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'registered_at' => 'datetime',
            'officially_enrolled_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'dropped_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function credentialUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'credential_user_id');
    }

    /** @return BelongsTo<AdmissionApplication, $this> */
    public function admissionApplication(): BelongsTo
    {
        return $this->belongsTo(AdmissionApplication::class, 'admission_application_id');
    }

    /** @return BelongsTo<RegistrationProposalVersion, $this> */
    public function currentProposalVersion(): BelongsTo
    {
        return $this->belongsTo(RegistrationProposalVersion::class, 'current_proposal_version_id');
    }

    /** @return BelongsTo<CorVersion, $this> */
    public function currentCorVersion(): BelongsTo
    {
        return $this->belongsTo(CorVersion::class, 'current_cor_version_id');
    }

    /** @return HasMany<RegistrationCaseEvent, $this> */
    public function registrationEvents(): HasMany
    {
        return $this->hasMany(RegistrationCaseEvent::class);
    }

    /** @return HasMany<RegistrationProposalVersion, $this> */
    public function proposalVersions(): HasMany
    {
        return $this->hasMany(RegistrationProposalVersion::class);
    }

    /** @return HasMany<RegistrationIdentityConfirmationVersion, $this> */
    public function identityConfirmationVersions(): HasMany
    {
        return $this->hasMany(RegistrationIdentityConfirmationVersion::class);
    }

    /** @return HasOne<TermAccount, $this> */
    public function termAccount(): HasOne
    {
        return $this->hasOne(TermAccount::class);
    }

    /** @return HasMany<CorVersion, $this> */
    public function corVersions(): HasMany
    {
        return $this->hasMany(CorVersion::class);
    }

    /** @return BelongsTo<StudentProfile, $this> */
    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    /** @return BelongsTo<User, $this> */
    public function finalizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    /** @return BelongsTo<Term, $this> */
    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @return HasMany<Hold, $this> */
    public function holds(): HasMany
    {
        return $this->hasMany(Hold::class);
    }

    /** @return HasMany<CourseEnrollment, $this> */
    public function courseEnrollments(): HasMany
    {
        return $this->hasMany(CourseEnrollment::class);
    }

    /** @return HasMany<EnrollmentSeatReservation, $this> */
    public function seatReservations(): HasMany
    {
        return $this->hasMany(EnrollmentSeatReservation::class);
    }

    /** @return HasMany<EnrollmentGateResult, $this> */
    public function gateResults(): HasMany
    {
        return $this->hasMany(EnrollmentGateResult::class);
    }

    /** @return HasMany<EnrollmentException, $this> */
    public function exceptions(): HasMany
    {
        return $this->hasMany(EnrollmentException::class);
    }

    public function lifecycleChanges(): HasMany
    {
        return $this->hasMany(StudentLifecycleChange::class);
    }

    public function displayLabel(): string
    {
        $this->loadMissing('term');

        return collect([
            "#{$this->id}",
            $this->term->label ?? 'No term',
            $this->status,
        ])
            ->filter(fn (?string $part): bool => filled($part))
            ->implode(' - ');
    }
}
