<?php

namespace App\Http\Controllers;

use App\Actions\Grades\CurrentClassRosterOutput;
use App\Models\GradeRoster;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class StudentProfilePhotoController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && $actor->hasRole('student')
            && $actor->canAuthenticate() && $actor->hasAccessibleStudentProfile(), 403);

        return $this->photo($actor);
    }

    public function forRoster(Request $request, GradeRoster $roster, int $row): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && $actor->canAuthenticate() && $actor->can('view', $roster), 403);
        $snapshot = app(CurrentClassRosterOutput::class)->membershipSnapshot($roster, $actor);
        abort_unless($snapshot['isCurrent'], 409);
        $member = $snapshot['roster']->rows->firstWhere('id', $row);
        abort_if($member === null, 404);
        $student = $member->courseEnrollment?->enrollment?->studentProfile?->user;
        abort_unless($student instanceof User && $student->hasRole('student')
            && $student->canAuthenticate() && $student->hasAccessibleStudentProfile(), 404);

        return $this->photo($student);
    }

    private function photo(User $student): Response
    {
        $path = str_replace('\\', '/', (string) $student->avatar_path);
        abort_unless(str_starts_with($path, 'profile-photos/'.$student->id.'/')
            && ! str_contains($path, '../') && Storage::disk('local')->exists($path), 404);

        $contents = Storage::disk('local')->get($path);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents);
        abort_unless(in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true), 415);

        return response($contents, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="profile-photo"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
