<?php

namespace App\Models;

use Database\Factories\AcademicYearFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class AcademicYear extends Model
{
    /** @use HasFactory<AcademicYearFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'academic_year',
        'school_year_start_date',
        'school_year_end_date',
        'status',
        'reference_note',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'school_year_start_date' => 'date',
            'school_year_end_date' => 'date',
        ];
    }

    public function terms(): HasMany
    {
        return $this->hasMany(Term::class);
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            'draft' => 'Draft',
            'active' => 'Active',
            'closed' => 'Closed',
            'archived' => 'Archived',
        ];
    }

    public function displayLabel(): string
    {
        return collect([
            $this->academic_year,
            $this->statusLabel(),
        ])->filter()->implode(' | ');
    }

    public function statusLabel(): string
    {
        return self::statusOptions()[$this->status]
            ?? Str::of((string) $this->status)->headline()->toString();
    }
}
