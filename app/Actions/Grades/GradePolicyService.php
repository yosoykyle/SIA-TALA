<?php

namespace App\Actions\Grades;

use App\Models\GradeRosterRow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

class GradePolicyService
{
    /**
     * @return array<string, mixed>
     */
    public function snapshot(string $key = 'servitech_v1'): array
    {
        $policy = Config::array("grades.$key");

        if ($policy === []) {
            throw new InvalidArgumentException("Unknown grade policy [$key].");
        }

        return $policy;
    }

    public function computedAverage(float|int|string $prelim, float|int|string $midterm, float|int|string $final): float
    {
        $weights = Config::array('grades.servitech_v1.formula');

        return round(
            ((float) $prelim * (float) $weights['prelim'])
            + ((float) $midterm * (float) $weights['midterm'])
            + ((float) $final * (float) $weights['final']),
            4,
        );
    }

    /**
     * @return array{code:string, category:string, value:float|null}
     */
    public function outcomeForAverage(float|int|string $average): array
    {
        $average = (float) $average;

        foreach (Config::array('grades.servitech_v1.scale') as $band) {
            if ($average >= (float) $band['min'] && $average <= (float) $band['max']) {
                return [
                    'code' => (string) $band['code'],
                    'category' => (string) $band['category'],
                    'value' => (float) $band['code'],
                ];
            }
        }

        throw new InvalidArgumentException('Average must be between 0 and 100.');
    }

    /**
     * @return array{code:string, category:string, value:float|null}
     */
    public function controlledOutcome(string $code): array
    {
        $code = strtoupper($code);

        return match ($code) {
            'P' => ['code' => 'P', 'category' => GradeRosterRow::CategoryPending, 'value' => null],
            'INC' => ['code' => 'INC', 'category' => GradeRosterRow::CategoryIncomplete, 'value' => null],
            default => throw new InvalidArgumentException('Only P or INC can override the computed outcome before release.'),
        };
    }

    public function incDeadline(): Carbon
    {
        return now()->addDays(Config::integer('grades.servitech_v1.inc_deadline_days'));
    }
}
