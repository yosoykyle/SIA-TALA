<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OutputAccessLog extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'output_type', 'source_record_type', 'source_record_id', 'student_profile_id',
        'actor_user_id', 'actor_role', 'action', 'copy_context', 'schedule_version',
        'filter_summary', 'row_count', 'purpose', 'sensitivity', 'stored_file_reference',
        'request_context', 'status', 'occurred_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'filter_summary' => 'array',
            'request_context' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /** @return BelongsTo<StudentProfile, $this> */
    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }
}
