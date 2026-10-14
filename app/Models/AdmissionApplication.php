<?php

namespace App\Models;

use Database\Factories\AdmissionApplicationFactory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * @property Carbon|null $birth_date
 * @property Carbon|null $privacy_acknowledged_at
 * @property Carbon|null $accuracy_declared_at
 * @property Carbon|null $submitted_at
 * @property string|null $lrn_availability
 * @property string|null $optional_identity_notice_reference
 * @property string|null $optional_identity_consent_purpose
 */
class AdmissionApplication extends Model
{
    /** @use HasFactory<AdmissionApplicationFactory> */
    use HasFactory;

    public const StateDraft = 'Draft';

    public const StateSubmitted = 'Submitted';

    public const StateActionNeeded = 'ActionNeeded';

    public const StateAdmitted = 'Admitted';

    public const StateNotAdmitted = 'NotAdmitted';

    public const StateWithdrawn = 'Withdrawn';

    public const PathFirstYear = AdmissionCycle::PathFirstYear;

    public const PathTransferee = AdmissionCycle::PathTransferee;

    public const CredentialSeniorHighSchool = ApplicantIntake::CredentialBasisSeniorHighSchool;

    public const CredentialAlsAe = 'ALS_AE';

    public const CredentialPept = 'PEPT';

    public const CredentialTransfer = ApplicantIntake::CredentialBasisTransferCredentials;

