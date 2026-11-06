<?php

namespace App\Filament\Resources\GradeRosters\Schemas;

use App\Actions\Grades\CurrentClassRosterOutput;
use App\Models\GradeOutcomeEvent;
use App\Models\GradeRoster;
use App\Models\GradeRosterVersion;
use App\Models\IncDeadlineAmendment;
use App\Models\OperationalEvent;
use App\Models\User;
use Filament\Schemas\Components\View as SchemaView;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class GradeRosterInfolist
{
    public static function configure(Schema $schema): Schema
    {
        if (auth()->user()?->hasRole(User::StaffRoleAcademicHead) && ! auth()->user()->hasRole(User::StaffRoleRegistrar)) {
            return $schema->components([
                SchemaView::make('filament.admin.grade-rosters.head-membership')->columnSpanFull()
                    ->viewData(function (GradeRoster $record): array {
                        $membership = app(CurrentClassRosterOutput::class)->membershipSnapshot($record, auth()->user());
                        $membership['roster']->loadMissing('teachingAssignment.faculty');

                        return $membership;
                    }),
            ]);
        }

        return $schema->components([
            SchemaView::make('filament.admin.grade-rosters.registrar-review')->columnSpanFull()
                ->viewData(function (GradeRoster $record) use ($schema): array {
                    $record->loadMissing(['termOffering.term.academicYear', 'termOffering.curriculumEntry.courseSpecification.course',
                        'section', 'teachingAssignment.faculty', 'rows.courseEnrollment.enrollment.studentProfile']);
                    $version = $record->versions()->where('version_number', $record->current_version_number)
                        ->with(['rows.rosterRow.courseEnrollment.enrollment.studentProfile', 'returnedRows'])->first();

                    return [
                        'roster' => $record,
                        'version' => $version,
                        'reviewRows' => $version?->rows ?? collect(),
                        'submissionHistory' => self::submissionHistory($record, $schema->getLivewire()->submissionHistoryPage),
                        'notifications' => $record->state === GradeRoster::StateReleased ? collect() : self::notificationQuery($record)->latest('occurred_at')->get(),
                    ];
                }),
        ]);

    }

    /** @return LengthAwarePaginator<int, GradeRosterVersion> */
    public static function submissionHistory(GradeRoster $record, int $page): LengthAwarePaginator
    {
        $query = $record->versions()->with(['submitter', 'reviewer', 'releaser', 'returnedRows'])
            ->orderByDesc('version_number');
        $history = $query->paginate(10, pageName: 'submissionHistoryPage', page: max(1, $page));

        if ($history->isEmpty() && $history->total() > 0) {
            return $query->paginate(10, pageName: 'submissionHistoryPage', page: $history->lastPage());
        }

        return $history;
    }

    /** @return Builder<OperationalEvent> */
    public static function notificationQuery(GradeRoster $record): Builder
    {
        $outcomes = GradeOutcomeEvent::query()->select('id')
            ->whereHas('row', fn (Builder $rows): Builder => $rows->where('grade_roster_id', $record->id));
        $amendments = IncDeadlineAmendment::query()->select('id')->whereIn('grade_outcome_event_id', clone $outcomes);

        return OperationalEvent::query()->whereIn('event_type', OperationalEvent::academicNotificationTypes())
            ->where(fn (Builder $scope): Builder => $scope
                ->where(fn (Builder $roster): Builder => $roster->where('related_record_type', $record->getMorphClass())->where('related_record_id', $record->id))
                ->orWhere(fn (Builder $events): Builder => $events->where('related_record_type', (new GradeOutcomeEvent)->getMorphClass())->whereIn('related_record_id', $outcomes))
                ->orWhere(fn (Builder $changes): Builder => $changes->where('related_record_type', (new IncDeadlineAmendment)->getMorphClass())->whereIn('related_record_id', $amendments)));
    }
}
