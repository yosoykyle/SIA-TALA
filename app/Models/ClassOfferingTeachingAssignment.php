<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClassOfferingTeachingAssignment extends Model
{
    public const RoleDesignated = 'DESIGNATED';

    public const RoleCoFaculty = 'CO_FACULTY';

    public const StateActive = 'ACTIVE';

    public const StateReplaced = 'REPLACED';

    public const StateEnded = 'ENDED';

    /** Authority prefix written when the published timetable is the source of the assignment. */
    public const TimetableAuthorityPrefix = 'Published timetable v';

    protected $fillable = [
        'term_offering_id', 'section_id', 'faculty_user_id', 'role', 'state',
        'authority_reference', 'assigned_by', 'effective_at', 'ended_at',
        'replaced_by_assignment_id',
    ];

    protected function casts(): array
    {
        return ['effective_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    public function isFromPublishedTimetable(): bool
    {
        return (bool) preg_match('/^'.preg_quote(self::TimetableAuthorityPrefix, '/').'\d+/', (string) $this->authority_reference);
    }

    public function termOffering(): BelongsTo
    {
        return $this->belongsTo(TermOffering::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function faculty(): BelongsTo
    {
        return $this->belongsTo(User::class, 'faculty_user_id');
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
