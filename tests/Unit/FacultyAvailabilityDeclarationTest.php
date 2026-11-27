<?php

namespace Tests\Unit;

use App\Models\FacultyAvailabilityDeclaration;
use App\Models\TermCalendarPackage;
use App\Models\TermTeachingGridRow;
use PHPUnit\Framework\TestCase;

final class FacultyAvailabilityDeclarationTest extends TestCase
{
    public function test_legacy_declarations_map_to_availability_first_modes_and_compile_blocks_unchanged(): void
    {
        $calendar = $this->calendar([
            1 => ['08:00:00', '17:00:00', [['starts_at' => '12:00:00', 'ends_at' => '13:00:00']]],
            2 => ['08:00:00', '12:00:00', []],
        ]);

        $any = new FacultyAvailabilityDeclaration([
            'declaration' => FacultyAvailabilityDeclaration::DeclarationAvailable,
            'hard_unavailability' => [],
        ]);
        $this->assertSame(FacultyAvailabilityDeclaration::ModeAnyApproved, $any->effectiveMode());
        $this->assertSame('Any approved teaching time', $any->modeLabel());
        $this->assertSame([], $any->compileUnavailableBlocks($calendar));

        $legacyRestricted = new FacultyAvailabilityDeclaration([
            'declaration' => FacultyAvailabilityDeclaration::DeclarationAvailable,
            'hard_unavailability' => [
                ['day_of_week' => 1, 'starts_at' => '09:00:00', 'ends_at' => '11:00:00'],
            ],
        ]);
        $this->assertSame(FacultyAvailabilityDeclaration::ModeLegacyUnavailability, $legacyRestricted->effectiveMode());
        $this->assertSame('Any approved teaching time (except recorded unavailable times)', $legacyRestricted->modeLabel());
        $this->assertSame([
            ['day_of_week' => 1, 'starts_at' => '09:00:00', 'ends_at' => '11:00:00'],
        ], $legacyRestricted->compileUnavailableBlocks($calendar));

        $cannotTeach = new FacultyAvailabilityDeclaration([
            'declaration' => FacultyAvailabilityDeclaration::DeclarationUnavailable,
            'hard_unavailability' => [],
        ]);
        $this->assertSame(FacultyAvailabilityDeclaration::ModeCannotTeach, $cannotTeach->effectiveMode());
        $this->assertSame('Cannot teach this Term', $cannotTeach->modeLabel());
        $this->assertSame([
            ['day_of_week' => 1, 'starts_at' => '08:00:00', 'ends_at' => '17:00:00'],
            ['day_of_week' => 2, 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
        ], $cannotTeach->compileUnavailableBlocks($calendar));
    }

    public function test_chosen_windows_compile_exact_complement_never_widen_and_flag_break_only_windows_for_grid_review(): void
    {
        $calendar = $this->calendar([
            1 => ['08:00:00', '17:00:00', [['starts_at' => '12:00:00', 'ends_at' => '13:00:00']]],
            3 => ['09:00:00', '15:00:00', []],
        ]);

        $declaration = new FacultyAvailabilityDeclaration([
            'declaration' => FacultyAvailabilityDeclaration::DeclarationAvailable,
            'availability_mode' => FacultyAvailabilityDeclaration::ModeWindows,
            'teaching_windows' => [
                ['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '12:00'],
                ['day_of_week' => 1, 'start_time' => '13:00', 'end_time' => '15:00'],
            ],
            'hard_unavailability' => [],
        ]);

        $this->assertSame(FacultyAvailabilityDeclaration::ModeWindows, $declaration->effectiveMode());
        $this->assertSame('Choose teaching times', $declaration->modeLabel());
        $this->assertFalse($declaration->needsGridReview($calendar));
        $this->assertSame([], $declaration->windowsOutsideGrid($calendar));

        $this->assertSame([
            ['day_of_week' => 1, 'starts_at' => '08:00:00', 'ends_at' => '09:00:00'],
            ['day_of_week' => 1, 'starts_at' => '12:00:00', 'ends_at' => '13:00:00'],
            ['day_of_week' => 1, 'starts_at' => '15:00:00', 'ends_at' => '17:00:00'],
            ['day_of_week' => 3, 'starts_at' => '09:00:00', 'ends_at' => '15:00:00'],
        ], $declaration->compileUnavailableBlocks($calendar));

        // Later grid widening on Day 1 (07:00-20:00) and added break (10:00-11:00) never widen chosen windows.
        $widenedCalendar = $this->calendar([
            1 => [
                '07:00:00',
                '20:00:00',
                [
                    ['starts_at' => '10:00:00', 'ends_at' => '11:00:00'],
                    ['starts_at' => '12:00:00', 'ends_at' => '13:00:00'],
                ],
            ],
        ]);

        $this->assertFalse($declaration->needsGridReview($widenedCalendar));
        $this->assertSame([], $declaration->windowsOutsideGrid($widenedCalendar));
        $this->assertSame([
            ['day_of_week' => 1, 'starts_at' => '07:00:00', 'ends_at' => '09:00:00'],
            ['day_of_week' => 1, 'starts_at' => '12:00:00', 'ends_at' => '13:00:00'],
            ['day_of_week' => 1, 'starts_at' => '15:00:00', 'ends_at' => '20:00:00'],
        ], $declaration->compileUnavailableBlocks($widenedCalendar));

        // A window wholly inside the 10:00-11:00 break leaves zero usable minutes and IS flagged.
        $breakOnlyDeclaration = new FacultyAvailabilityDeclaration([
            'declaration' => FacultyAvailabilityDeclaration::DeclarationAvailable,
            'availability_mode' => FacultyAvailabilityDeclaration::ModeWindows,
            'teaching_windows' => [
                ['day_of_week' => 1, 'start_time' => '10:00', 'end_time' => '11:00'],
            ],
            'hard_unavailability' => [],
        ]);

        $this->assertTrue($breakOnlyDeclaration->needsGridReview($widenedCalendar));
        $outsideBreakOnly = $breakOnlyDeclaration->windowsOutsideGrid($widenedCalendar);
        $this->assertCount(1, $outsideBreakOnly);
        $this->assertSame('10:00', $outsideBreakOnly[0]['start_time']);
        $this->assertSame('11:00', $outsideBreakOnly[0]['end_time']);
    }

    /**
     * @param  array<int, array{0:string,1:string,2:list<array{starts_at:string,ends_at:string}>}>  $days
     */
    private function calendar(array $days): TermCalendarPackage
    {
        $rows = [];

        foreach ($days as $day => [$startsAt, $endsAt, $breaks]) {
            $rows[] = new TermTeachingGridRow([
                'day_of_week' => $day,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'breaks' => $breaks,
            ]);
        }

        $calendar = new class extends TermCalendarPackage
        {
            public function loadMissing($relations)
            {
                return $this;
            }
        };
        $calendar->setRelation('teachingGridRows', collect($rows));

        return $calendar;
    }
}
