<?php

namespace App\Actions\Grades;

use App\Models\GradeOutcomeEvent;
use App\Models\GradeRosterRow;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RecordApprovedGradeCorrection
{
    public function __construct(private readonly GradePolicyService $policy) {}

    public function execute(GradeRosterRow $row, string $correctedCode, string $authority, string $reason, ?string $evidenceReference, User $actor): GradeRosterRow
    {
        return DB::transaction(function () use ($row, $correctedCode, $authority, $reason, $evidenceReference, $actor): GradeRosterRow {
            $locked = GradeRosterRow::query()->lockForUpdate()->findOrFail($row->id);

            if ($locked->released_at === null) {
                throw new RuntimeException('Only released rows can receive posted corrections.');
            }

            $outcome = match (strtoupper($correctedCode)) {
                'P', 'INC' => $this->policy->controlledOutcome($correctedCode),
                default => $this->policy->outcomeForAverage($this->averageForNumericCode($correctedCode)),
            };

            $locked->outcomeEvents()->create([
                'event_type' => GradeOutcomeEvent::TypePostedCorrection,
                'previous_value' => is_numeric($locked->current_outcome_code) ? (float) $locked->current_outcome_code : null,
                'new_value' => $outcome['value'],
                'previous_category' => $locked->current_outcome_category,
                'new_category' => $outcome['category'],
                'deadline' => $outcome['code'] === 'INC' ? $this->policy->incDeadline()->toDateString() : null,
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

        throw new RuntimeException('Corrected grade must be a controlled numeric result, P, or INC.');
    }
}
