<?php

namespace App\Http\Controllers;

use App\Actions\Scheduling\BuildTimetableVersionOutput;
use App\Filament\Pages\TermTimetable;
use App\Models\PublishedTimetableVersion;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TimetableVersionPrintController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(
        Request $request,
        PublishedTimetableVersion $version,
        BuildTimetableVersionOutput $output,
    ): View {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return view('schedules.print', [
            'schedule' => [
                ...$output->execute($version, $actor, $request),
                'back_url' => TermTimetable::getUrl(['termId' => $version->term_id, 'selectedVersionId' => $version->id]),
                'back_label' => 'Return to official timetable',
            ],
        ]);
    }
}
