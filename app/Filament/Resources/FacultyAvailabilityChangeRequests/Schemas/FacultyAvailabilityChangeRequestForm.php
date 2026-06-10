<?php

namespace App\Filament\Resources\FacultyAvailabilityChangeRequests\Schemas;

use App\Models\FacultyAvailabilitySubmission;
use App\Models\SectionMeeting;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TimePicker;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class FacultyAvailabilityChangeRequestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Requested Availability Revision')
                    ->description('Use this only after an availability deadline or Registrar lock. This does not directly change an official schedule.')
                    ->schema([
                        Select::make('submission_id')
                            ->label('Source Availability')
                            ->options(fn (): array => self::submissionOptions())
                            ->searchable()
                            ->preload()
                            ->required()
                            ->helperText('Choose your latest submitted or locked availability record.'),
                        Repeater::make('requested_windows')
                            ->label('Requested Weekly Windows')
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
                        Textarea::make('reason')
                            ->label('Reason')
                            ->required()
                            ->maxLength(1000)
                            ->rows(4)
                            ->columnSpanFull(),
                    ])
                    ->columns(1)
                    ->columnSpanFull(),
            ]);
    }

    /**
     * @return array<int, string>
     */
    private static function submissionOptions(): array
    {
        $userId = auth()->id();

        if ($userId === null) {
            return [];
        }

        return FacultyAvailabilitySubmission::query()
            ->with('term')
            ->where('faculty_id', $userId)
            ->whereIn('status', [
                FacultyAvailabilitySubmission::StatusSubmitted,
                FacultyAvailabilitySubmission::StatusLocked,
            ])
            ->whereRaw('version = (select max(fas2.version) from faculty_availability_submissions as fas2 where fas2.term_id = faculty_availability_submissions.term_id and fas2.faculty_id = faculty_availability_submissions.faculty_id)')
            ->orderByDesc('submitted_at')
            ->get()
            ->mapWithKeys(fn (FacultyAvailabilitySubmission $submission): array => [
                $submission->id => collect([
                    $submission->term?->term_name,
                    'Version '.$submission->version,
                    ucfirst($submission->status),
                ])->filter()->implode(' | '),
            ])
            ->all();
    }
}
