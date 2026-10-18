<?php

namespace App\Actions\Scheduling;

use App\Models\ScheduleGenerationRun;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ScheduleSolverRecoveryService
{
    public function contextFingerprint(): string
    {
        $driver = (string) config('tala_integrations.scheduling_solver.driver', 'local_stub');
        $context = ['driver' => $driver, 'solver_version' => ScheduleGenerationRun::SolverVersion];

        if (in_array($driver, ['cloud_run', 'local_http'], true)) {
            foreach (['url', 'timeout_seconds', 'connect_timeout_seconds'] as $key) {
                $context[$key] = config('tala_integrations.scheduling_solver.'.$key);
            }
        }

        if ($driver === 'cloud_run') {
            $path = (string) config('tala_integrations.scheduling_solver.credentials_path');
            $context['audience'] = config('tala_integrations.scheduling_solver.audience');
            $context['credentials_path'] = $path;
            $context['credentials_digest'] = is_file($path) && is_readable($path) ? hash_file('sha256', $path) : null;
        }

        return hash_hmac('sha256', json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), (string) config('app.key'));
    }

    public function canRecover(ScheduleGenerationRun $run): bool
    {
        if (! $this->hasRecoverableTechnicalFailure($run)) {
            return false;
        }

        $captured = data_get($run->diagnostics, 'solver_dispatch.technical_context_fingerprint');

        if (is_string($captured) && $captured !== '') {
            return ! hash_equals($captured, $this->contextFingerprint());
        }

        $previousDriver = data_get($run->diagnostics, 'solver_dispatch.driver');

        return in_array($previousDriver, ['cloud_run', 'local_http', 'local_stub'], true)
            && $previousDriver !== config('tala_integrations.scheduling_solver.driver', 'local_stub');
    }

    public function canRequestRecovery(ScheduleGenerationRun $run): bool
    {
        return $run->contract_version === ScheduleGenerationRun::ContractVersion
            && $this->hasRecoverableTechnicalFailure($run)
            && ! $run->canRetrySolver()
            && ! ScheduleGenerationRun::query()->where('term_id', $run->term_id)->where('id', '>', $run->id)->exists();
    }

    /** @return array{source_run_id:int,requested_by:int,requested_at:string,correction_explanation:string} */
    public function recoveryEvidence(ScheduleGenerationRun $source, User $actor, int $termId, ?string $explanation): array
    {
        $locked = ScheduleGenerationRun::query()->lockForUpdate()->findOrFail($source->id);
        Gate::forUser($actor)->authorize('recover', $locked);

        if ((int) $locked->term_id !== $termId) {
            throw ValidationException::withMessages(['recovery_source' => 'Recovery must use the failed request for this exact Term.']);
        }

        $validated = Validator::make(['correction_explanation' => trim((string) $explanation)], [
            'correction_explanation' => ['required', 'string', 'max:2000'],
        ])->validate();

        return ['source_run_id' => (int) $locked->id, 'requested_by' => (int) $actor->id,
            'requested_at' => now()->toIso8601String(), 'correction_explanation' => $validated['correction_explanation']];
    }

    private function hasRecoverableTechnicalFailure(ScheduleGenerationRun $run): bool
    {
        $failure = $run->finalSolverFailure();
        $technicalFailure = ($run->status === ScheduleGenerationRun::StatusFailed
                && ($failure['final'] ?? false) === true
                && in_array($failure['classification'] ?? null, ['connection', 'timeout', 'rate_limited', 'server_error', 'client_error', 'configuration', 'credential', 'malformed_response', 'malformed_request', 'request_budget_exhausted', 'unexpected'], true)
                && ! in_array(strtoupper((string) ($failure['code'] ?? '')), ['INFEASIBLE', 'MODEL_INVALID', 'UNKNOWN'], true)
                && data_get($run->diagnostics, 'solver_result') === null)
            || ($run->status === ScheduleGenerationRun::StatusBlocked
                && data_get($run->diagnostics, 'solver_result.summary.outcome') === 'TechnicalFailure');

        return $technicalFailure
            && blank($run->candidate_key)
            && $run->published_at === null
            && ! in_array($run->candidate_state, ['Accepted', 'Rejected', 'Superseded'], true)
            && ! $run->candidateRows()->exists()
            && ! $run->sectionMeetings()->exists()
            && ! $run->timetableVersions()->exists();
    }
}
