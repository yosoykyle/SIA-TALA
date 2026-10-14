<?php

namespace App\Actions\Admissions;

use App\Models\AdmissionApplication;
use App\Models\AdmissionCycle;
use App\Models\Term;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class ResolveAdmissionEntryCycle
{
    public function resolve(int $programId, string $studentType): AdmissionCycle
    {
        if (! in_array($studentType, [AdmissionApplication::PathFirstYear, AdmissionApplication::PathTransferee], true)) {
            throw ValidationException::withMessages(['application_path' => 'Choose Freshman or Transferee.']);
        }
        $flag = $studentType === AdmissionApplication::PathFirstYear ? 'accepts_first_year' : 'accepts_transferee';
        $cycles = AdmissionCycle::query()->where('state', AdmissionCycle::StatePublished)
            ->whereHas('term', fn (Builder $query): Builder => $query->where('state', Term::StateActive))
            ->where('opens_at', '<=', now())->where('closes_at', '>', now())
            ->whereHas('programs', fn (Builder $query): Builder => $query->whereKey($programId)->where('is_active', true)->where('admission_cycle_program.'.$flag, true))
            ->whereHas('requirementSets', fn (Builder $query): Builder => $query->where('application_path', $studentType)->where('state', 'Published')->where('effective_at', '<=', now()))
            ->limit(2)->get();
        if ($cycles->count() !== 1) {
            throw ValidationException::withMessages(['program_id' => $cycles->isEmpty()
                ? 'This Program and Student type have no open intake. Contact the Registrar or choose another accepting Program.'
                : 'The school must confirm the intake for this Program and Student type. Contact the Registrar; your answers remain here.']);
        }

        return $cycles->sole();
    }
}
