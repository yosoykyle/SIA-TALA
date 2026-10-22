<?php

namespace App\Filament\Student\Pages;

use App\Models\Hold;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class HoldsView extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shield-exclamation';

    protected static ?string $navigationLabel = 'School matters to resolve';

    protected static ?string $title = 'School matters to resolve';

    protected ?string $subheading = 'These notices show what needs attention and which school office can help.';

    protected string $view = 'filament.student.pages.generic-table';

    /** @return array<string|int, string> */
    public function getBreadcrumbs(): array
    {
        return [Dashboard::getUrl(panel: 'student') => 'Overview', $this->getTitle()];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Hold::query()
                    ->whereHas('studentProfile', function (Builder $query) {
                        /** @var User $user */
                        $user = auth()->user();
                        $query->where('user_id', $user->id);
                    })
                    ->where('status', Hold::StatusActive)
                    ->where(fn (Builder $query) => $query->whereNull('effective_at')->orWhere('effective_at', '<=', now()))
                    ->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            )
            ->columns([
                TextColumn::make('required_action')
                    ->label('What needs attention')
                    ->state(fn (Hold $record): string => $record->studentFacingMessage())
                    ->wrap(),
                TextColumn::make('blocking_level')
                    ->label('Affects')
                    ->badge()
                    ->color(fn (string $state): string => $state === Hold::BlockingAdvisoryOnly ? 'gray' : 'warning')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        Hold::BlockingEnrollment => 'Enrollment',
                        Hold::BlockingCorPrint => 'Registration certificate',
                        Hold::BlockingClearance => 'Clearance',
                        Hold::BlockingRecordRelease => 'Record release',
                        Hold::BlockingGraduationEligibility => 'Graduation',
                        Hold::BlockingReactivation => 'Return to school',
                        Hold::BlockingAdvisoryOnly => 'Advisory notice',
                        default => str($state)->headline()->toString(),
                    }),
                TextColumn::make('office_to_contact')
                    ->label('Contact')
                    ->state(fn (Hold $record): string => $record->studentFacingOfficeLabel())
                    ->weight(FontWeight::SemiBold)
                    ->description(fn (Hold $record): ?string => $record->effective_at
                        ? 'Since '.$record->effective_at->copy()->timezone(config('app.display_timezone'))->format('M j, Y') : null),
            ])
            ->stackedOnMobile()
            ->emptyStateHeading('No active school notices')
            ->emptyStateDescription('No active notices are recorded here. Enrollment and other requirements are checked separately.')
            ->emptyStateIcon('heroicon-o-check-circle')
            ->emptyStateActions([
                Action::make('viewEnrollment')->label('View enrollment')->color('gray')
                    ->url(Enrollment::getUrl(panel: 'student')),
            ]);
    }
}
