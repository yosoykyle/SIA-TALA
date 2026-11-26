<?php

namespace App\Models;

use Database\Factories\RegistrationCreditLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * The previous school's source for one credited SIA subject: course, units and the TOR grade as printed.
 * The grade is a record only; academic averages never read it.
 */
class RegistrationCreditLine extends Model
{
    /** @use HasFactory<RegistrationCreditLineFactory> */
    use HasFactory;

    protected $fillable = ['registration_credit_evaluation_id', 'curriculum_entry_id', 'source_course_code', 'source_course_title',
        'source_units', 'tor_grade', 'remarks'];

    protected function casts(): array
    {
        return ['source_units' => 'decimal:2'];
    }

    /** @return BelongsTo<RegistrationCreditEvaluation, $this> */
    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(RegistrationCreditEvaluation::class, 'registration_credit_evaluation_id');
    }

    /** @return BelongsTo<CurriculumEntry, $this> */
    public function curriculumEntry(): BelongsTo
    {
        return $this->belongsTo(CurriculumEntry::class);
    }

    public function transcriptRemark(): string
    {
        return 'Transfer credit from '.$this->source_course_code;
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Transfer credit lines are immutable. Record a replacement crediting.'));
        static::deleting(fn (): never => throw new LogicException('Transfer credit history cannot be deleted.'));
    }
}