    protected $table = 'applicant_intakes';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'admission_cycle_id',
        'application_reference',
        'application_state',
        'application_path',
        'term_id',
        'program_id',
        'admission_category',
        'credential_basis',
        'first_name',
        'middle_name',
        'last_name',
        'extension_name',
        'birth_date',
        'citizenship_country_code',
        'email',
        'phone',
        'current_city_municipality',
        'current_province',
        'prior_school_name',
        'prior_school_country_code',
        'prior_school_completion_year',
        'lrn',
        'lrn_availability',
        'gender',
        'civil_status',
        'current_barangay',
        'current_street_address',
        'current_postal_code',
        'prior_school_address',
        'optional_identity_notice_reference',
        'optional_identity_consent_purpose',
        'optional_identity_consented_at',
        'prior_college_identifier',
        'guardian_full_name',
        'guardian_relationship',
        'guardian_mobile',
        'privacy_notice_reference',
        'privacy_acknowledged_at',
        'accuracy_declared_at',
        'current_submission_version_id',
        'status',
        'submitted_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'application_state' => self::StateDraft,
    ];

    /** @return array<string, string> */
    public static function admissionQualificationOptions(string $path): array
    {
        if ($path === self::PathTransferee) {
            return [self::CredentialTransfer => 'Transfer credentials (from another college)'];
        }

        return [
            self::CredentialSeniorHighSchool => 'Senior High School credential',
            self::CredentialAlsAe => 'ALS Accreditation and Equivalency',
            self::CredentialPept => 'Philippine Educational Placement Test',
        ];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'prior_school_completion_year' => 'integer',
            'privacy_acknowledged_at' => 'datetime',
            'optional_identity_consented_at' => 'datetime',
            'accuracy_declared_at' => 'datetime',
            'submitted_at' => 'datetime',
        ];
    }

    public function scopeCanonical(Builder $query): Builder
    {
        return $query
            ->whereNotNull('admission_cycle_id')
            ->whereNotNull('application_state');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<AdmissionCycle, $this> */
    public function admissionCycle(): BelongsTo
    {
        return $this->belongsTo(AdmissionCycle::class);
    }

    /** @return BelongsTo<Term, $this> */
    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    /** @return BelongsTo<Program, $this> */
    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    /** @return HasMany<ApplicationSubmissionVersion, $this> */
    public function submissionVersions(): HasMany
    {
        return $this->hasMany(ApplicationSubmissionVersion::class, 'admission_application_id');
    }

    /** @return BelongsTo<ApplicationSubmissionVersion, $this> */
    public function currentSubmissionVersion(): BelongsTo
    {
        return $this->belongsTo(ApplicationSubmissionVersion::class, 'current_submission_version_id');
    }

    public function currentPreliminaryReview(DocumentEvidence $evidence, bool $lockForUpdate = false): ?PreliminaryEvidenceReview
    {
        if ($evidence->admission_application_id !== $this->id) {
            return null;
        }
        $query = $evidence->preliminaryReviews()->whereDoesntHave('successor');
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }
        $review = $query->first();
        $typeBoundary = $this->submissionVersions()->where('snapshot->type_correction_request_id', '>', 0)->latest('version')->first();
        if ($review !== null && $typeBoundary !== null) {
            $prior = collect($typeBoundary->snapshot['requirements'] ?? [])->firstWhere('id', $evidence->admission_requirement_id);
            if (($prior['preliminary_review']['id'] ?? null) === $review->id) {
                return null;
            }
        }

        return $review;
    }

    /** @return HasMany<ApplicationCorrectionRequest, $this> */
    public function correctionRequests(): HasMany
    {
        return $this->hasMany(ApplicationCorrectionRequest::class, 'admission_application_id');
    }

    /** @return HasMany<AdmissionDecision, $this> */
    public function decisions(): HasMany
    {
        return $this->hasMany(AdmissionDecision::class, 'admission_application_id');
    }

    /** @return HasMany<RegistrarEnrollmentClearance, $this> */
    public function enrollmentClearances(): HasMany
    {
        return $this->hasMany(RegistrarEnrollmentClearance::class, 'admission_application_id');
    }

    /** @return HasMany<OfficialCredentialResult, $this> */
    public function credentialResults(): HasMany
    {
        return $this->hasMany(OfficialCredentialResult::class, 'admission_application_id');
    }

    /** @return HasMany<IdentityMatchReview, $this> */
    public function identityMatchReviews(): HasMany
    {
        return $this->hasMany(IdentityMatchReview::class, 'admission_application_id');
    }

    /** @return HasMany<AdmissionApplicationEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(AdmissionApplicationEvent::class, 'admission_application_id');
    }

    public function reviewHasStarted(): bool
    {
        return $this->events()->where('event_type', AdmissionApplicationEvent::TypeReviewStarted)->exists()
            || ($this->getAttribute('reviewed_by') !== null && $this->getAttribute('reviewed_at') !== null)
            || $this->evidenceVersions()->whereNotNull('reviewed_by')->whereNotNull('reviewed_at')->exists()
            || $this->evidenceVersions()->whereHas('preliminaryReviews')->exists()
            || $this->identityMatchReviews()->whereNotNull('resolved_at')->exists()
            || $this->correctionRequests()->exists()
            || $this->decisions()->exists()
            || $this->credentialResults()->exists()
            || $this->enrollmentClearances()->exists();
    }

    public function allowsSubmittedEdit(): bool
    {
        return $this->application_state === self::StateSubmitted
            && $this->current_submission_version_id !== null
            && ! $this->reviewHasStarted();
    }

    public function assertReviewSource(User $actor, ?int $expectedSubmissionVersionId): void
    {
        $actor = $actor->fresh();
        if (! $actor instanceof User) {
            throw new AuthorizationException('The Registrar account is unavailable.');
        }
        if (! $actor->hasRole(User::StaffRoleRegistrar)
            || ! $actor->canAuthenticate() || ! $actor->can('approve-documents')) {
            throw new AuthorizationException('Only an authorized Registrar may change a review.');
        }

        $source = $this->currentSubmissionVersion()->first();
        if ($this->current_submission_version_id === null
            || $source?->admission_application_id !== $this->id
            || $source->requirementSet?->admission_cycle_id !== $this->admission_cycle_id
            || $source->requirementSet?->application_path !== $this->application_path
            || $this->admissionCycle?->term_id !== $this->term_id
            || $this->current_submission_version_id !== $expectedSubmissionVersionId
            || ! in_array($this->application_state, [self::StateSubmitted, self::StateAdmitted, self::StateNotAdmitted], true)
            || ! $this->reviewHasStarted()) {
            throw ValidationException::withMessages([
                'review' => 'Start review of the current submitted version before making changes. If the source changed, refresh this record.',
            ]);
        }
    }

    /** @return HasMany<DocumentEvidence, $this> */
    public function evidenceVersions(): HasMany
    {
        return $this->hasMany(DocumentEvidence::class, 'admission_application_id');
    }
}
