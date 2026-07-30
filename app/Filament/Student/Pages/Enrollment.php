<?php

namespace App\Filament\Student\Pages;

use App\Actions\Enrollment\EnrollmentAcademicContextResolver;
use App\Actions\Enrollment\EnrollmentPlacementService;
use App\Actions\Enrollment\EnrollmentProposalService;
use App\Actions\Enrollment\SubjectSuggestionService;
use App\Models\CourseEnrollment;
use App\Models\Enrollment as EnrollmentRecord;
use App\Models\ScheduleGenerationRun;
use App\Models\Section;
use App\Models\SectionMeeting;
use App\Models\StudentProfile;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Throwable;

class Enrollment extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationLabel = 'Enrollment';

    protected static ?string $title = 'Enrollment';

    protected string $view = 'filament.student.pages.generic-table';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('student') ?? false;
    }

    /**
     * @return list<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('viewClassSchedule')
                ->label('Class Schedule')
                ->icon('heroicon-o-calendar-days')
                ->url(ScheduleView::getUrl(panel: 'student')),
            Action::make('viewCor')
                ->label('Current COR')
                ->icon('heroicon-o-document-text')
                ->url(CorView::getUrl(panel: 'student')),
        ];
    }

    public function getSubheading(): ?string
    {
        $enrollment = $this->currentEnrollment();

        if (! $enrollment instanceof EnrollmentRecord) {
            return 'No enrollment record is active. Contact the Registrar when the enrollment period opens.';
        }

        $context = app(EnrollmentAcademicContextResolver::class)->forEnrollment($enrollment);
        $cohortCode = $enrollment->student_type === 'irregular'
            ? null
            : app(EnrollmentPlacementService::class)->recommendedRegularCohortCode($enrollment);

        return collect([
            'Current Term: '.($context['term_label'] ?? 'Not recorded'),
            'Enrollment Status: '.$context['enrollment_status_label'],
            'Enrollment Type: '.$context['enrollment_type_label'],
            'Curriculum Level: '.$context['curriculum_level_label'],
            'Course Delivery Mix: '.$context['course_delivery_mix'],
            $enrollment->status_reason !== null ? 'Reason: '.$enrollment->status_reason : null,
            'Responsible Office: '.$context['responsible_office'],
            'Next Action: '.$context['next_action'],
            $enrollment->student_type === 'irregular'
                ? 'Your selections are proposals until the Registrar confirms capacity.'
                : ($cohortCode !== null
                    ? "Proposed cohort {$cohortCode}. The Registrar confirms the complete published block."
                    : 'No complete published cohort block is currently available. Contact the Registrar.'),
        ])->filter()->implode(' — ');
    }

    public function table(Table $table): Table
    {
        $enrollment = $this->currentEnrollment();

        return $table
            ->query($this->eligibleSectionsQuery($enrollment))
            ->columns([
                TextColumn::make('subject')
                    ->label('Subject')
                    ->state(fn (Section $record): string => collect([
                        $record->termOffering?->course()?->code,
                        $record->termOffering?->courseSpecification()?->title,
                    ])->filter()->implode(' — '))
                    ->weight('bold')
                    ->wrap(),
                TextColumn::make('description')
                    ->label('Description')
                    ->state(fn (Section $record): ?string => $record->termOffering?->courseSpecification()?->title)
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('code')
                    ->label('Section')
                    ->searchable(),
                TextColumn::make('cohort')
                    ->label('Cohort')
                    ->state(fn (Section $record): string => $record->deliveryGroups->pluck('name')->filter()->implode(', '))
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('termOffering.modality')
                    ->label('Modality')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => str((string) $state)->replace('_', ' ')->headline()->toString())
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('credit_units')
                    ->label('Units')
                    ->state(fn (Section $record): mixed => $record->termOffering?->courseSpecification()?->credit_units)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('schedule')
                    ->state(fn (Section $record): string => $this->sectionSummary($enrollment, $record)['schedule'] ?? 'Unpublished')
                    ->wrap(),
                TextColumn::make('remaining_capacity')
                    ->label('Seats Left')
                    ->state(fn (Section $record): string => (string) ($this->sectionSummary($enrollment, $record)['remaining'] ?? 0)),
                TextColumn::make('section_status')
                    ->label('Status / Next Step')
                    ->state(fn (Section $record): string => $this->sectionStatus($enrollment, $record))
                    ->badge()
                    ->color(fn (string $state): string => $this->sectionStatusColor($state))
                    ->wrap(),
            ])
            ->toolbarActions([
                BulkAction::make('proposeSections')
                    ->label('Replace complete proposal')
                    ->icon('heroicon-o-paper-airplane')
                    ->requiresConfirmation()
                    ->modalHeading('Replace complete section proposal')
                    ->modalDescription('Submitting replaces your complete proposal. Select every section you want to keep. The Registrar reviews the complete set, and no capacity is reserved until confirmation.')
                    ->visible(fn (): bool => $enrollment instanceof EnrollmentRecord
                        && $enrollment->student_type === 'irregular'
                        && app(EnrollmentPlacementService::class)->placementIsMutable($enrollment))
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records) use ($enrollment): void {
                        $actor = auth()->user();

                        if (! $enrollment instanceof EnrollmentRecord || ! $actor instanceof User) {
                            return;
                        }

                        try {
                            app(EnrollmentProposalService::class)->replace(
                                $enrollment,
                                $records->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
                                $actor,
                            );
                            Notification::make()
                                ->title('Section proposal saved')
                                ->body('The Registrar must confirm placement before capacity is reserved.')
                                ->success()
                                ->send();
                            $this->resetTable();
                        } catch (Throwable $exception) {
                            Notification::make()
                                ->title('Section proposal not saved')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->checkIfRecordIsSelectableUsing(fn (Section $record): bool => $enrollment instanceof EnrollmentRecord
                && $enrollment->student_type === 'irregular'
                && app(EnrollmentPlacementService::class)->placementIsMutable($enrollment)
                && $this->eligibleOfferingIds($enrollment)->contains((int) $record->term_offering_id)
                && $this->selectionBlocker($enrollment, $record) === null)
            ->emptyStateHeading($enrollment instanceof EnrollmentRecord
                ? 'No published curriculum sections'
                : 'Enrollment has not started')
            ->emptyStateDescription($enrollment instanceof EnrollmentRecord
                ? 'Your curriculum, academic progression, or the published schedule currently has no selectable sections. Contact the Registrar if this is unexpected.'
                : 'The Registrar must start an enrollment record before sections can be displayed.')
            ->emptyStateIcon('heroicon-o-clipboard-document-list')
            ->stackedOnMobile();
    }

    private function currentEnrollment(): ?EnrollmentRecord
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return null;
        }

        $profile = StudentProfile::query()
            ->where('user_id', $user->id)
            ->first();

        return $profile instanceof StudentProfile
            ? app(EnrollmentAcademicContextResolver::class)->currentEnrollmentForProfile($profile)
            : null;
    }

    private function eligibleSectionsQuery(?EnrollmentRecord $enrollment): Builder
    {
        if (! $enrollment instanceof EnrollmentRecord) {
            return Section::query()->whereRaw('1 = 0');
        }

        $query = Section::query()
            ->with([
                'termOffering.curriculumEntry.courseSpecification.course',
                'deliveryGroups',
            ])
            ->where('state', Section::StateOpen)
            ->whereHas('termOffering', fn (Builder $query) => $query
                ->where('term_id', $enrollment->term_id)
                ->whereHas('curriculumEntry', fn (Builder $query) => $query
                    ->where('curriculum_version_id', $enrollment->studentProfile?->curriculum_version_id)))
            ->whereHas('deliveryGroups.schedulingDemands.sectionMeetings', fn (Builder $query) => $query
                ->where('state', SectionMeeting::StateActive)
                ->whereHas('scheduleRun', fn (Builder $query) => $query
                    ->where('status', ScheduleGenerationRun::StatusPublished)))
            ->orderBy('code');

        if ($enrollment->student_type !== 'irregular') {
            $cohortCode = app(EnrollmentPlacementService::class)
                ->recommendedRegularCohortCode($enrollment);

            if ($cohortCode === null) {
                return $query->whereRaw('1 = 0');
            }

            $query->whereHas('deliveryGroups', fn (Builder $query) => $query->where('name', $cohortCode));
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private function sectionSummary(?EnrollmentRecord $enrollment, Section $section): array
    {
        if (! $enrollment instanceof EnrollmentRecord) {
            return [];
        }

        return app(EnrollmentPlacementService::class)->sectionOperationalSummary($section);
    }

    /**
     * @return Collection<int, int>
     */
    private function eligibleOfferingIds(EnrollmentRecord $enrollment): Collection
    {
        $suggestions = app(SubjectSuggestionService::class)->suggestForEnrollment($enrollment);

        return collect([
            ...($suggestions['suggested'] ?? []),
            ...($suggestions['back_subjects'] ?? []),
        ])
            ->pluck('term_offering_id')
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();
    }

    private function eligibilityMessage(?EnrollmentRecord $enrollment, Section $section): string
    {
        if (! $enrollment instanceof EnrollmentRecord) {
            return 'No active enrollment';
        }

        $suggestions = app(SubjectSuggestionService::class)->suggestForEnrollment($enrollment);

        if ($this->eligibleOfferingIds($enrollment)->contains((int) $section->term_offering_id)) {
            return 'Eligible';
        }

        $courseId = $section->termOffering?->course()?->id;
        $blocker = collect($suggestions['blocked'] ?? [])
            ->first(fn (array $item): bool => (int) ($item['course_id'] ?? 0) === (int) $courseId);

        if (is_array($blocker)) {
            return collect($blocker['blockers'] ?? [])
                ->map(fn (array $item): string => str((string) ($item['reason'] ?? 'academic rule'))->replace('_', ' ')->headline()->toString())
                ->unique()
                ->implode('; ');
        }

        $setupBlockers = collect($suggestions['setup_blockers'] ?? [])
            ->map(fn (mixed $item): string => str((string) $item)->replace('_', ' ')->headline()->toString())
            ->implode('; ');

        return $setupBlockers !== '' ? $setupBlockers : 'Not eligible for current progression';
    }

    private function sectionStatus(?EnrollmentRecord $enrollment, Section $section): string
    {
        if (! $enrollment instanceof EnrollmentRecord) {
            return 'Enrollment has not started';
        }

        if ($enrollment->student_type !== 'irregular') {
            return 'Registrar confirmation pending';
        }

        $eligibility = $this->eligibilityMessage($enrollment, $section);

        if ($eligibility !== 'Eligible') {
            return $eligibility;
        }

        if ($blocker = $this->selectionBlocker($enrollment, $section)) {
            return $blocker;
        }

        $isProposed = CourseEnrollment::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('proposed_section_id', $section->id)
            ->where('status', CourseEnrollment::StatusActive)
            ->exists();

        return $isProposed ? 'Included in your proposal' : 'Available to select';
    }

    private function sectionStatusColor(string $status): string
    {
        return match ($status) {
            'Available to select' => 'success',
            'Included in your proposal', 'Registrar confirmation pending' => 'warning',
            default => 'danger',
        };
    }

    private function selectionBlocker(?EnrollmentRecord $enrollment, Section $section): ?string
    {
        if (! $enrollment instanceof EnrollmentRecord) {
            return 'No active enrollment';
        }

        return app(EnrollmentPlacementService::class)->sectionSelectionBlocker($enrollment, $section);
    }
}
