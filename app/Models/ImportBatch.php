<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ImportBatch extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'import_type',
        'file_name',
        'file_path',
        'total_rows',
        'valid_rows',
        'error_rows',
        'skipped_rows',
        'status',
        'imported_by',
        'committed_by',
        'committed_at',
        'error_log',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'committed_at' => 'datetime',
            'error_log' => 'array',
        ];
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function committer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'committed_by');
    }

    protected static function booted(): void
    {
        static::creating(function (ImportBatch $importBatch): void {
            if (! $importBatch->getKey()) {
                $importBatch->setAttribute($importBatch->getKeyName(), (string) Str::uuid());
            }
        });
    }
}
