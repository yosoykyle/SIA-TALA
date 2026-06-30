<?php

namespace App\Models;

use Database\Factories\StudentLifecycleChangeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $effective_on
 * @property Carbon $decided_on
 * @property-read Term $term
 */
class StudentLifecycleChange extends Model
{
    /** @use HasFactory<StudentLifecycleChangeFactory> */
    use HasFactory;

    public const TypeSubjectDrop = 'SUBJECT_DROP';

    public const TypeWithdrawal = 'WITHDRAWAL';

    public const TypeLeaveOfAbsence = 'LEAVE_OF_ABSENCE';

    public const TypeTransferOut = 'TRANSFER_OUT';

    public const TypeReactivation = 'REACTIVATION';

    public const TypeProgramShift = 'PROGRAM_SHIFT';

    public const StateRecordedApproved = 'RECORDED_APPROVED';

    public const StateApplied = 'APPLIED';

    public const StateCancelled = 'CANCELLED';

    /** @var list<string> */
    protected $fillable = [
        'student_profile_id', 'term_id', 'expected_return_term_id', 'target_program_id',
        'target_curriculum_version_id', 'type', 'enrollment_id', 'course_enrollment_id',
        'requested_on', 'effective_on', 'decided_on', 'authority', 'late_exception_authority',
        'late_exception_reason', 'private_source_reference', 'reason', 'impact_snapshot',
        'recorded_by', 'state',
    ];

    protected function casts(): array
    {
        return [
            'requested_on' => 'date', 'effective_on' => 'date', 'decided_on' => 'date',
            'impact_snapshot' => 'array',
        ];
    }

    public static function typeOptions(): array
    {
        return [
            self::TypeSubjectDrop => 'Subject Drop', self::TypeWithdrawal => 'Withdrawal',
            self::TypeLeaveOfAbsence => 'Leave of Absence', self::TypeTransferOut => 'Transfer Out',
            self::TypeReactivation => 'Reactivation', self::TypeProgramShift => 'Program Shift',
        ];
    }

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    /** @return BelongsTo<Term, $this> */
    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function expectedReturnTerm(): BelongsTo
    {
        return $this->belongsTo(Term::class, 'expected_return_term_id');
    }

    public function targetProgram(): BelongsTo
    {
        return $this->belongsTo(Program::class, 'target_program_id');
    }

    public function targetCurriculumVersion(): BelongsTo
    {
        return $this->belongsTo(CurriculumVersion::class, 'target_curriculum_version_id');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function courseEnrollment(): BelongsTo
    {
        return $this->belongsTo(CourseEnrollment::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function programShiftCredits(): HasMany
    {
        return $this->hasMany(ProgramShiftCreditEntry::class);
    }
}
