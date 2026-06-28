<?php

namespace App\Models;

use Database\Factories\DocumentEvidenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentEvidence extends Model
{
    /** @use HasFactory<DocumentEvidenceFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'checklist_item_id',
        'disk',
        'path',
        'checksum',
        'mime_type',
        'size_bytes',
        'evidence_method',
        'status',
        'uploaded_by',
        'uploaded_at',
        'reviewed_by',
        'reviewed_at',
        'replaces_document_evidence_id',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'uploaded_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function checklistItem(): BelongsTo
    {
        return $this->belongsTo(ChecklistItem::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function replacedEvidence(): BelongsTo
    {
        return $this->belongsTo(DocumentEvidence::class, 'replaces_document_evidence_id');
    }
}
