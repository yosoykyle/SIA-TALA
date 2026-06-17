<?php

namespace App\Models;

use Database\Factories\SubjectFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subject extends Model
{
    /** @use HasFactory<SubjectFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'description',
        'units',
        'lec_hours',
        'category',
        'department',
        'subject_type',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'units' => 'decimal:2',
            'lec_hours' => 'decimal:2',
        ];
    }

    public function grades(): HasMany
    {
        return $this->hasMany(Grade::class);
    }

    public function gradeCorrections(): HasMany
    {
        return $this->hasMany(GradeCorrection::class);
    }

    public function facultySubjectEligibilities(): HasMany
    {
        return $this->hasMany(FacultySubjectEligibility::class);
    }

    public function curriculumSubjects(): HasMany
    {
        return $this->hasMany(CurriculumSubject::class);
    }

    public function prerequisites(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'prerequisites',
            'subject_id',
            'prerequisite_id',
        )->withTimestamps();
    }

    public function requiredBySubjects(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'prerequisites',
            'prerequisite_id',
            'subject_id',
        )->withTimestamps();
    }
}
