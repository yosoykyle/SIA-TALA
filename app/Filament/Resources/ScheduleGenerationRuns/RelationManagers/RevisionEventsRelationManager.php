<?php

namespace App\Filament\Resources\ScheduleGenerationRuns\RelationManagers;

use App\Filament\Resources\ScheduleGenerationRuns\Schemas\ScheduleRevisionEventInfolist;
use App\Models\Course;
use App\Models\ScheduleGenerationRun;
use App\Models\ScheduleRevisionEvent;
use App\Models\Section;
use App\Models\SectionMeeting;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Facades\FilamentView;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Tables\View\TablesRenderHook;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
use Livewire\Mechanisms\ExtendBlade\ExtendBlade;

class RevisionEventsRelationManager extends RelationManager
{
    protected static bool $isLazy = false;

    protected static string $relationship = 'revisionEvents';

    protected static ?string $title = 'Term revision history';

    private static ?int $registeredViewManagerId = null;

    public function booted(): void
    {
        $this->registerToolbarHeadingHook();
    }

    public function mount(): void
    {
        abort_unless(static::canViewForRecord($this->ownerRecord, $this->pageClass ?? static::class), 403);

        parent::mount();
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $actor = auth()->user();

        return $ownerRecord instanceof ScheduleGenerationRun
            && $actor instanceof User
            && Gate::forUser($actor)->allows('view', $ownerRecord)
            && Gate::forUser($actor)->allows('viewAny', SectionMeeting::class);
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function infolist(Schema $schema): Schema
    {
        return ScheduleRevisionEventInfolist::configure($schema);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->modifyQueryUsing(fn ($query) => $query->with([
                'changedBy',
                'sectionMeeting.schedulingDemand.courseComponent.courseSpecification.course',
                'sectionMeeting.schedulingDemand.sectionDeliveryGroup.section',
            ]))
            ->columns([
                TextColumn::make('change_type')
                    ->label('What changed')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ScheduleRevisionEvent::changeTypeOptions()[$state] ?? str($state)->headline()->toString())
                    ->description(fn (ScheduleRevisionEvent $record): string => self::meetingContext($record))
                    ->sortable(),
                TextColumn::make('effective_date')
                    ->label('When & who')
                    ->date()
                    ->description(fn (ScheduleRevisionEvent $record): string => self::recordedBy($record))
                    ->sortable(),
                TextColumn::make('reason')
                    ->label('Reason')
                    ->limit(60)
                    ->wrap(),
                TextColumn::make('affected_student_count')
                    ->label('Impact')
                    ->state(fn (ScheduleRevisionEvent $record): string => $record->affected_student_count.' students')
                    ->description(fn (ScheduleRevisionEvent $record): string => $record->affected_faculty_count.' Faculty'),
            ])
            ->filters([
                SelectFilter::make('change_type')
                    ->label('Change Type')
                    ->options(ScheduleRevisionEvent::changeTypeOptions()),
            ])
            ->filtersTriggerAction(fn (Action $action): Action => $action->button()->label('Filter')->color('gray')->labeledFrom(null))
            ->defaultSort('created_at', 'desc')
            ->heading(null)
            ->description(null)
            ->columnManager(false)
            ->extraAttributes(['class' => 'tala-term-revision-history tala-solver-rm-table'])
            ->recordActions([
                ViewAction::make()
                    ->label('View details')->button()->size('sm')->color('gray'),
            ])
            ->toolbarActions([])
            ->emptyStateHeading('No official meeting changes recorded')
            ->emptyStateDescription('This is normal until a published timetable meeting is revised. Candidate edits are not recorded in Term revision history.')
            ->stackedOnMobile();
    }

    public function toolbarHeadingHtml(): Htmlable
    {
        return new HtmlString(
            '<div class="tala-rm-toolbar-heading" style="flex: 1 1 18rem; min-inline-size: 0; display: grid; gap: 0.125rem; align-content: center;">'
            .'<h2 class="fi-ta-header-heading" style="margin: 0; font-size: 0.9375rem; font-weight: 600; line-height: 1.35; color: var(--tala-text);">Term revision history</h2>'
            .'<p class="fi-ta-header-description" style="margin: 0; font-size: 0.8125rem; line-height: 1.45; color: var(--tala-muted);">Chronological log of official meeting changes across this term.</p>'
            .'</div>'
        );
    }

    private function registerToolbarHeadingHook(): void
    {
        $manager = FilamentView::getFacadeRoot();
        $managerId = is_object($manager) ? spl_object_id($manager) : null;

        if ($managerId !== null && self::$registeredViewManagerId === $managerId) {
            return;
        }

        self::$registeredViewManagerId = $managerId;

        FilamentView::registerRenderHook(
            TablesRenderHook::TOOLBAR_START,
            function (): Htmlable {
                $current = ExtendBlade::currentRendering();

                return $current instanceof self
                    ? $current->toolbarHeadingHtml()
                    : new HtmlString('');
            },
            scopes: static::class,
        );
    }

    private static function meeting(ScheduleRevisionEvent $record): ?SectionMeeting
    {
        $meeting = $record->getRelationValue('sectionMeeting');

        return $meeting instanceof SectionMeeting ? $meeting : null;
    }

    private static function actorLabel(ScheduleRevisionEvent $record): string
    {
        $actor = $record->getRelationValue('changedBy');

        return $actor instanceof User ? $actor->name : 'User #'.(int) $record->changed_by;
    }

    private static function meetingContext(ScheduleRevisionEvent $record): string
    {
        $meeting = self::meeting($record);
        $section = $meeting?->schedulingDemand?->sectionDeliveryGroup?->section;
        $sequence = $meeting instanceof SectionMeeting
            ? (int) $meeting->meeting_sequence
            : (int) ($record->new_snapshot_json['meeting_sequence'] ?? 0);

        return collect([
            self::courseLabel($record),
            $section instanceof Section ? 'Class '.$section->code : 'Class unavailable',
            $sequence > 0 ? 'Meeting '.$sequence : null,
        ])->filter()->implode(' · ');
    }

    private static function courseLabel(ScheduleRevisionEvent $record): string
    {
        $specification = self::meeting($record)?->schedulingDemand?->courseComponent?->courseSpecification;
        $course = $specification?->course;
        $parts = array_filter([$course instanceof Course ? $course->code : null, $specification?->title]);

        return $parts === [] ? 'Course unavailable' : implode(' · ', $parts);
    }

    private static function recordedBy(ScheduleRevisionEvent $record): string
    {
        return self::actorLabel($record).' · Recorded '.($record->created_at?->timezone('Asia/Manila')->format('M j, Y, g:i A') ?? 'time unavailable');
    }
}
