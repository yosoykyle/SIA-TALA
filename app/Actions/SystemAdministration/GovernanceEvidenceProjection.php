<?php

namespace App\Actions\SystemAdministration;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GovernanceEvidenceProjection
{
    public const InstitutionalChanges = 'institutional-changes';

    public const SystemEvents = 'system-events';

    public const OutputAccess = 'output-access';

    public const PrivacyRetention = 'privacy-retention';

    /** @return array<string, string> */
    public static function tabs(): array
    {
        return [
            self::InstitutionalChanges => 'Institutional Changes',
            self::SystemEvents => 'System Events',
            self::OutputAccess => 'Output and Export Access',
            self::PrivacyRetention => 'Privacy and Retention Boundary',
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $filters
     */
    public function paginate(string $tab, int $page, int $perPage, ?string $search, array $filters): LengthAwarePaginator
    {
        $query = DB::query()->fromSub($this->query($tab), 'governance_evidence');
        $actorId = $filters['actor']['value'] ?? null;
        $type = $filters['type']['value'] ?? null;
        $from = $filters['date']['from'] ?? null;
        $until = $filters['date']['until'] ?? null;

        $query
            ->when(filled($actorId), fn (Builder $builder): Builder => $builder->where('actor_id', (int) $actorId))
            ->when(filled($type), fn (Builder $builder): Builder => $builder->where('type', (string) $type))
            ->when(filled($from), fn (Builder $builder): Builder => $builder->whereDate('occurred_at', '>=', (string) $from))
            ->when(filled($until), fn (Builder $builder): Builder => $builder->whereDate('occurred_at', '<=', (string) $until));

        $safeSearch = Str::of((string) $search)->trim()->limit(100, '')->toString();
        if ($safeSearch !== '') {
            $query->where(function (Builder $builder) use ($safeSearch): void {
                $builder
                    ->where('actor', 'like', "%{$safeSearch}%")
                    ->orWhere('type', 'like', "%{$safeSearch}%")
                    ->orWhere('source', 'like', "%{$safeSearch}%");
            });
        }

        $total = (clone $query)->count();
        $rows = $query
            ->orderByDesc('occurred_at')
            ->orderByDesc('sort_id')
            ->forPage($page, $perPage)
            ->get()
            ->mapWithKeys(function (object $row): array {
                $record = [
                    'reference_id' => (string) $row->reference_id,
                    'occurred_at' => (string) $row->occurred_at,
                    'actor' => (string) $row->actor,
                    'actor_id' => $row->actor_id === null ? null : (int) $row->actor_id,
                    'type' => Str::headline((string) $row->type),
                    'source' => (string) $row->source,
                    'status' => (string) $row->status,
                    'summary' => (string) $row->summary,
                ];

                return [$record['reference_id'] => $record];
            });

        return new LengthAwarePaginator($rows, $total, $perPage, $page);
    }

    /** @return array<int, string> */
    public function actorOptions(): array
    {
        return User::query()
            ->whereHas('roles')
            ->orderBy('name')
            ->pluck('name', 'id')
            ->map(fn (?string $name): string => filled($name) ? (string) $name : 'Staff account')
            ->all();
    }

    /** @return array<string, string> */
    public function typeOptions(string $tab): array
    {
        $types = match ($tab) {
            self::InstitutionalChanges => DB::table('activity_log')
                ->whereNotIn('event', $this->authenticationEvents())
                ->whereNotNull('event')
                ->distinct()
                ->orderBy('event')
                ->pluck('event'),
            self::SystemEvents => DB::table('activity_log')
                ->whereIn('event', $this->authenticationEvents())
                ->whereNotNull('event')
                ->pluck('event')
                ->merge(DB::table('operational_events')->distinct()->orderBy('event_type')->pluck('event_type')),
            self::OutputAccess => DB::table('output_access_logs')
                ->whereNotNull('output_type')
                ->distinct()
                ->orderBy('output_type')
                ->pluck('output_type'),
            default => collect(),
        };

        return $types
            ->filter(fn (mixed $type): bool => is_string($type) && $type !== '')
            ->unique()
            ->sort()
            ->mapWithKeys(fn (string $type): array => [$type => Str::headline($type)])
            ->all();
    }

    private function query(string $tab): Builder
    {
        return match ($tab) {
            self::InstitutionalChanges => $this->institutionalChangesQuery(),
            self::SystemEvents => $this->systemEventsQuery(),
            self::OutputAccess => $this->outputAccessQuery(),
            default => DB::query()->fromSub(DB::query()->selectRaw("1 AS sort_id, 'none' AS reference_id, NULL AS occurred_at, NULL AS actor_id, 'System' AS actor, 'none' AS type, 'None' AS source, 'Recorded' AS status, 'No evidence.' AS summary")->whereRaw('1 = 0'), 'evidence'),
        };
    }

    private function institutionalChangesQuery(): Builder
    {
        return DB::table('activity_log as activity')
            ->leftJoin('users as actor_user', 'actor_user.id', '=', 'activity.causer_id')
            ->whereNotIn('activity.event', $this->authenticationEvents())
            ->selectRaw("activity.id AS sort_id, CONCAT('activity:', activity.id) AS reference_id, activity.created_at AS occurred_at, activity.causer_id AS actor_id, COALESCE(NULLIF(actor_user.name, ''), 'System') AS actor, COALESCE(activity.event, 'recorded') AS type, 'Institutional change' AS source, 'Recorded' AS status, 'An institutional change was recorded.' AS summary");
    }

    private function systemEventsQuery(): Builder
    {
        $authentication = DB::table('activity_log as activity')
            ->leftJoin('users as actor_user', 'actor_user.id', '=', 'activity.causer_id')
            ->whereIn('activity.event', $this->authenticationEvents())
            ->selectRaw("activity.id AS sort_id, CONCAT('authentication:', activity.id) AS reference_id, activity.created_at AS occurred_at, activity.causer_id AS actor_id, COALESCE(NULLIF(actor_user.name, ''), 'System') AS actor, activity.event AS type, 'Authentication' AS source, CASE WHEN activity.event = 'login_failed' THEN 'Attention' ELSE 'Recorded' END AS status, 'An authentication event was recorded.' AS summary");
        $operational = DB::table('operational_events as event')
            ->leftJoin('users as actor_user', 'actor_user.id', '=', 'event.user_id')
            ->selectRaw("event.id AS sort_id, CONCAT('operational:', event.id) AS reference_id, event.occurred_at AS occurred_at, event.user_id AS actor_id, COALESCE(NULLIF(actor_user.name, ''), 'System') AS actor, event.event_type AS type, 'Operational event' AS source, CASE WHEN event.status = 'PROCESSED' THEN 'Recorded' WHEN event.status IN ('FAILED', 'REVIEW_REQUIRED') THEN 'Attention' ELSE 'Pending' END AS status, 'A classified operational event was recorded.' AS summary");

        return DB::query()->fromSub($authentication->unionAll($operational), 'evidence');
    }

    private function outputAccessQuery(): Builder
    {
        return DB::table('output_access_logs as output')
            ->leftJoin('users as actor_user', 'actor_user.id', '=', 'output.actor_user_id')
            ->selectRaw("output.id AS sort_id, CONCAT('output:', output.id) AS reference_id, output.occurred_at AS occurred_at, output.actor_user_id AS actor_id, COALESCE(NULLIF(actor_user.name, ''), 'System') AS actor, COALESCE(output.output_type, 'output') AS type, 'Output access' AS source, CASE WHEN output.status IN ('SUCCESS', 'COMPLETED', 'GENERATED', 'DOWNLOADED', 'VIEWED') THEN 'Recorded' ELSE 'Attention' END AS status, 'An authorized output or export access was recorded.' AS summary");
    }

    /** @return list<string> */
    private function authenticationEvents(): array
    {
        return ['login', 'logout', 'login_failed'];
    }
}
