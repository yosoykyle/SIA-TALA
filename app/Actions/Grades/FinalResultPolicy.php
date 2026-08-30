<?php

namespace App\Actions\Grades;

use App\Models\GradeRosterRow;
use RuntimeException;

class FinalResultPolicy
{
    /** @return array{key:string,version:int,accepted_codes:list<string>,passing_through:string} */
    public function snapshot(): array
    {
        return [
            'key' => 'tala_final_result_v1',
            'version' => 1,
            'accepted_codes' => $this->acceptedCodes(),
            'passing_through' => '4.00',
        ];
    }

    /** @return array<string, string> */
    public function options(): array
    {
        return collect($this->acceptedCodes())->mapWithKeys(
            fn (string $code): array => [$code => $code],
        )->all();
    }

    /** @return list<string> */
    public function acceptedCodes(): array
    {
        return [
            '1.00', '1.25', '1.50', '1.75', '2.00', '2.25', '2.50', '2.75',
            '3.00', '4.00', '5.00', 'INC',
        ];
    }

    public function normalize(string|int|float $result): string
    {
        $normalized = strtoupper(trim((string) $result));

        if (is_numeric($normalized)) {
            $normalized = number_format((float) $normalized, 2, '.', '');
        }

        if (! in_array($normalized, $this->acceptedCodes(), true)) {
            throw new RuntimeException('The final result must use the approved 1.00–5.00 or INC vocabulary.');
        }

        return $normalized;
    }

    public function category(string $result): string
    {
        $result = $this->normalize($result);

        return match (true) {
            $result === 'INC' => GradeRosterRow::CategoryIncomplete,
            (float) $result <= 4.00 => GradeRosterRow::CategoryPassing,
            default => GradeRosterRow::CategoryFailed,
        };
    }

    public function numericValue(string $result): ?float
    {
        $result = $this->normalize($result);

        return $result === 'INC' ? null : (float) $result;
    }
}
