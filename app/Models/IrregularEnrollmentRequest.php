<?php

namespace App\Models;

use Database\Factories\IrregularEnrollmentRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A student's "Enroll as irregular" request for one registration case (#99 Scope 11).
 *
 * It records the confirmed reasons, the student's remarks and documents, and the classes the student
 * would like. The Registrar turns it into the official proposal (Accepted) or returns it for correction.
 */
class IrregularEnrollmentRequest extends Model
{
    /** @use HasFactory<IrregularEnrollmentRequestFactory> */
    use HasFactory;

    public const StateSubmitted = 'SUBMITTED';

    public const StateReturned = 'RETURNED_FOR_CORRECTION';

    public const StateAccepted = 'ACCEPTED';

    protected $fillable = [
        'enrollment_id', 'student_profile_id', 'state', 'reasons', 'student_remarks', 'supporting_documents',
        'section_ids', 'selected_units', 'submitted_at', 'returned_at', 'returned_by', 'return_reason',
        'accepted_at', 'accepted_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'reasons' => 'array',
            'supporting_documents' => 'array',
            'section_ids' => 'array',
            'selected_units' => 'decimal:2',
            'submitted_at' => 'datetime',
            'returned_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    /** @return array<string, string> */
    public static function stateLabels(): array
    {
        return [
            self::StateSubmitted => 'Submitted – for Registrar approval',
            self::StateReturned => 'Returned for correction',
            self::StateAccepted => 'Approved – subjects prepared',
        ];
    }

    /** @return BelongsTo<Enrollment, $this> */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    /** @return BelongsTo<StudentProfile, $this> */
    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    /** @return BelongsTo<User, $this> */
    public function returnedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by');
    }

    /** @return list<int> */
    public function sectionIds(): array
    {
        return array_values(array_map('intval', $this->section_ids ?? []));
    }
}
