<?php

namespace App\Actions\Enrollment;

use App\Models\Enrollment;

final readonly class AutoFinalizationResult
{
    public const StatusFinalized = 'finalized';

    public const StatusAlreadyOfficial = 'already_official';

    public const StatusNotCleared = 'not_cleared';

    public const StatusDeferredUnconfirmed = 'deferred_unconfirmed';

    public const StatusDeferredBlocked = 'deferred_blocked';

    public const StatusFailed = 'failed';

    public const StatusSkipped = 'skipped';

    /** @param list<string> $blockers */
    public function __construct(
        public string $status,
        public ?Enrollment $enrollment = null,
        public array $blockers = [],
        public ?string $errorMessage = null,
        public bool $alertedRegistrars = false,
    ) {}

    public function wasFinalized(): bool
    {
        return $this->status === self::StatusFinalized;
    }

    public function alertedRegistrars(): bool
    {
        return $this->alertedRegistrars;
    }
}
