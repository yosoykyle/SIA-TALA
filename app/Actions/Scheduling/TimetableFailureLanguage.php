<?php

namespace App\Actions\Scheduling;

/**
 * Single source of plain-language text for timetable generation failures (#80).
 *
 * Maps the technical messages and outcome codes produced by ScheduleGenerationService,
 * CloudRunSchedulingSolverClient and ScheduleGenerationRun::reviewEvidence() to wording a
 * non-technical Registrar can act on. Raw values stay available in diagnostics for a
 * collapsed "Technical details" section; this class never changes what is recorded, only
 * how it is presented.
 */
class TimetableFailureLanguage
{
    /** @return array{title: string, explanation: string, nextStep: string} */
    public static function fallback(): array
    {
        return [
            'title' => 'Timetable could not be generated',
            'explanation' => 'Something went wrong while generating the timetable.',
            'nextStep' => 'Please try again or contact your administrator.',
        ];
    }

    /**
     * Maps the "generation" validation message thrown by ScheduleGenerationService::assertRerunAllowed().
     *
     * @return array{title: string, explanation: string, nextStep: string}
     */
    public static function forBlockedGeneration(string $rawMessage): array
    {
        return match ($rawMessage) {
            'Review or reject the retained candidate, or change its source facts before generating again.' => [
                'title' => 'A previous draft needs your decision',
                'explanation' => 'A previous timetable draft is still waiting for your decision.',
                'nextStep' => 'Approve or reject that draft, or change the schedule inputs, before generating a new one.',
            ],
            'Correct the conflicting source facts before generating again.' => [
                'title' => 'Timetable could not be generated',
                'explanation' => 'The schedule data has conflicting requirements, so no timetable could be created.',
                'nextStep' => 'Review and correct the conflicting class, room, or faculty requirements, then try again.',
            ],
            'Correct the solver model defect before generating again.' => [
                'title' => 'Timetable could not be generated',
                'explanation' => 'The scheduling service could not understand the schedule data it was given.',
                'nextStep' => 'Contact your system administrator to review the schedule data for errors.',
            ],
            'Correct the recorded solver response or integration failure before generating again.' => [
                'title' => 'Timetable could not be generated',
                'explanation' => 'The scheduling service did not return a usable result, so no timetable was created. Your schedule data is not the problem.',
                'nextStep' => 'Try again. If this keeps happening, contact your system administrator.',
            ],
            'Retry a retryable failure, correct the solver integration, or correct the source facts before generating again.' => [
                'title' => 'Timetable could not be generated',
                'explanation' => 'The scheduling service could not finish the request, so no timetable was created.',
                'nextStep' => 'Try generating again. If this keeps happening, contact your system administrator.',
            ],
            default => self::fallback(),
        };
    }

    /**
     * Maps a scheduling-solver transport failure message (CloudRunSchedulingSolverClient, finalSolverFailure()).
     *
     * @return array{title: string, explanation: string, nextStep: string}
     */
    public static function forSolverFailureMessage(?string $rawMessage): array
    {
        return match ($rawMessage) {
            'Scheduling solver authentication failed.' => [
                'title' => 'Scheduling service connection problem',
                'explanation' => "The scheduling service rejected the system's login, so no timetable was created. Your schedule data is not the problem.",
                'nextStep' => 'Please contact your system administrator to fix the scheduling service connection.',
            ],
            'Scheduling solver URL is not configured.',
            'Scheduling solver request failed.',
            'Scheduling solver probe failed.' => [
                'title' => 'Scheduling service connection problem',
                'explanation' => 'The system could not reach the scheduling service, so no timetable was created. Your schedule data is not the problem.',
                'nextStep' => 'Please contact your system administrator to fix the scheduling service connection.',
            ],
            'Scheduling solver did not return a JSON object.' => [
                'title' => 'Scheduling service returned an unexpected response',
                'explanation' => 'The scheduling service sent back a response the system could not understand, so no timetable was created.',
                'nextStep' => 'Try again. If this keeps happening, contact your system administrator.',
            ],
            default => self::fallback(),
        };
    }

    /**
     * Maps the "outcome" key produced by ScheduleGenerationRun::reviewEvidence() to a plain label and explanation.
     *
     * @return array{label: string, explanation: string}
     */
    public static function forOutcome(string $outcome): array
    {
        return match ($outcome) {
            'Optimal' => [
                'label' => 'Best possible timetable found',
                'explanation' => 'The system found the best possible timetable for the given schedule data.',
            ],
            'Feasible' => [
                'label' => 'A working timetable was found',
                'explanation' => 'The system found a timetable that works, though not necessarily the best one. A reason is needed before publishing it.',
            ],
            'Infeasible' => [
                'label' => 'No valid timetable found',
                'explanation' => 'The system could not find any timetable that satisfies every requirement. Review and correct the conflicting schedule data.',
            ],
            'Unknown' => [
                'label' => 'No result found',
                'explanation' => 'The system did not find a timetable or confirm that none exists. You may try generating again.',
            ],
            'ModelInvalid' => [
                'label' => 'Schedule data could not be understood',
                'explanation' => 'The scheduling service could not understand the schedule data it was given. Contact your system administrator.',
            ],
            'TechnicalFailure' => [
                'label' => 'Scheduling service problem',
                'explanation' => 'The scheduling service did not return a usable result, so no timetable was created. Your schedule data is not the problem.',
            ],
            'Local adjustment' => [
                'label' => 'Manually adjusted',
                'explanation' => 'This result was manually adjusted and checked for conflicts. Its overall quality has not been measured.',
            ],
            'Generating' => [
                'label' => 'Still generating',
                'explanation' => 'The timetable request is still being processed. Check back shortly.',
            ],
            default => [
                'label' => 'Result unavailable',
                'explanation' => 'No result is available yet for this request.',
            ],
        };
    }

    public static function meetingCompletenessNotEstablished(): string
    {
        return 'The system could not confirm that every class meeting was placed in the timetable.';
    }

    public static function conflictChecksNotEstablished(): string
    {
        return 'The system could not confirm that the timetable has no conflicts (such as the same room or teacher being double-booked).';
    }
}
