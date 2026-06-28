<?php

namespace App\Models;

use Database\Factories\CourseComponentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseComponent extends Model
{
    /** @use HasFactory<CourseComponentFactory> */
    use HasFactory;

    public const TypeLecture = 'LECTURE';

    public const TypeLaboratory = 'LABORATORY';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'course_specification_id',
        'component_type',
        'weekly_contact_hours',
        'room_type_default',
        'modality_restriction',
        'requires_consecutive_block',
        'same_faculty',
        'sequence',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'weekly_contact_hours' => 'decimal:2',
            'requires_consecutive_block' => 'boolean',
            'same_faculty' => 'boolean',
            'sequence' => 'integer',
        ];
    }

    public function courseSpecification(): BelongsTo
    {
        return $this->belongsTo(CourseSpecification::class);
    }
}
