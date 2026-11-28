<?php

namespace Tests\Unit\Academics;

use App\Actions\Academics\YearLevelStanding;
use PHPUnit\Framework\TestCase;

class YearLevelStandingTest extends TestCase
{
    public function test_a_mixed_load_shows_its_highest_year_even_when_a_retake_comes_first(): void
    {
        $levels = ['First Year', '2', 'Second Year', '2nd Year', '2'];

        $this->assertSame(2, YearLevelStanding::highest($levels));
        $this->assertSame('Second Year (mixed levels)', YearLevelStanding::describe($levels));
    }

    public function test_a_single_year_has_no_mixed_note_whatever_way_it_was_recorded(): void
    {
        $this->assertSame('Third Year', YearLevelStanding::describe(['3', 'Third Year', '3rd Year']));
    }

    public function test_unrecognised_levels_are_ignored(): void
    {
        $this->assertNull(YearLevelStanding::highest(['', null, 'Bridging']));
        $this->assertNull(YearLevelStanding::describe([]));
        $this->assertSame('First Year', YearLevelStanding::describe(['Bridging', '1']));
    }
}
