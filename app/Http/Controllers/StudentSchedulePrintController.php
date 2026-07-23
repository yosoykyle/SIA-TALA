<?php

namespace App\Http\Controllers;

use App\Actions\Scheduling\BuildOfficialScheduleOutput;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StudentSchedulePrintController extends Controller
{
    public function __invoke(Request $request, BuildOfficialScheduleOutput $output): View
    {
        $student = $request->user();

        abort_unless($student instanceof User, 401);

        return view('schedules.print', [
            'schedule' => $output->forStudent($student, $request),
        ]);
    }
}
