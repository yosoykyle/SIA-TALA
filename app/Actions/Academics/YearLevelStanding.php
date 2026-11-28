<?php

namespace App\Actions\Academics;

use App\Models\TermCohort;

/**
 * One rule for showing a student's year level when a term mixes subjects from several
 * curriculum years (#117): the highest year level being taken, with a "mixed levels" note.
 * A 2nd-year student retaking a 1st-year subject is shown as Second Year, not First Year.
 */
class YearLevelStanding
{
    public const MixedNote = ' (mixed levels)';

    /**
     * @param  iterable<mixed>  $rawLevels  Curriculum year levels as recorded ("1", "First Year", "2nd Year", ...).
     */
    public static function highest(iterable $rawLevels): ?int
    {
        $highest = null;

        foreach ($rawLevels as $raw) {
            $level = TermCohort::parseYearLevelValue($raw);
            if ($level !== null && ($highest === null || $level > $highest)) {
                $highest = $level;
            }
        }

        return $highest;
    }

    public static function label(int $level): string
    {
        return match ($level) {
            1 => 'First Year',
            2 => 'Second Year',
            3 => 'Third Year',
            4 => 'Fourth Year',
            5 => 'Fifth Year',
            default => 'Year '.$level,
        };
    }

    /**
     * The highest year level's label, noting "(mixed levels)" when more than one year is present.
     *
     * @param  iterable<mixed>  $rawLevels
     */
    public static function describe(iterable $rawLevels): ?string
    {
        $levels = [];
        foreach ($rawLevels as $raw) {
            $level = TermCohort::parseYearLevelValue($raw);
            if ($level !== null) {
                $levels[$level] = true;
            }
        }

        if ($levels === []) {
            return null;
        }

        $highest = max(array_keys($levels));

        return self::label($highest).(count($levels) > 1 ? self::MixedNote : '');
    }
}
