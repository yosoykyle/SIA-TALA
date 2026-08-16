<?php

namespace App\Models;

use Database\Factories\IdentityMatchReviewFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IdentityMatchReview extends Model
{
    /** @use HasFactory<IdentityMatchReviewFactory> */
    use HasFactory;

    public const TypeVerifiedLrnCollision = 'VerifiedLrnCollision';

    public const TypeExactNameBirthDate = 'ExactNameBirthDate';

    public const OutcomePending = 'Pending';

    public const OutcomeSamePerson = 'SamePerson';

    public const OutcomeDifferentPerson = 'DifferentPerson';

    public const OutcomeCorrectedIdentifier = 'CorrectedIdentifier';

    /** @var list<string> */
    protected $fillable = [
        'admission_application_id',
        'review_key',
        'match_type',
        'outcome',
        'candidate_user_id',
        'evidence_reference',
        'corrected_identifier',
        'resolved_by',
        'resolved_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'outcome' => self::OutcomePending,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    /** @return BelongsTo<AdmissionApplication, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(AdmissionApplication::class, 'admission_application_id');
    }

    /** @return BelongsTo<User, $this> */
    public function candidateUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'candidate_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
