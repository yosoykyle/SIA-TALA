<?php

namespace Database\Factories;

use App\Models\ImportBatch;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ImportBatch>
 */
class ImportBatchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'type' => ImportBatch::TypeCurriculum,
            'template_version' => 'curriculum-v1',
            'source_disk' => 'local',
            'source_path' => 'imports/'.Str::uuid().'.csv',
            'source_checksum' => hash('sha256', fake()->uuid()),
            'uploaded_by' => null,
            'row_count' => 1,
            'error_count' => 0,
            'warning_count' => 0,
            'state' => ImportBatch::StatePendingReview,
            'validation_details' => [
                'rows' => [],
            ],
            'acknowledged_at' => null,
            'posted_at' => null,
        ];
    }
}
