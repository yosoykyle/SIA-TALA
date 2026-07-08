<?php

namespace Database\Factories;

use App\Models\OperationalEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OperationalEvent>
 */
class OperationalEventFactory extends Factory
{
    protected $model = OperationalEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_domain' => 'notifications',
            'integration' => 'mail',
            'channel' => null,
            'direction' => null,
            'event_type' => 'test_email_sent',
            'event_version' => null,
            'user_id' => null,
            'recipient_snapshot' => null,
            'external_id' => null,
            'status' => 'PROCESSED',
            'occurred_at' => now(),
            'processed_at' => null,
            'sent_at' => now(),
            'failed_at' => null,
            'related_record_type' => null,
            'related_record_id' => null,
            'diagnostics' => null,
            'payload' => null,
        ];
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'event_type' => 'test_email_failed',
            'status' => 'FAILED',
            'sent_at' => null,
            'failed_at' => now(),
            'diagnostics' => ['reason' => 'Simulated failure for testing.'],
        ]);
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (): array => [
            'user_id' => $user->id,
        ]);
    }
}
