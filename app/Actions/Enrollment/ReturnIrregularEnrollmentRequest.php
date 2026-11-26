<?php

namespace App\Actions\Enrollment;

use App\Models\IrregularEnrollmentRequest;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Registrar returns a student's irregular enrollment request so the student can correct it (#99 Scope 11).
 */
class ReturnIrregularEnrollmentRequest
{
    public function execute(IrregularEnrollmentRequest $request, User $actor, string $reason): IrregularEnrollmentRequest
    {
        if (! $actor->hasRole(User::StaffRoleRegistrar)) {
            throw ValidationException::withMessages(['request' => 'Only the Registrar can return an irregular enrollment request.']);
        }
        if ($request->state !== IrregularEnrollmentRequest::StateSubmitted) {
            throw ValidationException::withMessages(['request' => 'Only a submitted request can be returned for correction.']);
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['return_reason' => 'Tell the student what to correct.']);
        }

        $request->update([
            'state' => IrregularEnrollmentRequest::StateReturned,
            'returned_at' => now(),
            'returned_by' => $actor->id,
            'return_reason' => trim($reason),
        ]);

        return $request->refresh();
    }
}
