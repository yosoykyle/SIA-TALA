<?php

namespace App\Filament\Resources\Assessments\Schemas;

use App\Models\Assessment;
use App\Models\StudentProfile;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AssessmentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Assessment')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                TextEntry::make('enrollment.studentProfile.student_number')
                                    ->label('Student No.'),
                                TextEntry::make('enrollment.studentProfile.last_name')
                                    ->label('Student')
                                    ->state(function (Assessment $record): string {
                                        $studentProfile = $record->enrollment?->studentProfile;

                                        if (! $studentProfile instanceof StudentProfile) {
                                            return '';
                                        }

                                        return collect([
                                            $studentProfile->last_name,
                                            $studentProfile->first_name,
                                        ])->filter()->implode(', ');
                                    }),
                                TextEntry::make('enrollment.term.label')
                                    ->label('Term'),
                                TextEntry::make('version')
                                    ->numeric(),
                                TextEntry::make('state')
                                    ->badge(),
                                TextEntry::make('currency'),
                                TextEntry::make('subtotal')
                                    ->money('PHP'),
                                TextEntry::make('discount_total')
                                    ->money('PHP'),
                                TextEntry::make('total')
                                    ->money('PHP'),
                                TextEntry::make('required_downpayment')
                                    ->money('PHP'),
                                TextEntry::make('activated_at')
                                    ->dateTime()
                                    ->placeholder('-'),
                                TextEntry::make('activator.name')
                                    ->placeholder('Not activated'),
                            ]),
                    ]),
                Section::make('Lines')
                    ->schema([
                        TextEntry::make('lines.description_snapshot')
                            ->label('Assessment lines')
                            ->listWithLineBreaks()
                            ->placeholder('No lines'),
                    ]),
            ]);
    }
}
