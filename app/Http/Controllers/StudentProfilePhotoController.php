<?php

namespace App\Http\Controllers;

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

        $path = str_replace('\\', '/', (string) $actor->avatar_path);
        abort_unless(str_starts_with($path, 'profile-photos/'.$actor->id.'/')
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
