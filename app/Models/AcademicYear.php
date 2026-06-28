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

    public const StateDraft = 'DRAFT';

    public const StateActive = 'ACTIVE';

    public const StateClosed = 'CLOSED';

    public const StateArchived = 'ARCHIVED';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'label',
        'starts_on',
        'ends_on',
        'state',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    public function terms(): HasMany
    {
        return $this->hasMany(Term::class);
    }

    /**
     * @return array<string, string>
     */
    public static function stateOptions(): array
    {
        return [
            self::StateDraft => 'Draft',
            self::StateActive => 'Active',
            self::StateClosed => 'Closed',
            self::StateArchived => 'Archived',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return self::stateOptions();
    }

    public function displayLabel(): string
    {
        return collect([
            $this->label,
            $this->stateLabel(),
        ])->filter()->implode(' | ');
    }

    public function stateLabel(): string
    {
        return self::stateOptions()[$this->state]
            ?? Str::of((string) $this->state)->headline()->toString();
    }

    public function statusLabel(): string
    {
        return $this->stateLabel();
    }
}
