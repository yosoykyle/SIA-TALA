<?php

namespace App\Http\Controllers;

use App\Models\FinanceExport;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FinanceExportDownloadController extends Controller
{
    public function __invoke(Request $request, FinanceExport $export): StreamedResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User
            && $actor->hasRole(User::StaffRoleAccounting)
            && (int) $export->initiated_by === (int) $actor->id
            && $export->outcome === FinanceExport::OutcomeGenerated, 403);
        abort_unless(filled($export->disk) && filled($export->path), 404);
        abort_unless(Storage::disk($export->disk)->exists($export->path), 404);

        return Storage::disk($export->disk)->download($export->path, $export->downloadFilename(), ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
