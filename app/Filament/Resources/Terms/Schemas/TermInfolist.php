<?php

namespace App\Filament\Resources\Terms\Schemas;

use App\Actions\Calendar\TermTeachingGrid;
use App\Models\SectionMeeting;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Support\DisplayDateTime;
use Carbon\CarbonImmutable;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TermInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Section::make('Term details')->schema([
                TextEntry::make('label')->label('Term name'),
                TextEntry::make('academic_year_label')
                    ->label('Academic year')
                    ->state(fn (Term $record): string => $record->academicYear?->displayLabel() ?? '-'),
                TextEntry::make('type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => Term::typeOptions()[$state] ?? str((string) $state)->headline()->toString()),
                TextEntry::make('state')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => Term::stateOptions()[$state] ?? str((string) $state)->headline()->toString()),
                TextEntry::make('starts_on')->label('Start date')->date('M j, Y'),
                TextEntry::make('ends_on')->label('End date')->date('M j, Y'),
                TextEntry::make('default_max_units')->label('Faculty teaching-load default')->suffix(' units')->placeholder('Not recorded'),
                TextEntry::make('approved_teaching_grid')
                    ->label('Active teaching calendar (Asia/Manila)')->columnSpanFull()
                    ->state(fn (Term $record): array => collect(app(TermTeachingGrid::class)->activePackage($record)?->teachingGridRows ?? [])
                        ->sortBy('day_of_week')
                        ->map(fn ($row): string => (SectionMeeting::dayOptions()[$row->day_of_week] ?? 'Unknown day').' '.CarbonImmutable::parse($row->starts_at, 'Asia/Manila')->format('g:i A').'–'.CarbonImmutable::parse($row->ends_at, 'Asia/Manila')->format('g:i A')
                            .(empty($row->breaks) ? '' : '; Breaks: '.collect($row->breaks)->map(fn (array $break): string => CarbonImmutable::parse($break['starts_at'], 'Asia/Manila')->format('g:i A').'–'.CarbonImmutable::parse($break['ends_at'], 'Asia/Manila')->format('g:i A'))->implode(', ')))
                        ->values()->all())
                    ->listWithLineBreaks()
                    ->placeholder('Awaiting core calendar activation'),
                Section::make('Historical settings')->contained(false)->description('Earlier Term grid fields. The active calendar above controls current scheduling.')->collapsible()->collapsed()->columnSpanFull()->columns(2)->schema([
                    TextEntry::make('scheduling_slot_minutes')->label('Historical slot duration')->suffix(' minutes'),
                    TextEntry::make('scheduling_days')
                        ->label('Historical grid days')
                        ->state(fn (Term $record): string => collect($record->scheduling_days ?? [])
                            ->map(fn (int|string $day): string => [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'][(int) $day] ?? 'Unknown day')
                            ->implode(', '))
                        ->placeholder('Approved grid is recorded in Term Planning'),
                    TextEntry::make('scheduling_day_starts_at')->label('Historical day start')->time('g:i A'),
                    TextEntry::make('scheduling_day_ends_at')->label('Historical day end')->time('g:i A'),
                ]),
                Section::make('Calendar history')->contained(false)->columnSpanFull()->schema([
                    RepeatableEntry::make('calendarPackages')->label('Recorded calendar versions')
                        ->state(fn (Term $record) => $record->calendarPackages()->with('recorder')->latest('version')->get())->schema([
                            TextEntry::make('version')->label('Version'),
                            TextEntry::make('state')->badge(),
                            TextEntry::make('authority_reference')->label('Approval reference')->placeholder('Not recorded'),
                            TextEntry::make('classes_start_on')->label('Classes start')->date(),
                            TextEntry::make('classes_end_on')->label('Classes end')->date(),
                            TextEntry::make('recorder.name')->label('Recorded by')->placeholder('Recorder unavailable'),
                            TextEntry::make('created_at')->label('Recorded (Asia/Manila)')->state(fn (TermCalendarPackage $record): string => DisplayDateTime::format($record->created_at)),
                        ])->columns(3),
                ]),
            ])->columns(2)->divided()->columnSpanFull(),
        ]);
    }
}
