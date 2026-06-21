<?php

namespace App\Models;

use Database\Factories\SectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Section extends Model
{
    /** @use HasFactory<SectionFactory> */
    use HasFactory;

    public const MaxRescueSeats = 30;

    /**
     * @return array<string, string>
     */
    public static function modalityOptions(): array
    {
        return SectionMeeting::modalityOptions();
    }

    /**
     * @return list<string>
     */
    public static function modalityValues(): array
    {
        return array_keys(self::modalityOptions());
    }

    /**
     * @return array<string, string>
     */
    public static function yearLevelOptions(): array
    {
        return [
            '1st Year' => '1st Year',
            '2nd Year' => '2nd Year',
            '3rd Year' => '3rd Year',
            '4th Year' => '4th Year',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function curriculumPeriodOptions(): array
    {
        return [
            '1st Semester' => '1st Semester',
            '2nd Semester' => '2nd Semester',
            'Summer' => 'Summer',
        ];
    }

    public static function modalityRequiresRoom(?string $modality): bool
    {
        return in_array($modality, ['on_site', 'blended'], true);
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'term_id',
        'program_id',
        'curriculum_id',
        'year_level',
        'curriculum_period',
        'name',
        'room',
        'max_seats',
        'enrolled_count',
        'modality',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'max_seats' => 'integer',
            'enrolled_count' => 'integer',
        ];
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function curriculum(): BelongsTo
    {
        return $this->belongsTo(Curriculum::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function deliveryGroups(): HasMany
    {
        return $this->hasMany(SectionDeliveryGroup::class);
    }
}
