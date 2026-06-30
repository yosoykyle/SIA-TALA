<?php

namespace App\Http\Controllers;

use App\Actions\Finance\FinanceEvidenceService;
use App\Models\Assessment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BillingSlipController extends Controller
{
    public function __invoke(Request $request, Assessment $assessment, FinanceEvidenceService $evidence): View
    {
        $actor = $request->user();

        abort_unless($actor instanceof User, 401);

        $copyContext = $actor->can('process-payments')
            ? FinanceEvidenceService::CopyAccounting
            : FinanceEvidenceService::CopyStudent;
        $slip = $evidence->billingSlip($assessment, $actor, $copyContext);
        $action = $request->boolean('print')
            ? FinanceEvidenceService::ActionPrint
            : FinanceEvidenceService::ActionView;

        $evidence->recordAccess($slip, $actor, FinanceEvidenceService::OutputBillingSlip, $action, $request);

        return view('finance.billing-slip', [
            'slip' => $slip,
        ]);
    }
}
