<?php

namespace App\Models;

use Database\Factories\EnrollmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Enrollment extends Model
{
    /** @use HasFactory<EnrollmentFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'student_profile_id',
        'term_id',
        'section_id',
        'section_delivery_group_id',
        'status',
        'student_type',
        'year_level',
        'modality',
        'lis_status',
        'is_late_enrollment',
        'enrolled_at',
        'pre_enrolled_at',
        'officially_enrolled_at',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_late_enrollment' => 'boolean',
            'enrolled_at' => 'datetime',
            'pre_enrolled_at' => 'datetime',
            'officially_enrolled_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function sectionDeliveryGroup(): BelongsTo
    {
        return $this->belongsTo(SectionDeliveryGroup::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function grades(): HasMany
    {
        return $this->hasMany(Grade::class);
    }

    public function isFreshmenDiscountEligible(): bool
    {
        $studentType = Str::of((string) $this->student_type)->lower()->replace(['-', '_'], ' ')->squish()->toString();
        $yearLevel = Str::of((string) $this->year_level)->lower()->replace(['-', '_'], ' ')->squish()->toString();

        return in_array($studentType, ['new', 'freshman', 'freshmen'], true)
            && in_array($yearLevel, ['grade 11', '11', '1st year', 'first year', '1'], true);
    }

    public function displayLabel(): string
    {
        $this->loadMissing('term');

        return collect([
            "#{$this->id}",
            $this->term?->term_name ?? 'No term',
            $this->status,
            $this->year_level,
        ])
            ->filter(fn (?string $part): bool => filled($part))
            ->implode(' - ');
    }
}
