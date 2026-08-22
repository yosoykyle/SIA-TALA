<?php

namespace App\Actions\Grades;

use App\Models\GradeRoster;
use App\Models\GradeRosterReturnedRow;
use App\Models\GradeRosterVersion;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ReturnGradeRoster
{
    /** @param list<int> $rowIds */
    public function execute(GradeRoster $roster, User $actor, string $reason, array $rowIds = []): GradeRoster
    {
        if (! $actor->hasRole(User::StaffRoleRegistrar)) {
            throw new AuthorizationException('Only Registrar staff can return grade rosters.');
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw new RuntimeException('A consolidated return explanation is required.');
        }

        return DB::transaction(function () use ($roster, $actor, $reason, $rowIds): GradeRoster {
            $locked = GradeRoster::query()->with(['rows' => fn ($query) => $query->where('is_current_membership', true)])
                ->lockForUpdate()->findOrFail($roster->id);

            if ($locked->state !== GradeRoster::StateSubmitted) {
                throw new RuntimeException('Only submitted rosters can be returned.');
            }

            $version = GradeRosterVersion::query()
                ->where('grade_roster_id', $locked->id)
                ->where('version_number', $locked->current_version_number)
                ->where('state', GradeRosterVersion::StateSubmitted)
                ->lockForUpdate()
                ->firstOrFail();
            $selectedIds = $rowIds === [] ? $locked->rows->pluck('id')->all() : array_values(array_unique(array_map('intval', $rowIds)));

            if (collect($selectedIds)->diff($locked->rows->pluck('id'))->isNotEmpty()) {
                throw new RuntimeException('Returned rows must belong to the submitted roster version.');
            }

            foreach ($locked->rows->whereIn('id', $selectedIds) as $row) {
                GradeRosterReturnedRow::query()->create([
                    'grade_roster_version_id' => $version->id,
                    'grade_roster_row_id' => $row->id,
                    'reason' => $reason,
                    'returned_by' => $actor->id,
                    'returned_at' => now(),
                ]);
                $row->update(['returned_at' => now(), 'returned_by' => $actor->id, 'return_reason' => $reason]);
            }

            $version->update(['state' => GradeRosterVersion::StateReturned, 'reviewed_by' => $actor->id, 'reviewed_at' => now()]);
            $locked->update([
                'state' => GradeRoster::StateReturned,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'return_reason' => $reason,
            ]);

            return $locked->fresh(['rows', 'versions.returnedRows']);
        }, attempts: 3);
    }
}
