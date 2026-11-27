<?php

namespace Tests\Unit;

use App\Actions\Grades\FinalResultPolicy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FinalResultPolicyTest extends TestCase
{
    #[Test]
    public function final_result_scale_treats_four_as_passing_and_five_as_failed(): void
    {
        $policy = new FinalResultPolicy;

        $this->assertSame('Passing', $policy->category('4.00'));
        $this->assertSame('Failed', $policy->category('5.00'));

        $this->expectException(RuntimeException::class);
        $policy->normalize('P');
    }
}
