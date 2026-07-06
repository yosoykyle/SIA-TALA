<?php

namespace App\Actions\Enrollment;

use App\Models\Enrollment;
use App\Models\EnrollmentGateResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class EnrollmentGateReviewSummary
{
    /**
     * @return list<array{
     *     gate_type:string,
     *     label:string,
     *     sequence:int,
     *     result:string,
     *     result_label:string,
     *     result_color:string,
     *     responsible_office:string,
     *     office_label:string,
     *     blocker_code:string,
     *     blocker_message:string,
     *     source_reference:string,
     *     checked_at:string,
     *     is_recorded:bool
     * }>
     */
    public function rows(Enrollment $enrollment): array
    {
        $enrollment->loadMissing('gateResults');

        /** @var array<string, EnrollmentGateResult> $recordedByGate */
        $recordedByGate = [];

        foreach ($enrollment->gateResults->sortByDesc('sequence') as $gateResult) {
            if (! $gateResult instanceof EnrollmentGateResult) {
                continue;
            }

            $gateType = $gateResult->getAttribute('gate_type');

            if (is_string($gateType) && ! isset($recordedByGate[$gateType])) {
                $recordedByGate[$gateType] = $gateResult;
            }
        }

        return collect($this->expectedGates())
            ->map(function (array $expected) use ($recordedByGate): array {
                $recorded = $recordedByGate[$expected['gate_type']] ?? null;

                if ($recorded instanceof EnrollmentGateResult) {
                    return $this->recordedRow($expected, $recorded);
                }

                return [
                    ...$expected,
                    'result' => EnrollmentGateResult::ResultNotChecked,
                    'result_label' => 'Not Checked',
                    'result_color' => 'gray',
                    'blocker_code' => '-',
                    'blocker_message' => 'No source-backed gate result has been recorded yet.',
                    'source_reference' => '-',
                    'checked_at' => '-',
                    'is_recorded' => false,
                ];
            })
            ->values()
            ->all();
    }

    public function compactStatus(Enrollment $enrollment): string
    {
        $blockingRow = $this->blockingRow($enrollment);

        if ($blockingRow === null) {
            return 'All recorded gates clear';
        }

        if ($blockingRow['result'] === EnrollmentGateResult::ResultNotChecked) {
            return $blockingRow['label'].': Not checked';
        }

        return $blockingRow['label'].': '.$blockingRow['blocker_message'];
    }

    public function compactStatusColor(Enrollment $enrollment): string
    {
        $blockingRow = $this->blockingRow($enrollment);

        return $blockingRow['result_color'] ?? 'success';
    }

    public function compactResponsibleOffice(Enrollment $enrollment): string
    {
        $blockingRow = $this->blockingRow($enrollment);

        return $blockingRow['office_label'] ?? 'No blocking office';
    }

    /**
     * @return array<string, string|int>
     */
    private function recordedRow(array $expected, EnrollmentGateResult $recorded): array
    {
        $result = $recorded->result;
        $checkedAt = $recorded->getAttribute('checked_at');

        return [
            ...$expected,
            'result' => $result,
            'result_label' => $this->resultLabel($result),
            'result_color' => $this->resultColor($result),
            'responsible_office' => $recorded->responsible_office,
            'office_label' => $this->officeLabel($recorded->responsible_office),
            'blocker_code' => $recorded->blocker_code ?: '-',
            'blocker_message' => $recorded->blocker_message ?: $this->defaultResultMessage($result),
            'source_reference' => $this->sourceReference($recorded),
            'checked_at' => $checkedAt instanceof Carbon
                ? $checkedAt->toDateTimeString()
                : '-',
            'is_recorded' => true,
        ];
    }

    /**
     * @return array{
     *     gate_type:string,
     *     label:string,
     *     sequence:int,
     *     responsible_office:string,
     *     office_label:string
     * }
     */
    private function expectedGate(string $gateType, string $label, int $sequence, string $office): array
    {
        return [
            'gate_type' => $gateType,
            'label' => $label,
            'sequence' => $sequence,
            'responsible_office' => $office,
            'office_label' => $this->officeLabel($office),
        ];
    }

    /**
     * @return list<array{
     *     gate_type:string,
     *     label:string,
     *     sequence:int,
     *     responsible_office:string,
     *     office_label:string
     * }>
     */
    private function expectedGates(): array
    {
        return [
            $this->expectedGate(EnrollmentGateResult::GateIdentity, 'Identity', 1, EnrollmentGateResult::ResponsibleOfficeRegistrar),
            $this->expectedGate(EnrollmentGateResult::GateAdmissionOrStudentStatus, 'Admission / Student Status', 2, EnrollmentGateResult::ResponsibleOfficeRegistrar),
            $this->expectedGate(EnrollmentGateResult::GateDocument, 'Document', 3, EnrollmentGateResult::ResponsibleOfficeRegistrar),
            $this->expectedGate(EnrollmentGateResult::GateFinance, 'Finance', 4, EnrollmentGateResult::ResponsibleOfficeAccounting),
            $this->expectedGate(EnrollmentGateResult::GateAcademicProgression, 'Academic Progression', 5, EnrollmentGateResult::ResponsibleOfficeAcademicHead),
            $this->expectedGate(EnrollmentGateResult::GateCapacity, 'Capacity', 6, EnrollmentGateResult::ResponsibleOfficeRegistrar),
            $this->expectedGate(EnrollmentGateResult::GatePlacement, 'Placement', 7, EnrollmentGateResult::ResponsibleOfficeRegistrar),
            $this->expectedGate(EnrollmentGateResult::GateConflict, 'Conflict', 8, EnrollmentGateResult::ResponsibleOfficeRegistrar),
            $this->expectedGate(EnrollmentGateResult::GateFinalApproval, 'Final Approval', 9, EnrollmentGateResult::ResponsibleOfficeRegistrar),
        ];
    }

    /**
     * @return array{
     *     gate_type:string,
     *     label:string,
     *     sequence:int,
     *     result:string,
     *     result_label:string,
     *     result_color:string,
     *     responsible_office:string,
     *     office_label:string,
     *     blocker_code:string,
     *     blocker_message:string,
     *     source_reference:string,
     *     checked_at:string,
     *     is_recorded:bool
     * }|null
     */
    private function blockingRow(Enrollment $enrollment): ?array
    {
        $rows = collect($this->rows($enrollment));
        $recordedBlocker = $rows->first(fn (array $row): bool => in_array($row['result'], [
            EnrollmentGateResult::ResultFailed,
            EnrollmentGateResult::ResultPendingReview,
        ], true));

        if (is_array($recordedBlocker)) {
            return $recordedBlocker;
        }

        $notChecked = $rows->first(fn (array $row): bool => $row['result'] === EnrollmentGateResult::ResultNotChecked);

        return is_array($notChecked) ? $notChecked : null;
    }

    private function resultLabel(string $result): string
    {
        return match ($result) {
            EnrollmentGateResult::ResultPassed => 'Passed',
            EnrollmentGateResult::ResultFailed => 'Failed',
            EnrollmentGateResult::ResultPendingReview => 'Pending Review',
            EnrollmentGateResult::ResultNotChecked => 'Not Checked',
            default => Str::headline($result),
        };
    }

    private function resultColor(string $result): string
    {
        return match ($result) {
            EnrollmentGateResult::ResultPassed => 'success',
            EnrollmentGateResult::ResultFailed => 'danger',
            EnrollmentGateResult::ResultPendingReview => 'warning',
            EnrollmentGateResult::ResultNotChecked => 'gray',
            default => 'gray',
        };
    }

    private function officeLabel(string $office): string
    {
        return match ($office) {
            EnrollmentGateResult::ResponsibleOfficeRegistrar => 'Registrar Office',
            EnrollmentGateResult::ResponsibleOfficeAccounting => 'Accounting Office',
            EnrollmentGateResult::ResponsibleOfficeAcademicHead => 'Academic Head',
            default => Str::headline($office),
        };
    }

    private function defaultResultMessage(string $result): string
    {
        return match ($result) {
            EnrollmentGateResult::ResultPassed => 'Gate passed.',
            EnrollmentGateResult::ResultPendingReview => 'Gate requires office review.',
            EnrollmentGateResult::ResultFailed => 'Gate is blocking enrollment.',
            default => 'No source-backed gate result has been recorded yet.',
        };
    }

    private function sourceReference(EnrollmentGateResult $recorded): string
    {
        if (! filled($recorded->source_type) || ! filled($recorded->source_id)) {
            return '-';
        }

        return Str::headline(class_basename($recorded->source_type)).' #'.$recorded->source_id;
    }
}
