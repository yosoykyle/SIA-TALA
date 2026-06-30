<?php

namespace App\Actions\Grades;

use App\Models\GradeOutcomeEvent;
use App\Models\GradeRosterRow;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RecordIncResolution
{
    public function __construct(private readonly GradePolicyService $policy) {}

    public function execute(GradeRosterRow $row, string $replacementCode, string $authority, string $reason, ?string $evidenceReference, User $actor): GradeRosterRow
    {
        return DB::transaction(function () use ($row, $replacementCode, $authority, $reason, $evidenceReference, $actor): GradeRosterRow {
            $locked = GradeRosterRow::query()->lockForUpdate()->findOrFail($row->id);

            if ($locked->current_outcome_code !== 'INC') {
                throw new RuntimeException('Only INC rows can be resolved through INC resolution.');
            }

            $outcome = $replacementCode === 'INC'
                ? throw new RuntimeException('INC resolution requires a replacement or lapsed result.')
                : ($replacementCode === 'P' ? $this->policy->controlledOutcome('P') : $this->policy->outcomeForAverage($this->averageForNumericCode($replacementCode)));

            $locked->outcomeEvents()->create([
                'event_type' => GradeOutcomeEvent::TypeIncResolution,
                'previous_value' => null,
                'new_value' => $outcome['value'],
                'previous_category' => $locked->current_outcome_category,
                'new_category' => $outcome['category'],
                'deadline' => null,
                'authority' => $authority,
                'reason' => $reason,
                'evidence_reference' => $evidenceReference,
                'recorded_by' => $actor->id,
            ]);

            $locked->update([
                'current_outcome_code' => $outcome['code'],
                'current_outcome_category' => $outcome['category'],
            ]);

            return $locked->fresh('outcomeEvents');
        });
    }

    private function averageForNumericCode(string $code): float
    {
        foreach (config('grades.servitech_v1.scale') as $band) {
            if ((string) $band['code'] === $code) {
                return (float) $band['min'];
            }
        }

        throw new RuntimeException('Replacement grade must be a controlled numeric result or P.');
    }
}
