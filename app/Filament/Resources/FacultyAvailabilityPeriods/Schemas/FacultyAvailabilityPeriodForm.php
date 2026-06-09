<?php

namespace App\Filament\Resources\FacultyAvailabilityPeriods\Schemas;

use App\Models\Term;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class FacultyAvailabilityPeriodForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Faculty Availability Submission Period')
                    ->description('Registrar opens one term-scoped window before scheduling starts. Faculty can submit availability only during this period.')
                    ->schema([
                        Select::make('term_id')
                            ->label('Term')
                            ->relationship(
                                'term',
                                'term_name',
                                fn (Builder $query): Builder => $query
                                    ->whereNotNull('term_name')
                                    ->whereNotNull('term_start_date')
                                    ->whereNotNull('term_end_date')
                                    ->whereNotNull('scheduling_starts_at')
                                    ->orderByDesc('scheduling_starts_at')
                            )
                            ->getOptionLabelFromRecordUsing(fn (Term $record): string => collect([
                                $record->term_name,
                                $record->scheduling_starts_at?->format('M d, Y g:i A'),
                            ])->filter()->implode(' | Scheduling starts: '))
                            ->searchable()
                            ->preload()
                            ->required()
                            ->helperText('Only terms with complete scheduling readiness dates are selectable.'),
                        DateTimePicker::make('opens_at')
                            ->label('Opens at')
                            ->seconds(false)
                            ->required(),
                        DateTimePicker::make('closes_at')
                            ->label('Closes at')
                            ->seconds(false)
                            ->after('opens_at')
                            ->required()
                            ->helperText('Must close on or before the term scheduling start date.'),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }
}
