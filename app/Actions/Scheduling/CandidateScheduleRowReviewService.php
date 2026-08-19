<?php

namespace App\Actions\Scheduling;

use App\Models\CandidateScheduleRow;
use App\Models\ScheduleGenerationRun;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class CandidateScheduleRowReviewService
{
    public function __construct(private readonly AdjustCandidateMeeting $adjustCandidateMeeting) {}

    /** @param array<string, mixed> $data */
    public function revise(
        CandidateScheduleRow $candidateRow,
        array $data,
        User $actor,
    ): ScheduleGenerationRun {
        $proposal = $this->validatedRevision($data);

        return $this->adjustCandidateMeeting->execute(
            requestedRow: $candidateRow,
            actor: $actor,
            assignment: collect($proposal)->except(['override_authority', 'override_reason'])->all(),
            reason: $proposal['override_reason'],
            authority: $proposal['override_authority'],
        );
    }

    /** @param list<array<string, mixed>> $assignments */
    public function replace(
        ScheduleGenerationRun $run,
        array $assignments,
        User $actor,
        string $authority,
        string $reason,
    ): ScheduleGenerationRun {
        throw ValidationException::withMessages([
            'assignments' => 'Generic Manual Schedule Override is retired. Use one-meeting candidate correction or explicit whole-Term repair.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{faculty_user_id:int,room_id:int|null,day_of_week:int,starts_at:string,ends_at:string,time_block_key:string|null,override_authority:string,override_reason:string}
     */
    private function validatedRevision(array $data): array
    {
        ['override_authority' => $authority, 'override_reason' => $reason] = $this->validatedEvidence(
            (string) ($data['override_authority'] ?? ''),
            (string) ($data['override_reason'] ?? ''),
        );
        $payload = [
            'faculty_user_id' => $data['faculty_user_id'] ?? null,
            'room_id' => $data['room_id'] ?? null,
            'day_of_week' => $data['day_of_week'] ?? null,
            'starts_at' => $this->timeValue($data['starts_at'] ?? null),
            'ends_at' => $this->timeValue($data['ends_at'] ?? null),
            'time_block_key' => filled($data['time_block_key'] ?? null) ? trim((string) $data['time_block_key']) : null,
            'override_authority' => $authority,
            'override_reason' => $reason,
        ];
        $validator = Validator::make($payload, [
            'faculty_user_id' => ['required', 'integer', 'exists:users,id'],
            'room_id' => ['nullable', 'integer', 'exists:rooms,id'],
            'day_of_week' => ['required', 'integer', 'min:1', 'max:7'],
            'starts_at' => ['required', 'date_format:H:i:s'],
            'ends_at' => ['required', 'date_format:H:i:s', 'after:starts_at'],
            'time_block_key' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        /** @var array{faculty_user_id:int,room_id:int|null,day_of_week:int,starts_at:string,ends_at:string,time_block_key:string|null,override_authority:string,override_reason:string} $payload */
        return $payload;
    }

    /** @return array{override_authority:string,override_reason:string} */
    private function validatedEvidence(string $authority, string $reason): array
    {
        $payload = [
            'override_authority' => trim($authority),
            'override_reason' => trim($reason),
        ];
        $validator = Validator::make($payload, [
            'override_authority' => ['required', 'string', 'max:255'],
            'override_reason' => ['required', 'string', 'max:2000'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $payload;
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
