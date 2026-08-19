<?php

namespace App\Models;

use Database\Factories\FacultyAvailabilityDeclarationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property array<int, array{day_of_week:int,starts_at:string,ends_at:string}> $hard_unavailability
 * @property Carbon $declared_at
 */
class FacultyAvailabilityDeclaration extends Model
{
    /** @use HasFactory<FacultyAvailabilityDeclarationFactory> */
    use HasFactory;

    protected $fillable = [
        'term_id', 'faculty_user_id', 'version', 'declaration',
        'hard_unavailability', 'correction_reason', 'declared_at',
    ];

    protected function casts(): array
    {
        return ['version' => 'integer', 'hard_unavailability' => 'array', 'declared_at' => 'datetime'];
    }

    /** @return BelongsTo<Term, $this> */
    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    /** @return BelongsTo<User, $this> */
    public function faculty(): BelongsTo
    {
        return $this->belongsTo(User::class, 'faculty_user_id');
    }
}
