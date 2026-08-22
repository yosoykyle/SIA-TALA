<?php

namespace Tests\Feature;

use App\Actions\Grades\FinalResultPolicy;
use App\Filament\Pages\FacultyGradeRoster;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class TAL72GradesMvpTest extends TestCase
{
    #[Test]
    public function legacy_period_and_p_contract_is_not_reachable_from_the_faculty_surface(): void
    {
        $source = file_get_contents((new \ReflectionClass(FacultyGradeRoster::class))->getFileName());

        $this->assertIsString($source);
        $this->assertStringNotContainsString('SaveGradeRosterPeriodEquivalent', $source);
        $this->assertStringNotContainsString('SaveGradeRosterControlledOutcome', $source);
        $this->assertStringNotContainsString('prelim_equivalent', $source);
        $this->assertStringNotContainsString('midterm_equivalent', $source);
        $this->assertStringNotContainsString('Set P / INC', $source);
    }

    #[Test]
    public function final_result_scale_treats_four_as_passing_and_five_as_failed(): void
    {
        $policy = app(FinalResultPolicy::class);

        $this->assertSame('Passing', $policy->category('4.00'));
        $this->assertSame('Failed', $policy->category('5.00'));

        $this->expectException(RuntimeException::class);
        $policy->normalize('P');
    }
}
