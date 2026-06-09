<?php

namespace App\Models;

use Database\Factories\FacultyAvailabilityWindowFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FacultyAvailabilityWindow extends Model
{
    /** @use HasFactory<FacultyAvailabilityWindowFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'submission_id',
        'day_of_week',
        'starts_at',
        'ends_at',
        'notes',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(FacultyAvailabilitySubmission::class, 'submission_id');
    }
}
