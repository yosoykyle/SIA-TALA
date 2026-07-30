<?php

namespace App\Filament\Student\Pages;

use App\Actions\Enrollment\AcademicProgressionService;
use App\Models\StudentLifecycleChange;
use App\Models\StudentProfile;
use App\Models\User;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LifecycleView extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-path-rounded-square';

    protected static ?string $navigationLabel = 'Academic Status';

    protected static ?string $title = 'Progression & Lifecycle';

    protected string $view = 'filament.student.pages.generic-table';

    public function table(Table $table): Table
    {
        /** @var User $user */
        $user = auth()->user();
        $profile = StudentProfile::query()
            ->where('user_id', $user->id)
            ->first();
        $officialStanding = AcademicProgressionService::standingLabel($profile?->academic_standing);
        $systemReview = $profile instanceof StudentProfile
            ? app(AcademicProgressionService::class)->evaluate($profile)['recommendation']
            : [
                'label' => StudentProfile::StandingNotYetEvaluated,
                'explanation' => 'No active Student Profile is available for academic review.',
            ];

        return $table->query(StudentLifecycleChange::query()
            ->whereHas('studentProfile', function (Builder $query): void {
                /** @var User $user */
                $user = auth()->user();
                $query->where('user_id', $user->id);
            })
            ->where('state', StudentLifecycleChange::StateApplied))
            ->description(
                "Official academic standing: {$officialStanding}. "
                ."System review: {$systemReview['label']}. "
                .'The Registrar Office records approved changes; contact that office if this result or history is unexpected.',
            )
            ->columns([
                TextColumn::make('type')
                    ->label('Recorded Change')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => StudentLifecycleChange::typeOptions()[$state] ?? str($state)->headline()->toString()),
                TextColumn::make('term.label')->label('Term'),
                TextColumn::make('effective_on')->label('Effective Date')->date(),
                TextColumn::make('state')
                    ->label('Official Result')
                    ->badge()
                    ->formatStateUsing(fn (): string => 'Applied to your official record'),
                TextColumn::make('responsible_office')
                    ->label('Responsible Office')
                    ->state('Registrar Office'),
                TextColumn::make('student_summary')
                    ->label('What This Means')
                    ->state(fn (StudentLifecycleChange $record): string => sprintf(
                        'The approved %s took effect on %s. Contact the Registrar Office if you need clarification.',
                        StudentLifecycleChange::typeOptions()[$record->type] ?? str((string) $record->type)->headline()->toString(),
                        $record->effective_on->toFormattedDateString(),
                    ))
                    ->wrap(),
            ])->defaultSort('effective_on', 'desc')
            ->stackedOnMobile()
            ->emptyStateHeading('No applied lifecycle changes')
            ->emptyStateDescription('Your official academic standing is shown above. No approved lifecycle result has changed your Student Profile. Contact the Registrar Office if you expected a recorded result.');
    }
}
