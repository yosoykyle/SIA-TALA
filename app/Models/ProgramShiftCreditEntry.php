<?php

namespace App\Models;

use Database\Factories\ProgramShiftCreditEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProgramShiftCreditEntry extends Model
{
    /** @use HasFactory<ProgramShiftCreditEntryFactory> */
    use HasFactory;

    public const TreatmentAccepted = 'ACCEPTED';

    public const TreatmentDeficient = 'DEFICIENT';

    public const TreatmentRejected = 'REJECTED';

    public const StateRecorded = 'RECORDED';

    /** @var list<string> */
    protected $fillable = [
        'student_lifecycle_change_id', 'curriculum_entry_id', 'source_course_id',
        'source_grade_outcome_event_id', 'treatment', 'state', 'numeric_grade',
    ];

    protected function casts(): array
    {
        return ['numeric_grade' => 'decimal:4'];
    }

    public function lifecycleChange(): BelongsTo
    {
        return $this->belongsTo(StudentLifecycleChange::class, 'student_lifecycle_change_id');
    }

    public function curriculumEntry(): BelongsTo
    {
        return $this->belongsTo(CurriculumEntry::class);
    }

    public function sourceCourse(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'source_course_id');
    }

    public function sourceGradeOutcomeEvent(): BelongsTo
    {
        return $this->belongsTo(GradeOutcomeEvent::class, 'source_grade_outcome_event_id');
    }
}
