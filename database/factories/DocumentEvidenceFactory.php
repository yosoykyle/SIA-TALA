<?php

namespace Database\Factories;

use App\Models\ChecklistItem;
use App\Models\DocumentEvidence;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentEvidence>
 */
class DocumentEvidenceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'checklist_item_id' => ChecklistItem::factory(),
            'disk' => 'local',
            'path' => 'applicant-evidence/'.fake()->uuid().'.pdf',
            'checksum' => fake()->sha256(),
            'mime_type' => 'application/pdf',
            'size_bytes' => fake()->numberBetween(100, 5000),
            'evidence_method' => 'DIGITAL_UPLOAD',
            'status' => 'SUBMITTED',
            'uploaded_by' => null,
            'uploaded_at' => now(),
            'reviewed_by' => null,
            'reviewed_at' => null,
            'replaces_document_evidence_id' => null,
        ];
    }
}
