<?php

namespace App\Http\Controllers;

use App\Actions\Finance\CanonicalFinanceOutputPresenter;
use App\Models\OutputAccessLog;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PaymentAcknowledgementController extends Controller
{
    public function __invoke(Request $request, Payment $payment, CanonicalFinanceOutputPresenter $outputs): View
    {
        $actor = $request->user();

        abort_unless($actor instanceof User, 401);

        $acknowledgement = $outputs->acknowledgement($payment, $actor);
        OutputAccessLog::query()->create([
            'output_type' => 'PAYMENT_ACKNOWLEDGEMENT', 'source_record_type' => Payment::class, 'source_record_id' => $payment->id,
            'student_profile_id' => $payment->student_profile_id, 'actor_user_id' => $actor->id,
            'actor_role' => $actor->getRoleNames()->first(), 'action' => $request->boolean('print') ? 'PRINT' : 'VIEW',
            'copy_context' => $actor->hasRole(User::StaffRoleAccounting) ? 'ACCOUNTING_COPY' : 'LEARNER_COPY',
            'request_context' => ['route' => $request->route()?->getName()], 'status' => 'logged', 'occurred_at' => now(),
        ]);

        return view('finance.payment-acknowledgement', [
            'acknowledgement' => $acknowledgement,
        ]);
    }
}
