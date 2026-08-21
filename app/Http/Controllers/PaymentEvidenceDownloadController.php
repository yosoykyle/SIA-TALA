<?php

namespace App\Http\Controllers;

use App\Models\PaymentEvidenceVersion;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class PaymentEvidenceDownloadController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, PaymentEvidenceVersion $evidence): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize('view', $evidence);
        abort_unless($evidence->disk === 'local', 404);

        $path = str_replace('\\', '/', (string) $evidence->path);
        $prefix = 'registration-payment-evidence/'.(int) $evidence->term_account_id.'/';
        abort_unless(str_starts_with($path, $prefix)
            && ! str_contains($path, '../')
            && Storage::disk('local')->exists($path), 404);

        DB::table('activity_log')->insert([
            'log_name' => 'registration_finance',
            'description' => 'Private payment evidence accessed.',
            'subject_type' => PaymentEvidenceVersion::class,
            'subject_id' => $evidence->id,
            'event' => 'payment_evidence_accessed',
            'causer_type' => User::class,
            'causer_id' => $actor->id,
            'properties' => json_encode(['term_account_id' => $evidence->term_account_id], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $extension = match ($evidence->mime_type) {
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            default => 'bin',
        };

        return response(Storage::disk('local')->get($path), 200, [
            'Content-Type' => $evidence->mime_type,
            'Content-Disposition' => sprintf('attachment; filename="payment-evidence-%d.%s"', $evidence->id, $extension),
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
