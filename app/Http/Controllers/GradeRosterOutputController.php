<?php

namespace App\Http\Controllers;

use App\Actions\Grades\CurrentClassRosterOutput;
use App\Models\GradeRoster;
use App\Models\OutputAccessLog;
use App\Models\User;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GradeRosterOutputController extends Controller
{
    public function print(GradeRoster $roster): Response
    {
        $snapshot = app(CurrentClassRosterOutput::class)->forRoster($roster, $this->actor());
        $roster = $snapshot['roster'];
        $generatedAt = $snapshot['generatedAt'];
        $html = view('outputs.class-roster', compact('roster', 'generatedAt'))->render();
        $this->recordAccess($roster, 'print');

        return response($html);
    }

    public function csv(GradeRoster $roster): StreamedResponse
    {
        $snapshot = app(CurrentClassRosterOutput::class)->forRoster($roster, $this->actor());
        $roster = $snapshot['roster'];
        $generatedAt = $snapshot['generatedAt'];
        $stream = fopen('php://temp', 'w+');
        abort_unless(is_resource($stream), 500, 'The class roster CSV could not be prepared.');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, $snapshot['headers'], ',', '"', '', "\r\n");

        foreach ($snapshot['rows'] as $row) {
            fputcsv($stream, array_map($this->safeCsvCell(...), $row), ',', '"', '', "\r\n");
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        abort_unless(is_string($csv), 500, 'The class roster CSV could not be prepared.');

        $this->recordAccess($roster, 'download');
        $classReference = Str::slug((string) ($roster->section->code ?? $roster->id));
        $filename = "tala-class-roster-{$classReference}-{$generatedAt->format('Ymd-His')}-PHT.csv";

        return response()->streamDownload(function () use ($csv): void {
            echo $csv;
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function actor(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function recordAccess(GradeRoster $roster, string $action): void
    {
        $user = auth()->user();
        OutputAccessLog::query()->create([
            'output_type' => 'Class Roster',
            'source_record_type' => GradeRoster::class,
            'source_record_id' => $roster->id,
            'actor_user_id' => $user?->id,
            'actor_role' => $user?->getRoleNames()->first(),
            'action' => $action,
            'copy_context' => 'current official membership',
            'row_count' => $roster->rows()->where('is_current_membership', true)->count(),
            'purpose' => 'Authorized teaching and Registrar roster operations.',
            'sensitivity' => 'restricted_academic_roster',
            'status' => 'generated',
            'occurred_at' => now(),
        ]);
    }

    private function safeCsvCell(mixed $value): string
    {
        $value = (string) ($value ?? '');

        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'{$value}" : $value;
    }
}
