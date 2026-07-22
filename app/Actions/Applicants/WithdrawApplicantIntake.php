<?php

namespace App\Actions\Applicants;

use App\Models\ApplicantIntake;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WithdrawApplicantIntake
{
    public function execute(ApplicantIntake $intake, User $applicant): ApplicantIntake
    {
        if ($intake->user_id !== $applicant->id
            || ! $applicant->hasRole('applicant')
            || ! $applicant->canAuthenticate()) {
            throw new AuthorizationException('Applicants may withdraw only their own application.');
        }

        return DB::transaction(function () use ($intake, $applicant): ApplicantIntake {
            $locked = ApplicantIntake::query()->lockForUpdate()->findOrFail($intake->id);

            if (! in_array($locked->status, [ApplicantIntake::StatusDraft, ApplicantIntake::StatusPending], true)
                || $locked->reviewed_at !== null
                || $locked->handed_over_at !== null) {
                throw ValidationException::withMessages([
                    'status' => 'This application can no longer be withdrawn online. Contact the Registrar for assistance.',
                ]);
            }

            $timestamp = CarbonImmutable::now(config('app.timezone'));
            $before = $locked->status;
            $locked->forceFill([
                'status' => ApplicantIntake::StatusWithdrawn,
                'archived_at' => $timestamp,
            ])->save();
            $applicant->forceFill(['status' => User::StatusApplicantWithdrawn])->save();

            DB::table('activity_log')->insert([
                'log_name' => 'applicant_intake',
                'description' => 'Applicant withdrew their intake before completed staff review.',
                'subject_type' => ApplicantIntake::class,
                'subject_id' => $locked->id,
                'event' => 'applicant_intake_withdrawn',
                'causer_type' => User::class,
                'causer_id' => $applicant->id,
                'properties' => json_encode([
                    'status_before' => $before,
                    'status_after' => ApplicantIntake::StatusWithdrawn,
                ], JSON_UNESCAPED_SLASHES),
                'created_at' => $timestamp->toDateTimeString(),
                'updated_at' => $timestamp->toDateTimeString(),
            ]);

            return $locked->refresh();
        }, attempts: 3);
    }
}
