<?php

namespace App\Filament\Resources\FacultyQualifications\Pages;

use App\Actions\Scheduling\GenerateSchedulingDemand;
use App\Filament\Resources\FacultyQualifications\FacultyQualificationResource;
use App\Models\CourseSpecification;
use App\Models\FacultyQualification;
use App\Models\ScheduleGenerationRun;
use App\Models\SectionMeeting;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\User;
use App\Support\HasTermPlanningContext;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Alignment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class EditFacultyQualification extends EditRecord
{
    use HasTermPlanningContext {
        getPageClasses as protected getTermPlanningPageClasses;
    }

    protected static string $resource = FacultyQualificationResource::class;

    /** @return array<string> */
    public function getPageClasses(): array
    {
        return [...$this->getTermPlanningPageClasses(), 'tala-qualification-form'];
    }

    public function getTitle(): string
    {
        return 'Edit qualification';
    }

    public function getSubheading(): string
    {
        $record = $this->getRecord();
        $courseTitle = $record->course?->specifications()
            ->where('state', CourseSpecification::StateActive)
            ->latest('id')
            ->value('title');

        return collect([$courseTitle ?? $record->course?->code, $record->faculty?->name])->filter()->implode(' · ');
    }

    public function getBreadcrumb(): string
    {
        return 'Edit qualification';
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make()->label('View details'),
        ];
    }

    /** @return array<Action|ActionGroup> */
    protected function getFormActions(): array
    {
        return [
            $this->getCancelFormAction()->label('Cancel')->color('gray'),
            $this->getSaveFormAction()->label('Save changes'),
        ];
    }

    public function getFormActionsAlignment(): string|Alignment
    {
        return Alignment::End;
    }

    protected function getRedirectUrl(): ?string
    {
        return $this->termId === null ? parent::getRedirectUrl() : $this->getResourceUrl('index');
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $record = $this->getRecord();
        abort_unless($record instanceof FacultyQualification, 404);

        $willBeActive = (bool) ($data['is_active'] ?? $record->is_active);
        $targetCourseId = (int) ($data['course_id'] ?? $record->course_id);
        $targetFacultyId = (int) ($data['faculty_user_id'] ?? $record->faculty_user_id);

        if ($record->is_active && (! $willBeActive || $targetCourseId !== (int) $record->course_id || $targetFacultyId !== (int) $record->faculty_user_id)) {
            $blockingMeeting = SectionMeeting::query()
                ->activeOfficial()
                ->where('faculty_user_id', $record->faculty_user_id)
                ->whereHas('scheduleRun.term', fn (Builder $query): Builder => $query->where('state', '!=', Term::StateClosed))
                ->whereHas('schedulingDemand', function (Builder $query) use ($record): void {
                    $query->whereHas('courseComponent.courseSpecification', fn (Builder $spec): Builder => $spec->where('course_id', $record->course_id))
                        ->orWhereHas('termOffering.curriculumEntry.courseSpecification', fn (Builder $spec): Builder => $spec->where('course_id', $record->course_id));
                })
                ->first();

            if ($blockingMeeting instanceof SectionMeeting) {
                throw ValidationException::withMessages([
                    'is_active' => 'Cannot deactivate or reassign this qualification while the faculty member is assigned to active official classes for this subject. Update the published schedule via Timetable → Revise schedule first.',
                ]);
            }
        }

        return [...$data, 'recorded_by' => $record->recorded_by, 'recorded_at' => $record->recorded_at];
    }

    protected function afterSave(): void
    {
        $actor = auth()->user();
        if (! $actor instanceof User) {
            return;
        }

        $activeTerms = Term::query()
            ->where('state', '!=', Term::StateClosed)
            ->whereHas('calendarPackages', fn (Builder $query): Builder => $query->where('state', TermCalendarPackage::StateActive))
            ->orderBy('starts_on')
            ->get();

        $demandGenerator = app(GenerateSchedulingDemand::class);

        foreach ($activeTerms as $targetTerm) {
            $demandGenerator->forTerm($actor, $targetTerm);

            ScheduleGenerationRun::query()
                ->where('term_id', $targetTerm->id)
                ->whereIn('status', [
                    ScheduleGenerationRun::StatusQueued,
                    ScheduleGenerationRun::StatusDispatching,
                    ScheduleGenerationRun::StatusUnderReview,
                ])
                ->update(['candidate_state' => 'Stale', 'updated_at' => now()]);
        }
    }
}
