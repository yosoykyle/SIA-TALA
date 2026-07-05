<?php

namespace App\Http\Controllers;

use App\Actions\Finance\FinanceEvidenceService;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PaymentAcknowledgementController extends Controller
{
    public function __invoke(Request $request, Payment $payment, FinanceEvidenceService $evidence): View
    {
        $actor = $request->user();

        abort_unless($actor instanceof User, 401);

        $copyContext = $actor->canProcessPayments()
            ? FinanceEvidenceService::CopyAccounting
            : FinanceEvidenceService::CopyStudent;
        $acknowledgement = $evidence->paymentAcknowledgement($payment, $actor, $copyContext);
        $action = $request->boolean('print')
            ? FinanceEvidenceService::ActionPrint
            : FinanceEvidenceService::ActionView;

        $evidence->recordAccess($acknowledgement, $actor, FinanceEvidenceService::OutputPaymentAcknowledgement, $action, $request);

        return view('finance.payment-acknowledgement', [
            'acknowledgement' => $acknowledgement,
        ]);
    }
}
