<?php

namespace App\Support;

use App\Filament\Clusters\TermPlanning\TermPlanningCluster;
use App\Filament\Pages\TermPlanningWorkbench;
use App\Filament\Resources\CalendarEvents\CalendarEventResource;
use App\Filament\Resources\FacultyQualifications\FacultyQualificationResource;
use App\Filament\Resources\FacultyTermLoadOverrides\FacultyTermLoadOverrideResource;
use App\Filament\Resources\Rooms\RoomResource;
use App\Filament\Resources\ScheduleGenerationRuns\ScheduleGenerationRunResource;
use App\Filament\Resources\SchedulingDemands\SchedulingDemandResource;
use App\Filament\Resources\SectionMeetings\SectionMeetingResource;
use App\Filament\Resources\Sections\SectionResource;
use App\Filament\Resources\TermOfferings\TermOfferingResource;
use App\Models\Term;
use App\Models\TermOffering;
use Filament\Actions\Action;
use Filament\Navigation\NavigationItem;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Pages\ViewRecord;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

trait HasTermPlanningContext
{
    use HasTermPlanningShell;

    #[Url]
    #[Locked]
    public ?int $termId = null;

    #[Url]
    public string $resourceView = 'faculty';

    /** @return array<NavigationItem> */
    public function getWorkspaceNavigation(): array
    {
        $task = $this->termPlanningTask();

        return TermPlanningCluster::workspaceNavigation($this->termPlanningContextTermId(), $task === 'published' ? 'generate' : $task);
    }

    public function mountHasTermPlanningContext(): void
    {
        if ($this->termId === null) {
            return;
        }

        abort_unless(Term::query()->whereKey($this->termId)->exists(), 404);

        if (method_exists($this, 'getRecord') && $this->getRecord() instanceof Model) {
            $record = $this->getRecord();
            abort_unless($this->scopeTermPlanningQuery($record->newQuery())->whereKey($record->getKey())->exists(), 404);
        }
    }

    /** @param array<string, mixed> $parameters */
    public function getResourceUrl(?string $name = null, array $parameters = [], bool $isAbsolute = true, ?string $panel = null, ?Model $tenant = null, bool $shouldGuessMissingParameters = true): string
    {
        if ($this->termId !== null) {
            $parameters['termId'] = $this->termId;
        }
        if (static::getResource() === CalendarEventResource::class && $this->resourceView === 'rooms') {
            $parameters['resourceView'] = 'rooms';
        }

        return parent::getResourceUrl($name, $parameters, $isAbsolute, $panel, $tenant, $shouldGuessMissingParameters);
    }

    /** @return array<string> */
    public function getBreadcrumbs(): array
    {
        if (! TermPlanningWorkbench::canAccess()) {
            return parent::getBreadcrumbs();
        }

        return [
            $this->termPlanningReturnUrl() => $this->termPlanningTaskLabel(),
            ...($this instanceof ListRecords ? [] : [$this->getResourceUrl('index') => $this->termPlanningResourceLabel()]),
            $this instanceof ListRecords ? $this->termPlanningResourceLabel() : $this->getBreadcrumb(),
        ];
    }

    public function getSubheading(): string|Htmlable|null
    {
        return $this->termPlanningResourcePurpose();
    }

    public function getTitle(): string|Htmlable
    {
        if (static::getResource() === RoomResource::class) {
            return match (true) {
                $this instanceof CreateRecord => 'Add room',
                $this instanceof EditRecord => 'Edit room · '.$this->getRecord()->code,
                $this instanceof ViewRecord => $this->getRecord()->code.' · '.$this->getRecord()->name,
                default => 'Rooms',
            };
        }
        if (static::getResource() !== SectionResource::class) {
            $recordLabel = match (static::getResource()) {
                FacultyQualificationResource::class => 'faculty qualification',
                FacultyTermLoadOverrideResource::class => 'Faculty load exception',
                SchedulingDemandResource::class => 'weekly meeting requirement',
                SectionMeetingResource::class => 'official class meeting',
                CalendarEventResource::class => 'weekly unavailable time',
                default => null,
            };

            if ($recordLabel === null) {
                return parent::getTitle();
            }

            return match (true) {
                $this instanceof CreateRecord => 'Add '.$recordLabel,
                $this instanceof EditRecord => 'Edit '.$recordLabel,
                $this instanceof ViewRecord => ucfirst($recordLabel),
                default => $this->termPlanningResourceLabel(),
            };
        }

        return match (true) {
            $this instanceof CreateRecord => 'Add a class',
            $this instanceof EditRecord => 'Edit class',
            $this instanceof ViewRecord => ($this->getRecord()->courseRevision?->title ?? $this->getRecord()->termOffering?->curriculumEntry?->courseSpecification?->title) ?: 'Class '.$this->getRecord()->code,
            default => 'Classes & sections',
        };
    }

    protected function termPlanningContextDescription(): string
    {
        $termId = $this->termPlanningContextTermId();
        $term = $termId === null ? null : Term::query()->with('academicYear')->find($termId);

        return $term === null ? '' : collect([$term->academicYear?->label, $term->label])->filter()->implode(' · ').'. ';
    }

