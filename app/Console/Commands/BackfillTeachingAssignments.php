<?php

namespace App\Console\Commands;

use App\Actions\Scheduling\SyncTeachingAssignmentsFromTimetable;
use App\Models\PublishedTimetableVersion;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillTeachingAssignments extends Command
{
    /**
     * @var string
     */
    protected $signature = 'teaching-assignments:backfill
        {--term= : Only backfill this Term ID}
        {--apply : Write the assignments and rosters (without it, only a preview is shown)}';

    /**
     * @var string
     */
    protected $description = 'Record teaching assignments and grade rosters from each Term\'s current published timetable';

    public function handle(SyncTeachingAssignmentsFromTimetable $sync): int
    {
        $versions = PublishedTimetableVersion::query()
            ->where('state', PublishedTimetableVersion::StatePublished)
            ->when($this->option('term'), fn ($query, $termId) => $query->where('term_id', (int) $termId))
            ->orderBy('term_id')
            ->get();

        if ($versions->isEmpty()) {
            $this->components->info('No current published timetable was found.');

            return self::SUCCESS;
        }

        $apply = (bool) $this->option('apply');
        $names = User::query()->pluck('name', 'id');

        foreach ($versions as $version) {
            $actor = User::query()->find($version->published_by);

            if ($apply && ! $actor?->hasRole(User::StaffRoleRegistrar)) {
                $this->components->error("Term {$version->term_id}: the timetable publisher is no longer a Registrar, so nothing was written for this Term.");

                continue;
            }

            $plans = $apply
                ? DB::transaction(fn (): array => $sync->apply($version, $actor), attempts: 3)
                : $sync->plan($version);

            $this->components->twoColumnDetail("<fg=gray>Term {$version->term_id}</> · Published timetable v{$version->version}", $apply ? 'Applied' : 'Preview');
            $this->table(['Class', 'Result', 'Designated', 'Co-Faculty'], array_map(fn (array $plan): array => [
                $plan['class'],
                $this->outcomeLabel($plan),
                $names[$plan['designated']] ?? '—',
                collect($plan['co_faculty'])->map(fn (int $id): string => $names[$id] ?? (string) $id)->implode(', ') ?: '—',
            ], $plans));
        }

        if (! $apply) {
            $this->components->warn('Preview only. Run again with --apply to write these assignments.');
        }

        return self::SUCCESS;
    }

    /** @param array{outcome: string, designate: bool, add_roster: bool} $plan */
    private function outcomeLabel(array $plan): string
    {
        return match ($plan['outcome']) {
            SyncTeachingAssignmentsFromTimetable::OutcomeAssigned => $plan['designate'] ? 'Assign' : 'Update Co-Faculty',
            SyncTeachingAssignmentsFromTimetable::OutcomeManual => 'Skipped: assigned by hand',
            SyncTeachingAssignmentsFromTimetable::OutcomeNotFaculty => 'Skipped: not an active Faculty account',
            default => $plan['add_roster'] ? 'Already assigned, add grade roster' : 'Already assigned',
        };
    }
}
