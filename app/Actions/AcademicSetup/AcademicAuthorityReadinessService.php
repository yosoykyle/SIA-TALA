<?php

namespace App\Actions\AcademicSetup;

use App\Models\ProgramAuthority;
use Carbon\CarbonImmutable;

final class AcademicAuthorityReadinessService
{
    /**
     * @return array{ready: bool, blockers: list<array{code: string, source: string, owner: string, reason: string, next_action: string, recovery: string}>}
     */
    public function for(ProgramAuthority $authority): array
    {
        $blockers = [];

        if (blank($authority->authority_reference) || blank($authority->regulator)) {
            $blockers[] = $this->blocker(
                'academic_authority_missing',
                'Program authority',
                'Registrar',
                'The external approval reference and regulator are required.',
                'Record the approved authority reference and regulator.',
                'Correct the Draft authority record and retry activation.',
            );
        }

        if (blank($authority->curriculum_source_reference)) {
            $blockers[] = $this->blocker(
                'curriculum_source_missing',
                'Program authority',
                'Registrar',
                'The applicable curriculum source is not attributable.',
                'Record the approved curriculum source reference.',
                'Correct the Draft authority record and retry activation.',
            );
        }

        if ($authority->effective_until !== null
            && CarbonImmutable::parse((string) $authority->effective_until)
                ->lt(CarbonImmutable::parse((string) $authority->effective_from))) {
            $blockers[] = $this->blocker(
                'authority_dates_invalid',
                'Program authority',
                'Registrar',
                'The authority end date is earlier than its effective date.',
                'Correct the effective dates.',
                'Retain the Draft record until its date bounds are valid.',
            );
        }

        return ['ready' => $blockers === [], 'blockers' => $blockers];
    }

    /** @return array{code: string, source: string, owner: string, reason: string, next_action: string, recovery: string} */
    private function blocker(
        string $code,
        string $source,
        string $owner,
        string $reason,
        string $nextAction,
        string $recovery,
    ): array {
        return compact('code', 'source', 'owner', 'reason') + [
            'next_action' => $nextAction,
            'recovery' => $recovery,
        ];
    }
}
