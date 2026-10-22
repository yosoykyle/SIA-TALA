<?php

namespace App\Filament\Student\Pages;

use App\Actions\Grades\IncDeadlineService;
use App\Actions\StudentHub\StudentGradeLabelFormatter;
use App\Models\GradeRosterRow;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class GradesView extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?string $navigationLabel = 'Grades';

    protected static ?string $title = 'Released grades';

    protected ?string $subheading = 'Released results for your enrolled subjects.';

    protected string $view = 'filament.student.pages.generic-table';

    /** @return array<string|int, string> */
    public function getBreadcrumbs(): array
    {
        return [Dashboard::getUrl(panel: 'student') => 'Overview', $this->getTitle()];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->releasedGradesQuery())
            ->columns([
                TextColumn::make('roster.termOffering.term.label')->label('Term'),
                TextColumn::make('roster.termOffering.curriculumEntry.courseSpecification.title')->label('Subject')->wrap()
                    ->weight(FontWeight::SemiBold)
                    ->description(fn (GradeRosterRow $record): ?string => $record->roster?->termOffering?->curriculumEntry?->courseSpecification?->course?->code),
                TextColumn::make('courseEnrollment.units_snapshot')->label('Units')
                    ->formatStateUsing(fn (?string $state): ?string => $state === null ? null : (string) (float) $state),
                TextColumn::make('current_outcome_code')->label('Result')
                    ->state(fn (GradeRosterRow $record): ?string => $record->latestReleasedOutcome?->result_code ?? $record->current_outcome_code)
                    ->formatStateUsing(fn (?string $state, GradeRosterRow $record): ?string => app(StudentGradeLabelFormatter::class)
                        ->displayGrade($state, $record->latestReleasedOutcome?->new_category ?? $record->current_outcome_category))
                    ->weight(FontWeight::SemiBold)
                    ->description(function (GradeRosterRow $record): ?string {
                        $event = $record->latestReleasedOutcome;
                        if (($event?->result_code ?? $record->current_outcome_code) !== 'INC') {
                            return null;
                        }
                        if ($event?->deadline === null) {
                            return 'Deadline not recorded';
                        }
                        $deadline = $event->result_code === 'INC'
                            ? app(IncDeadlineService::class)->currentDeadline($event) : $event->deadline;

                        return 'Complete by '.$deadline->format('M j, Y');
                    }),
                TextColumn::make('released_at')->label('Released')
                    ->state(fn (GradeRosterRow $record): mixed => $record->latestReleasedOutcome?->released_at ?? $record->released_at)
                    ->date()->timezone(config('app.display_timezone')),
            ])
            ->stackedOnMobile()
            ->emptyStateHeading('No released grades yet')
            ->emptyStateDescription('Your results appear here after release. You can check your enrolled subjects in Academics.')
            ->emptyStateIcon('heroicon-o-clipboard-document-list')
            ->emptyStateActions([
                Action::make('viewAcademicRecord')->label('View academic record')->color('gray')
                    ->url(Academics::getUrl(['view' => 'record'], panel: 'student')),
            ]);
    }

    public function releasedGradesQuery(): Builder
    {
        $studentProfileId = auth()->user()?->studentProfile?->id;

        return GradeRosterRow::query()
            ->with([
                'roster.termOffering.term',
                'roster.termOffering.curriculumEntry.courseSpecification.course',
                'courseEnrollment.enrollment.studentProfile',
                'latestReleasedOutcome',
            ])
            ->whereNotNull('released_at')
            ->whereHas('courseEnrollment.enrollment', fn (Builder $query) => $query->where('student_profile_id', $studentProfileId ?? 0))
            ->orderByDesc('released_at')
            ->orderByDesc('id');
    }
}
