<?php

namespace App\Http\Controllers;

use App\Actions\Finance\FinanceEvidenceService;
use App\Models\Enrollment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class FinanceStatementController extends Controller
{
    public function __invoke(Request $request, Enrollment $enrollment, FinanceEvidenceService $evidence): View
    {
        Gate::authorize('viewStatement', $enrollment);

        return view('finance.statement', $evidence->statement($enrollment));
    }
}
