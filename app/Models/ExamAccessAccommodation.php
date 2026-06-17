<?php

namespace App\Models;

use Database\Factories\ExamAccessAccommodationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamAccessAccommodation extends Model
{
    /** @use HasFactory<ExamAccessAccommodationFactory> */
    use HasFactory;

    public const StatusPending = 'pending';

    public const StatusApproved = 'approved';

    public const StatusRejected = 'rejected';

    public const StatusRevoked = 'revoked';

    public const ScopeTerm = 'term';

    public const ScopeAcademicYear = 'academic_year';

    public const BasisRa11984Certification = 'ra11984_certification';

    public const BasisInstitutionalDiscretion = 'institutional_discretion';

    protected $attributes = [
        'status' => self::StatusPending,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'student_profile_id',
        'academic_year_id',
        'term_id',
        'enrollment_id',
        'promissory_note_id',
        'scope',
        'basis',
        'status',
        'request_reason',
        'certifying_office',
        'certification_reference',
        'certified_at',
        'evidence_disk',
        'evidence_path',
        'evidence_file_name',
        'evidence_mime_type',
        'evidence_file_size',
        'valid_from',
        'valid_until',
        'requested_by',
        'requested_at',
        'reviewed_by',
        'reviewed_at',
        'review_reason',
        'revoked_by',
        'revoked_at',
        'revocation_reason',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'evidence_disk',
        'evidence_path',
        'evidence_file_name',
        'evidence_mime_type',
        'evidence_file_size',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'certified_at' => 'date',
            'valid_from' => 'date',
            'valid_until' => 'date',
            'requested_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function basisOptions(): array
    {
        return [
            self::BasisRa11984Certification => 'RA 11984 certification',
            self::BasisInstitutionalDiscretion => 'Institutional discretion',
        ];
    }

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function promissoryNote(): BelongsTo
    {
        return $this->belongsTo(PromissoryNote::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }
}
