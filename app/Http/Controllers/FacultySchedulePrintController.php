<?php

namespace App\Http\Controllers;

use App\Actions\Scheduling\BuildOfficialScheduleOutput;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FacultySchedulePrintController extends Controller
{
    public function __invoke(Request $request, BuildOfficialScheduleOutput $output): View
    {
        $faculty = $request->user();

        abort_unless($faculty instanceof User, 401);

        return view('schedules.print', [
            'schedule' => $output->forFaculty($faculty, $request),
        ]);
    }
}
