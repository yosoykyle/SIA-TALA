<?php

namespace Tests\Unit;

use App\Support\DisplayDateTime;
use Carbon\CarbonImmutable;
use Filament\Support\Facades\FilamentTimezone;
use Tests\TestCase;

final class DisplayDateTimeContractTest extends TestCase
{
    public function test_utc_storage_uses_one_philippine_display_timezone_contract(): void
    {
        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('Asia/Manila', config('app.display_timezone'));
        $this->assertSame('Asia/Manila', FilamentTimezone::get());

        $timestamp = CarbonImmutable::parse('2026-07-26 00:00:00', 'UTC');

        $this->assertSame(
            'July 26, 2026 at 8:00 AM',
            DisplayDateTime::format($timestamp, 'F j, Y \a\t g:i A'),
        );
    }
}
