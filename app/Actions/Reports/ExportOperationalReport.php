<?php

namespace App\Actions\Reports;

use App\Models\OutputAccessLog;
use App\Models\User;
use App\Policies\OperationalReportPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportOperationalReport
{
    public function __construct(
        private readonly OperationalReportService $reports,
        private readonly OperationalReportPolicy $policy,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function execute(
        User $actor,
        string $reportKey,
        array $filters,
        ?string $purpose,
        ?Request $request = null,
    ): StreamedResponse {
        if (! $this->policy->export($actor, $reportKey)) {
            throw new AuthorizationException('You are not authorized to export this report.');
        }

        $purpose = filled($purpose) ? trim((string) $purpose) : null;

        if ($this->reports->isSensitive($reportKey) && blank($purpose)) {
            throw ValidationException::withMessages([
                'purpose' => 'A purpose is required for sensitive report exports.',
            ]);
        }

        $query = $this->reports->applyFilters(
            $reportKey,
            $this->reports->query($reportKey, $actor),
            $filters,
        );

        /** @var Collection<int, Model> $records */
        $records = $query->get();
        $columns = $this->reports->columns($reportKey);
        $filterSummary = [
            'report_key' => $reportKey,
            'report_name' => $this->reports->label($reportKey),
            'filters' => $this->reports->normalizeFilters($reportKey, $filters),
        ];

        OutputAccessLog::query()->create([
            'output_type' => 'REPORT',
            'source_record_type' => 'report:'.$reportKey,
            'source_record_id' => 0,
            'actor_user_id' => $actor->id,
            'actor_role' => $actor->getRoleNames()->first(),
            'action' => 'EXPORT',
            'filter_summary' => $filterSummary,
            'row_count' => $records->count(),
            'purpose' => $purpose,
            'sensitivity' => $this->reports->sensitivity($reportKey),
            'request_context' => [
                'ip' => $request?->ip(),
                'user_agent' => $request?->userAgent(),
                'route' => $request?->route()?->getName(),
            ],
            'status' => 'generated',
            'occurred_at' => now(),
        ]);

        $filename = Str::of($reportKey)
            ->replace('.', '-')
            ->append('-'.now()->format('Ymd-His').'.csv')
            ->toString();

        return response()->streamDownload(function () use ($columns, $records): void {
            $stream = fopen('php://output', 'wb');

            if ($stream === false) {
                return;
            }

            fputcsv($stream, collect($columns)->pluck('label')->all());

            foreach ($records as $record) {
                fputcsv($stream, collect($columns)
                    ->map(fn (array $column): string => $this->safeCsvValue($this->reports->value($record, $column)))
                    ->all());
            }

            fclose($stream);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function safeCsvValue(string $value): string
    {
        if (preg_match('/^[=+\-@\t\r]/u', $value) === 1) {
            return "'{$value}";
        }

        return $value;
    }
}
