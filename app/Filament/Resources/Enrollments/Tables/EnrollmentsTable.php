<?php

namespace App\Filament\Resources\Enrollments\Tables;

use App\Actions\Enrollment\EnrollmentPlacementService;
use App\Models\Enrollment;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Throwable;

class EnrollmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['studentProfile.program', 'term']))
            ->columns([
                TextColumn::make('studentProfile.student_number')
                    ->label('Student No.')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('studentProfile.last_name')
                    ->label('Student')
                    ->state(fn (Enrollment $record): string => collect([
                        $record->studentProfile?->last_name,
                        $record->studentProfile?->first_name,
                    ])->filter()->implode(', '))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('studentProfile.program.code')
                    ->label('Program')
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('term.term_name')
                    ->label('Term')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->colors([
                        'gray' => 'not_started',
                        'warning' => ['pending_review', 'pending_payment'],
                        'info' => 'capacity_pending',
                        'success' => 'ready_for_official_enrollment',
                        'primary' => 'officially_enrolled',
                        'danger' => ['cancelled', 'dropped', 'withdrawn'],
                    ])
                    ->searchable(),
                TextColumn::make('student_type')
                    ->label('Type')
                    ->badge()
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('registered_at')
                    ->dateTime()
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'not_started' => 'Not Started',
                        'pending_review' => 'Pending Review',
                        'capacity_pending' => 'Capacity Pending',
                        'pending_payment' => 'Payment Pending',
                        'ready_for_official_enrollment' => 'Ready for Official Enrollment',
                        'officially_enrolled' => 'Officially Enrolled',
                        'cancelled' => 'Cancelled',
                        'dropped' => 'Dropped',
                        'withdrawn' => 'Withdrawn',
                    ]),
                SelectFilter::make('student_type')
                    ->options([
                        'new' => 'New/Freshmen',
                        'transferee' => 'Transferee',
                        'regular' => 'Regular',
                        'irregular' => 'Irregular',
                        'returnee' => 'Returnee',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
                self::confirmPlacementAction(),
            ])
            ->toolbarActions([]);
    }

    public static function confirmPlacementAction(): Action
    {
        return Action::make('confirmPlacement')
            ->label('Confirm Placement')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->schema([
                Select::make('section_id')
                    ->label('Published section placement')
                    ->options(fn (?Enrollment $record): array => $record instanceof Enrollment
                        ? app(EnrollmentPlacementService::class)->placementOptions($record)
                        : [])
                    ->required()
                    ->searchable()
                    ->native(false)
                    ->helperText('Options come from active published section meetings only.'),
            ])
            ->modalHeading('Confirm enrollment placement')
            ->modalSubmitActionLabel('Confirm Placement')
            ->visible(fn (Enrollment $record): bool => auth()->user()?->can('confirmPlacement', $record) ?? false)
            ->action(function (Enrollment $record, array $data): void {
                $actor = auth()->user();

                if (! $actor instanceof User) {
                    return;
                }

                try {
                    $summary = app(EnrollmentPlacementService::class)->confirm(
                        enrollment: $record,
                        sectionId: (int) $data['section_id'],
                        actor: $actor,
                    );

                    Notification::make()
                        ->title($summary['already_confirmed'] ? 'Placement already confirmed' : 'Placement confirmed')
                        ->body('Seat reservation and published schedule bindings are recorded.')
                        ->success()
                        ->send();
                } catch (Throwable $exception) {
                    Notification::make()
                        ->title('Placement confirmation failed')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }
}