    public function termPlanningReturnUrl(): string
    {
        return TermPlanningWorkbench::getUrl([
            'termId' => $this->termPlanningContextTermId(),
            'viewTab' => $this->termPlanningTask(),
            ...(static::getResource() === CalendarEventResource::class && $this->resourceView === 'rooms' ? ['resourceView' => 'rooms'] : []),
            ...(static::getResource() === RoomResource::class ? ['resourceView' => 'rooms'] : []),
        ]);
    }

    private function termPlanningContextTermId(): ?int
    {
        if ($this->termId !== null) {
            return $this->termId;
        }

        $record = method_exists($this, 'getRecord') ? $this->getRecord() : null;

        $termId = $record instanceof Model ? ($record->getAttributes()['term_id'] ?? null) : null;

        return is_numeric($termId) ? (int) $termId : null;
    }

    protected function getCancelFormAction(): Action
    {
        if ($this->termId === null) {
            return parent::getCancelFormAction();
        }

        return Action::make('cancel')->label('Cancel')->color('gray')->url($this->getResourceUrl('index'));
    }

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing($this->scopeTermPlanningQuery(...));
    }

    protected function scopeTermPlanningQuery(Builder $query): Builder
    {
        if ($this->termId === null) {
            return $query;
        }

        return match (static::getResource()) {
            RoomResource::class, FacultyQualificationResource::class => $query,
            SectionResource::class => $query->where(fn (Builder $sections): Builder => $sections
                ->whereHas('calendarPackage', fn (Builder $package): Builder => $package->where('term_id', $this->termId))
                ->orWhere(fn (Builder $legacy): Builder => $legacy->whereNull('term_calendar_package_id')
                    ->whereHas('termOffering', fn (Builder $offering): Builder => $offering->where('term_id', $this->termId)))),
            SchedulingDemandResource::class => $query->whereHas('termOffering', fn (Builder $offering): Builder => $offering->where('term_id', $this->termId)),
            SectionMeetingResource::class => $query->whereHas('scheduleRun', fn (Builder $run): Builder => $run->where('term_id', $this->termId)),
            default => $query->where($query->qualifyColumn('term_id'), $this->termId),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function termPlanningFormData(array $data): array
    {
        if ($this->termId === null) {
            return $data;
        }

        if (static::getResource() === SectionResource::class
            && ! TermOffering::query()->whereKey($data['term_offering_id'] ?? null)->where('term_id', $this->termId)->exists()) {
            throw ValidationException::withMessages(['term_offering_id' => 'Choose an offering for the selected Term.']);
        }

        if (in_array(static::getResource(), [CalendarEventResource::class, FacultyTermLoadOverrideResource::class], true)) {
            $data['term_id'] = $this->termId;
        }

        return $data;
    }

    private function termPlanningTask(): string
    {
        return match (static::getResource()) {
            RoomResource::class, FacultyQualificationResource::class, FacultyTermLoadOverrideResource::class, CalendarEventResource::class => 'resources',
            ScheduleGenerationRunResource::class => 'generate',
            SectionMeetingResource::class => 'published',
            default => 'classes',
        };
    }

    private function termPlanningTaskLabel(): string
    {
        return match ($this->termPlanningTask()) {
            'resources' => 'Faculty & rooms',
            'generate' => 'Timetable',
            'published' => 'Timetable',
            default => 'Classes & sections',
        };
    }

    private function termPlanningResourceLabel(): string
    {
        return match (static::getResource()) {
            SectionResource::class => 'Classes',
            TermOfferingResource::class => 'Offered courses',
            SchedulingDemandResource::class => 'Weekly meeting requirements',
            RoomResource::class => 'Rooms',
            FacultyQualificationResource::class => 'Faculty qualifications',
            FacultyTermLoadOverrideResource::class => 'Faculty load exceptions',
            CalendarEventResource::class => 'Weekly unavailable times',
            ScheduleGenerationRunResource::class => 'Timetable requests',
            SectionMeetingResource::class => 'Official meetings',
            default => static::getResource()::getTitleCasePluralModelLabel(),
        };
    }

    private function termPlanningResourcePurpose(): string
    {
        return match (static::getResource()) {
            SectionResource::class => $this instanceof CreateRecord ? 'Choose the offered course and record this class.' : ($this instanceof EditRecord ? 'Edit this class’s permitted fields.' : 'Classes and their delivery groups.'),
            TermOfferingResource::class => 'Choose the courses taught in this Term, then prepare their classes and delivery groups. Regular offerings come from an active curriculum; special offerings record an externally approved additional offering.',
            SchedulingDemandResource::class => 'Check the required teaching for each class delivery group. Resolve source problems before generating a timetable.',
            RoomResource::class => 'Shared room catalog used for class assignments.',
            FacultyQualificationResource::class => 'Record which courses each teacher is approved to teach. Qualifications apply across Terms.',
            FacultyTermLoadOverrideResource::class => 'Approved exceptions to this Term’s default Faculty load.',
            CalendarEventResource::class => 'Prevent meetings during these times for this Term. Record dated no-class exceptions in Calendar.',
            ScheduleGenerationRunResource::class => 'Inspect generation requests, candidate assignments and validation. Accepting a candidate and publishing an official timetable are separate decisions.',
            SectionMeetingResource::class => 'Inspect official meetings from the published timetable. Changes to published meetings use controlled timetable revision.',
            default => '',
        };
    }
}
