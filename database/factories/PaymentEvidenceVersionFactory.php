<?php

namespace Database\Factories;

use App\Models\PaymentEvidenceVersion;
use App\Models\TermAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentEvidenceVersion>
 */
class PaymentEvidenceVersionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'term_account_id' => TermAccount::factory(),
            'version' => 1,
            'state' => PaymentEvidenceVersion::StateSubmitted,
            'disk' => 'local',
            'path' => fn (array $attributes): string => 'registration-payment-evidence/'.$attributes['term_account_id'].'/synthetic.pdf',
            'original_name' => 'synthetic-payment-evidence.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 128,
            'checksum' => hash('sha256', 'synthetic-payment-evidence'),
            'claimed_amount' => '1000.00',
            'submitted_by' => User::factory(),
            'submitted_at' => now(),
        ];
    }
}
