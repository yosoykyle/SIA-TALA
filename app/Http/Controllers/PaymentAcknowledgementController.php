<?php

namespace App\Http\Controllers;

use App\Actions\Finance\FinanceEvidenceService;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class PaymentAcknowledgementController extends Controller
{
    public function __invoke(Request $request, Payment $payment, FinanceEvidenceService $evidence): View
    {
        Gate::authorize('viewAcknowledgement', $payment);

        return view('finance.payment-acknowledgement', $evidence->paymentAcknowledgement($payment));
    }
}
