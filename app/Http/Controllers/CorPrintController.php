<?php

namespace App\Http\Controllers;

use App\Actions\Cor\BuildCorOutput;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CorPrintController extends Controller
{
    public function __invoke(Request $request, Enrollment $enrollment, BuildCorOutput $output): View
    {
        $actor = $request->user();

        abort_unless($actor instanceof User, 401);

        $copyContext = $actor->hasRole(User::StaffRoleAccounting)
            ? BuildCorOutput::CopyAccounting
            : ($actor->hasRole(User::StaffRoleRegistrar) ? BuildCorOutput::CopyRegistrar : BuildCorOutput::CopyStudent);

        $cor = $output->forEnrollment($enrollment, $actor, $copyContext, true);

        abort_if(($cor['available'] ?? false) !== true, 403, (string) ($cor['reason'] ?? 'COR is not available.'));

        $output->recordAccess($cor, $actor, BuildCorOutput::ActionPrint, $request);

        return view('cor.print', [
            'cor' => $cor,
        ]);
    }
}
