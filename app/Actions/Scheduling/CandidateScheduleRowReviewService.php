<?php

namespace App\Actions\Scheduling;

use App\Models\CandidateScheduleRow;
use App\Models\ScheduleGenerationRun;
use App\Models\SectionMeeting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CandidateScheduleRowReviewService
{
    public function __construct(private readonly ScheduleCloudResultIngestor $resultIngestor) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function revise(CandidateScheduleRow $draftRow, array $data, User $registrar): ScheduleGenerationRun
    {
        $this->authorizeRegistrar($registrar);
        $prepared = $this->validatedPayload($data);

        return DB::transaction(function () use ($draftRow, $prepared, $registrar): ScheduleGenerationRun {
            /** @var CandidateScheduleRow $lockedRow */
            $lockedRow = CandidateScheduleRow::query()
                ->with('generationRun')
                ->lockForUpdate()
                ->findOrFail($draftRow->id);

            $run = $lockedRow->generationRun;

            if (! $run instanceof ScheduleGenerationRun || in_array($run->status, [
                ScheduleGenerationRun::StatusCommitted,
                ScheduleGenerationRun::StatusPublished,
                ScheduleGenerationRun::StatusAbandoned,
                ScheduleGenerationRun::StatusSuperseded,
            ], true)) {
                throw ValidationException::withMessages([
                    'status' => 'This schedule run can no longer be edited.',
                ]);
            }

            $draftRows = CandidateScheduleRow::query()
                ->where('generation_run_id', $lockedRow->generation_run_id)
                ->orderBy('id')
                ->get();

            $solverResult = [
                'solver_status' => 'optimal',
                'draft_rows' => $draftRows
                    ->map(fn (CandidateScheduleRow $row): array => $row->id === $lockedRow->id
                        ? $this->reviewedRowPayload($row, $prepared)
                        : $this->existingRowPayload($row))
                    ->values()
                    ->all(),
            ];

            $this->resultIngestor->ingest($run, $solverResult);

            $reviewedRow = $this->findReviewedRow($lockedRow, $prepared);
            $timestamp = CarbonImmutable::now(config('app.timezone'));

            if ($reviewedRow instanceof CandidateScheduleRow) {
                $reviewedRow->forceFill([
                    'override_reason' => $prepared['override_reason'],
                    'edited_by' => $registrar->id,
                    'edited_at' => $timestamp,
                ])->save();
            }

            return $run->fresh();
        });
    }

    private function authorizeRegistrar(User $registrar): void
    {
        if ($registrar->can('manage-schedules')) {
            return;
        }

        throw new AuthorizationException('Only authorized Registrar staff can review schedule draft rows.');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{section_delivery_group_id:int, faculty_id:int, room:string|null, day_of_week:int, starts_at:string, ends_at:string, modality:string, override_reason:string}
     *
     * @throws ValidationException
     */
    private function validatedPayload(array $data): array
    {
        $payload = [
            'faculty_id' => $this->integerValue($data['faculty_id'] ?? null),
            'section_delivery_group_id' => $this->integerValue($data['section_delivery_group_id'] ?? null),
            'room' => filled($data['room'] ?? null) ? trim((string) $data['room']) : null,
            'day_of_week' => $this->integerValue($data['day_of_week'] ?? null),
            'starts_at' => $this->timeValue($data['starts_at'] ?? null),
            'ends_at' => $this->timeValue($data['ends_at'] ?? null),
            'modality' => filled($data['modality'] ?? null) ? trim((string) $data['modality']) : null,
            'override_reason' => filled($data['override_reason'] ?? null) ? trim((string) $data['override_reason']) : null,
        ];

        $validator = Validator::make($payload, [
            'faculty_id' => ['required', 'integer', 'exists:users,id'],
            'section_delivery_group_id' => ['required', 'integer', 'exists:section_delivery_groups,id'],
            'room' => ['nullable', 'string', 'max:255'],
            'day_of_week' => ['required', 'integer', 'min:1', 'max:7'],
            'starts_at' => ['required', 'date_format:H:i:s'],
            'ends_at' => ['required', 'date_format:H:i:s', 'after:starts_at'],
            'modality' => ['required', 'string', Rule::in(array_keys(SectionMeeting::modalityOptions()))],
            'override_reason' => ['required', 'string', 'max:500'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        /** @var array{section_delivery_group_id:int, faculty_id:int, room:string|null, day_of_week:int, starts_at:string, ends_at:string, modality:string, override_reason:string} $payload */
        return $payload;
    }

    /**
     * @param  array{section_delivery_group_id:int, faculty_id:int, room:string|null, day_of_week:int, starts_at:string, ends_at:string, modality:string, override_reason:string}  $prepared
     * @return array<string, mixed>
     */
    private function reviewedRowPayload(CandidateScheduleRow $row, array $prepared): array
    {
        return [
            'section_id' => $row->section_id,
            'section_delivery_group_id' => $prepared['section_delivery_group_id'],
            'subject_id' => $row->subject_id,
            'faculty_id' => $prepared['faculty_id'],
            'room' => $prepared['room'],
            'day_of_week' => $prepared['day_of_week'],
            'starts_at' => $prepared['starts_at'],
            'ends_at' => $prepared['ends_at'],
            'modality' => $prepared['modality'],
            'status' => CandidateScheduleRow::StatusWarning,
            'warning_payload' => [
                [
                    'type' => 'registrar_manual_revision',
                    'message' => 'Registrar manually revised this draft row during schedule review.',
                    'reason' => $prepared['override_reason'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function existingRowPayload(CandidateScheduleRow $row): array
    {
        return [
            'section_id' => $row->section_id,
            'section_delivery_group_id' => $row->section_delivery_group_id,
            'subject_id' => $row->subject_id,
            'faculty_id' => $row->faculty_id,
            'room' => $row->room,
            'day_of_week' => $row->day_of_week,
            'starts_at' => $this->timeValue($row->starts_at),
            'ends_at' => $this->timeValue($row->ends_at),
            'modality' => $row->modality,
            'status' => $row->status,
            'conflict_payload' => $this->payloadItems($row->conflict_payload),
            'warning_payload' => $this->payloadItems($row->warning_payload),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function payloadItems(?array $payload): array
    {
        $items = $payload['items'] ?? $payload ?? [];

        return is_array($items) ? array_values($items) : [];
    }

    /**
     * @param  array{section_delivery_group_id:int, faculty_id:int, room:string|null, day_of_week:int, starts_at:string, ends_at:string, modality:string, override_reason:string}  $prepared
     */
    private function findReviewedRow(CandidateScheduleRow $originalRow, array $prepared): ?CandidateScheduleRow
    {
        return CandidateScheduleRow::query()
            ->where('generation_run_id', $originalRow->generation_run_id)
            ->where('section_id', $originalRow->section_id)
            ->where('section_delivery_group_id', $prepared['section_delivery_group_id'])
            ->where('subject_id', $originalRow->subject_id)
            ->where('faculty_id', $prepared['faculty_id'])
            ->where('day_of_week', $prepared['day_of_week'])
            ->where('starts_at', $prepared['starts_at'])
            ->where('ends_at', $prepared['ends_at'])
            ->where('modality', $prepared['modality'])
            ->first();
    }

    private function integerValue(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function timeValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $time = (string) $value;

        return strlen($time) === 5 ? $time.':00' : $time;
    }
}
