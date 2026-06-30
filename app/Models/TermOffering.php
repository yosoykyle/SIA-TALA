<?php

namespace App\Models;

use Database\Factories\TermOfferingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TermOffering extends Model
{
    /** @use HasFactory<TermOfferingFactory> */
    use HasFactory;

    public const CategoryRegular = 'REGULAR';

    public const CategorySpecial = 'SPECIAL';

    public const ArrangementNormalClass = 'NORMAL_CLASS';

    public const ArrangementTutorial = 'TUTORIAL';

    public const ModalityOnline = 'ONLINE';

    public const ModalityFaceToFace = 'FACE_TO_FACE';

    public const ModalityModular = 'MODULAR';

    public const StatePendingScheduling = 'PENDING_SCHEDULING';

    public const StateScheduled = 'SCHEDULED';

    public const StateCancelled = 'CANCELLED';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'term_id',
        'curriculum_entry_id',
        'category',
        'special_reason',
        'delivery_variant',
        'modality',
        'expected_count',
        'room_type_override',
        'same_faculty_override',
        'state',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expected_count' => 'integer',
            'same_faculty_override' => 'boolean',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function modalityOptions(): array
    {
        return [
            self::ModalityOnline => 'Online',
            self::ModalityFaceToFace => 'Face-to-Face',
            self::ModalityModular => 'Modular',
        ];
    }

    public function usesSpecialReason(): bool
    {
        return $this->category === self::CategorySpecial && filled($this->special_reason);
    }

    public function courseSpecification(): ?CourseSpecification
    {
        $curriculumEntry = $this->curriculumEntry;

        if (! $curriculumEntry instanceof CurriculumEntry) {
            return null;
        }

        $courseSpecification = $curriculumEntry->courseSpecification;

        return $courseSpecification instanceof CourseSpecification ? $courseSpecification : null;
    }

    public function course(): ?Course
    {
        $course = $this->courseSpecification()?->course;

        return $course instanceof Course ? $course : null;
    }

    /** @return BelongsTo<Term, $this> */
    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    /** @return BelongsTo<CurriculumEntry, $this> */
    public function curriculumEntry(): BelongsTo
    {
        return $this->belongsTo(CurriculumEntry::class);
    }

    /** @return HasMany<Section, $this> */
    public function sections(): HasMany
    {
        return $this->hasMany(Section::class);
    }

    /** @return HasMany<GradeRoster, $this> */
    public function gradeRosters(): HasMany
    {
        return $this->hasMany(GradeRoster::class);
    }
}
