<?php

namespace App\Http\Controllers;

use App\Actions\Finance\CanonicalFinanceOutputPresenter;
use App\Models\Assessment;
use App\Models\OutputAccessLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FinanceStatementController extends Controller
{
    public function __invoke(Request $request, Assessment $assessment, CanonicalFinanceOutputPresenter $outputs): View
    {
        $actor = $request->user();

        abort_unless($actor instanceof User, 401);

        $statement = $outputs->statement($assessment, $actor);
        OutputAccessLog::query()->create([
            'output_type' => 'SOA', 'source_record_type' => Assessment::class, 'source_record_id' => $assessment->id,
            'student_profile_id' => $assessment->enrollment?->student_profile_id, 'actor_user_id' => $actor->id,
            'actor_role' => $actor->getRoleNames()->first(), 'action' => $request->boolean('print') ? 'PRINT' : 'VIEW',
            'copy_context' => $actor->hasRole(User::StaffRoleAccounting) ? 'ACCOUNTING_COPY' : 'LEARNER_COPY',
            'request_context' => ['route' => $request->route()?->getName()], 'status' => 'logged', 'occurred_at' => now(),
        ]);

        return view('finance.statement', [
            'statement' => $statement,
        ]);
    }
}
