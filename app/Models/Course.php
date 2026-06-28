<?php

namespace App\Models;

use Database\Factories\CourseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Course extends Model
{
    /** @use HasFactory<CourseFactory> */
    use HasFactory;

    public const StateActive = 'ACTIVE';

    public const StateRetired = 'RETIRED';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'state',
    ];

    public function specifications(): HasMany
    {
        return $this->hasMany(CourseSpecification::class);
    }

    public function requirementsReferencingThisCourse(): HasMany
    {
        return $this->hasMany(CourseRequirement::class, 'related_course_id');
    }

    public function facultyQualifications(): HasMany
    {
        return $this->hasMany(FacultyQualification::class);
    }
}
