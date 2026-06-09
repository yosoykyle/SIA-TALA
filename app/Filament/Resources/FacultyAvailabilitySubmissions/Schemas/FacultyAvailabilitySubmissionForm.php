<?php

namespace App\Filament\Resources\FacultyAvailabilitySubmissions\Schemas;

use App\Models\FacultyAvailabilityPeriod;
use App\Models\SectionMeeting;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TimePicker;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class FacultyAvailabilitySubmissionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Availability Windows')
                    ->description('Faculty submits weekly availability only. Official subject assignments remain Registrar-owned through faculty-subject eligibility.')
                    ->schema([
                        Select::make('availability_period_id')
                            ->label('Availability Period')
                            ->options(fn (): array => self::openPeriodOptions())
                            ->searchable()
                            ->preload()
                            ->required()
                            ->helperText('Only currently open submission periods are selectable.'),
                        Repeater::make('windows')
                            ->label('Weekly Windows')
                            ->schema([
                                Select::make('day_of_week')
                                    ->label('Day')
                                    ->options(SectionMeeting::dayOptions())
                                    ->required(),
                                TimePicker::make('starts_at')
                                    ->label('Start time')
                                    ->seconds(false)
                                    ->required(),
                                TimePicker::make('ends_at')
                                    ->label('End time')
                                    ->seconds(false)
                                    ->after('starts_at')
                                    ->required(),
                                Textarea::make('notes')
                                    ->rows(2)
                                    ->maxLength(255)
                                    ->columnSpanFull(),
                            ])
                            ->columns(3)
                            ->minItems(1)
                            ->defaultItems(1)
                            ->required()
                            ->columnSpanFull(),
                    ])
                    ->columns(1)
                    ->columnSpanFull(),
            ]);
    }

    /**
     * @return array<int, string>
     */
    private static function openPeriodOptions(): array
    {
        $now = now(config('app.timezone'));

        return FacultyAvailabilityPeriod::query()
            ->with('term')
            ->where('status', FacultyAvailabilityPeriod::StatusOpen)
            ->where('opens_at', '<=', $now)
            ->where('closes_at', '>=', $now)
            ->orderByDesc('opens_at')
            ->get()
            ->mapWithKeys(fn (FacultyAvailabilityPeriod $period): array => [
                $period->id => collect([
                    $period->term?->term_name,
                    $period->opens_at?->format('M d, Y g:i A').' - '.$period->closes_at?->format('M d, Y g:i A'),
                ])->filter()->implode(' | '),
            ])
            ->all();
    }
}
