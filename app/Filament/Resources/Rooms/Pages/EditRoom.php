<?php

namespace App\Filament\Resources\Rooms\Pages;

use App\Filament\Resources\Rooms\RoomResource;
use App\Models\PublishedTimetableMeeting;
use App\Models\PublishedTimetableVersion;
use App\Models\ScheduleGenerationRun;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\Term;
use App\Support\HasTermPlanningContext;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Alignment;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;

class EditRoom extends EditRecord
{
    use HasTermPlanningContext {
        getCancelFormAction as protected contextualCancelFormAction;
        getPageClasses as protected getTermPlanningPageClasses;
    }

    #[Url]
    public bool $returnToResources = false;

    protected ?bool $hasDatabaseTransactions = true;

    private bool $previousIsActive = true;

    private int $previousCapacity = 0;

    private string $previousRoomType = '';

    /** @return array<string> */
    public function getPageClasses(): array
    {
        return [...$this->getTermPlanningPageClasses(), 'tala-room-form'];
    }

    public function getSubheading(): string
    {
        return 'Room records apply across Terms.';
    }

    protected function beforeSave(): void
    {
        $keys = array_keys($this->data['features'] ?? []);
        foreach ($this->record->features()->pluck('id') as $id) {
            if (! in_array('record-'.$id, $keys, true)) {
                throw ValidationException::withMessages(['data.features' => 'Existing equipment cannot be removed from this form. Reload the room and correct its equipment.']);
            }
        }

        $this->previousIsActive = (bool) $this->record->is_active;
        $this->previousCapacity = (int) $this->record->capacity;
        $this->previousRoomType = (string) $this->record->room_type;

        $newIsActive = (bool) ($this->data['is_active'] ?? $this->record->is_active);
        $newCapacity = (int) ($this->data['capacity'] ?? $this->record->capacity);
        $newRoomType = (string) ($this->data['room_type'] ?? $this->record->room_type);

        if ($newIsActive && $newCapacity >= $this->previousCapacity && $newRoomType === $this->previousRoomType) {
            return;
        }

        $activeMeetings = PublishedTimetableMeeting::query()
            ->where('room_id', $this->record->id)
            ->whereHas('timetableVersion', fn ($query) => $query
                ->where('state', PublishedTimetableVersion::StatePublished)
                ->whereHas('term', fn ($termQuery) => $termQuery->where('state', Term::StateActive)))
            ->with([
                'classOffering.cohorts',
                'classOffering.deliveryGroups',
                'classOffering.termOffering.curriculumEntry.courseSpecification.course',
                'classOffering.courseRevision.course',
                'schedulingDemand.sectionDeliveryGroup',
            ])
            ->get();

        if ($activeMeetings->isEmpty()) {
            return;
        }

        if (! $newIsActive) {
            $affectedLabels = $activeMeetings
                ->map(fn (PublishedTimetableMeeting $meeting): string => $this->publishedMeetingClassLabel($meeting))
                ->unique()
                ->values()
                ->implode(', ');

            throw ValidationException::withMessages([
                'data.is_active' => "Cannot deactivate this room while it is assigned to active published class(es): {$affectedLabels}. Revise the official timetable first.",
            ]);
        }

        if ($newRoomType !== $this->previousRoomType) {
            $affectedLabels = $activeMeetings
                ->map(fn (PublishedTimetableMeeting $meeting): string => $this->publishedMeetingClassLabel($meeting))
                ->unique()
                ->values()
                ->implode(', ');

            throw ValidationException::withMessages([
                'data.room_type' => "Cannot change room type while this room is assigned to active published class(es): {$affectedLabels}. Revise the official timetable first.",
            ]);
        }

        $insufficientClasses = $activeMeetings
            ->map(function (PublishedTimetableMeeting $meeting) use ($newCapacity): ?string {
                $expectedCount = $this->publishedMeetingExpectedCount($meeting);

                if ($newCapacity >= $expectedCount) {
                    return null;
                }

                return $this->publishedMeetingClassLabel($meeting)." (expects {$expectedCount})";
            })
            ->filter()
            ->unique()
            ->values();

        if ($insufficientClasses->isNotEmpty()) {
            throw ValidationException::withMessages([
                'data.capacity' => 'Cannot reduce room capacity to '.$newCapacity.' below the expected count for active published class(es): '.$insufficientClasses->implode(', ').'.',
            ]);
        }
    }

    protected function afterSave(): void
    {
        if (! ($this->previousIsActive || (bool) $this->record->is_active)) {
            return;
        }

        if (! $this->record->wasChanged('is_active')
            && ! $this->record->wasChanged('capacity')
            && ! $this->record->wasChanged('room_type')) {
            return;
        }

        ScheduleGenerationRun::query()
            ->whereHas('term', fn ($query) => $query->where('state', Term::StateActive))
            ->whereIn('status', [
                ScheduleGenerationRun::StatusQueued,
                ScheduleGenerationRun::StatusDispatching,
                ScheduleGenerationRun::StatusUnderReview,
            ])
            ->update(['candidate_state' => 'Stale', 'updated_at' => now()]);
    }

    private function publishedMeetingExpectedCount(PublishedTimetableMeeting $meeting): int
    {
        $demandGroup = $meeting->schedulingDemand?->sectionDeliveryGroup;
        if ($demandGroup instanceof SectionDeliveryGroup) {
            return max(0, (int) $demandGroup->expected_count);
        }

        $section = $meeting->classOffering;
        if (! $section instanceof Section) {
            return 0;
        }

        $activeGroups = $section->deliveryGroups
            ->whereNotIn('state', [SectionDeliveryGroup::StateClosed, SectionDeliveryGroup::StateCancelled]);

        if ($activeGroups->isNotEmpty()) {
            return max(0, (int) $activeGroups->max('expected_count'));
        }

        if ($section->cohorts->isNotEmpty()) {
            return max(0, (int) $section->cohorts->sum('pivot.expected_count'));
        }

        return max(0, (int) ($section->termOffering?->expected_count ?? $section->capacity ?? 0));
    }

    private function publishedMeetingClassLabel(PublishedTimetableMeeting $meeting): string
    {
        $section = $meeting->classOffering;
        $courseCode = $section?->courseRevision?->course?->code
            ?? $section?->termOffering?->curriculumEntry?->courseSpecification?->course?->code;
        $sectionCode = $section?->class_reference ?: $section?->code;

        return collect([$courseCode, $sectionCode])->filter()->implode(' · ') ?: 'Section #'.$meeting->section_id;
    }

    protected function getCancelFormAction(): Action
    {
        return $this->returnToResources ? Action::make('cancel')->label('Cancel')->color('gray')->url($this->termPlanningReturnUrl()) : $this->contextualCancelFormAction();
    }

    protected static string $resource = RoomResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make()
                ->label('View details')
                ->url(fn (): string => $this->getResourceUrl('view', ['record' => $this->getRecord(), 'returnToResources' => $this->returnToResources])),
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
        return $this->returnToResources ? $this->termPlanningReturnUrl() : ($this->termId === null ? parent::getRedirectUrl() : $this->getResourceUrl('index'));
    }
}
