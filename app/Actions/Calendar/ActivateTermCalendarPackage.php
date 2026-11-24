<?php

namespace App\Actions\Calendar;

use App\Filament\Pages\MyAvailability;
use App\Models\AcademicYear;
use App\Models\FacultyAvailabilityDeclaration;
use App\Models\ScheduleGenerationRun;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class ActivateTermCalendarPackage
{
    public function __construct(private readonly TermCalendarPackageReadinessService $readiness) {}

    public function execute(TermCalendarPackage $package, User $actor, ?string $expectedToken = null): TermCalendarPackage
    {
        Gate::forUser($actor)->authorize('update', $package->term);

        $expectedToken ??= $package->concurrencyToken();

        $activated = DB::transaction(function () use ($package, $actor, $expectedToken): TermCalendarPackage {
            $lockedTerm = Term::query()->whereKey($package->term_id)->lockForUpdate()->firstOrFail();

            if ($lockedTerm->state === Term::StateClosed) {
                throw ValidationException::withMessages([
                    'term_id' => 'This term is closed and cannot activate a calendar package.',
                    'state' => 'This term is closed and cannot activate a calendar package.',
                ]);
            }

            $packages = TermCalendarPackage::query()
                ->where('term_id', $package->term_id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $locked = $packages->firstWhere('id', $package->id);

            if (! $locked instanceof TermCalendarPackage) {
                abort(404);
            }

            Gate::forUser($actor)->authorize('update', $locked->term);

            if (! hash_equals($locked->concurrencyToken(), $expectedToken)) {
                throw ValidationException::withMessages(['package_id' => 'This term calendar changed. Close and reopen activation to review the latest details.']);
            }

            if ($locked->state !== TermCalendarPackage::StateDraft) {
                throw ValidationException::withMessages(['state' => 'Only a draft term calendar can be activated.']);
            }

            $readiness = $this->readiness->for($locked);

            if (! $readiness['ready']) {
                throw ValidationException::withMessages([
                    'package_id' => collect($readiness['blockers'])->pluck('reason')->all(),
                    'readiness' => collect($readiness['blockers'])->pluck('reason')->all(),
                ]);
            }

            $lockedTerm->loadMissing('academicYear');
            $academicYear = $lockedTerm->academicYear;
            $enforceAcademicYearBounds = $academicYear instanceof AcademicYear
                && $academicYear->starts_on !== null
                && $academicYear->ends_on !== null
                && $lockedTerm->starts_on !== null
                && $lockedTerm->ends_on !== null
                && $lockedTerm->starts_on->betweenIncluded($academicYear->starts_on, $academicYear->ends_on)
                && $lockedTerm->ends_on->betweenIncluded($academicYear->starts_on, $academicYear->ends_on);

            if ($enforceAcademicYearBounds) {
                $boundErrors = [];
                $rangeLabel = $academicYear->starts_on->format('M j, Y').' – '.$academicYear->ends_on->format('M j, Y');

                if ($locked->classes_start_on !== null
                    && ! $locked->classes_start_on->betweenIncluded($academicYear->starts_on, $academicYear->ends_on)) {
                    $boundErrors['classes_start_on'] = "Classes start date must fall within {$academicYear->displayLabel()} ({$rangeLabel}).";
                }

                if ($locked->classes_end_on !== null
                    && ! $locked->classes_end_on->betweenIncluded($academicYear->starts_on, $academicYear->ends_on)) {
                    $boundErrors['classes_end_on'] = "Classes end date must fall within {$academicYear->displayLabel()} ({$rangeLabel}).";
                }

                if ($boundErrors !== []) {
                    throw ValidationException::withMessages($boundErrors);
                }
            }

            $previousActivePackages = $packages->where('state', TermCalendarPackage::StateActive);
            $previousCoreSignature = $previousActivePackages->last()?->coreSignature();
            $newCoreSignature = $locked->coreSignature();

            foreach ($previousActivePackages as $active) {
                $active->forceFill(['state' => TermCalendarPackage::StateClosed, 'closed_at' => now()])->save();
            }

            $locked->forceFill(['state' => TermCalendarPackage::StateActive, 'recorded_by' => $actor->id, 'activated_at' => now()])->save();

            if ($previousCoreSignature !== null && $previousCoreSignature !== $newCoreSignature) {
                $staleNote = 'Marked stale because the active term calendar dates, teaching hours, or holidays changed.';
                $activeRuns = ScheduleGenerationRun::query()
                    ->where('term_id', $locked->term_id)
                    ->whereIn('status', [
                        ScheduleGenerationRun::StatusQueued,
                        ScheduleGenerationRun::StatusDispatching,
                        ScheduleGenerationRun::StatusUnderReview,
                    ])
                    ->lockForUpdate()
                    ->get();

                foreach ($activeRuns as $activeRun) {
                    $diagnostics = is_array($activeRun->diagnostics) ? $activeRun->diagnostics : [];
                    $diagnostics['stale_reason'] = $staleNote;

                    $activeRun->forceFill([
                        'candidate_state' => 'Stale',
                        'candidate_review_reason' => $staleNote,
                        'diagnostics' => $diagnostics,
                    ])->save();
                }
            }

            $termUpdates = ['state' => Term::StateActive];
            if ($locked->classes_start_on !== null) {
                $termUpdates['starts_on'] = $locked->classes_start_on;
            }
            if ($locked->classes_end_on !== null) {
                $termUpdates['ends_on'] = $locked->classes_end_on;
            }

            $locked->term()->update($termUpdates);

            if ($academicYear instanceof AcademicYear && $academicYear->state === AcademicYear::StateDraft) {
                AcademicYear::query()
                    ->whereKey($academicYear->id)
                    ->where('state', AcademicYear::StateDraft)
                    ->update(['state' => AcademicYear::StateActive]);
            }

            return $locked->fresh(['term', 'windows', 'teachingGridRows', 'datedExceptions']);
        }, attempts: 5);

        $this->notifyAffectedFaculty($activated);

        return $activated;
    }

    private function notifyAffectedFaculty(TermCalendarPackage $activated): void
    {
        $declarations = FacultyAvailabilityDeclaration::query()
            ->where('term_id', $activated->term_id)
            ->with('faculty')
            ->orderByDesc('version')
            ->get()
            ->unique('faculty_user_id');

        if ($declarations->isEmpty()) {
            return;
        }

        $termLabel = trim((string) $activated->term?->label) !== ''
            ? (string) $activated->term->label
            : 'this term';
        $availabilityUrl = MyAvailability::getUrl(['termId' => (int) $activated->term_id]);

        foreach ($declarations as $declaration) {
            if (! $declaration->faculty instanceof User || $declaration->faculty->status !== User::StatusActive) {
                continue;
            }

            $outsideWindows = $declaration->windowsOutsideGrid($activated);

            if ($outsideWindows === []) {
                continue;
            }

            $outsideCount = count($outsideWindows);
            $windowPhrase = $outsideCount === 1
                ? '1 of your saved teaching windows falls'
                : "{$outsideCount} of your saved teaching windows fall";

            Notification::make()
                ->title("Action needed: update your teaching times for {$termLabel}")
                ->body("School teaching hours changed and {$windowPhrase} outside the updated hours. Review your availability if you need to adjust your schedule.")
                ->warning()
                ->actions([
                    Action::make('reviewAvailability')
                        ->label('Review my availability')
                        ->url($availabilityUrl),
                ])
                ->sendToDatabase($declaration->faculty);
        }
    }
}
