<?php

namespace App\Filament\Resources\GradeCorrections\Schemas;

use App\Enums\GradeCorrectionStatus;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class GradeCorrectionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('student.name')
                    ->label('Student'),
                TextEntry::make('subject.code')
                    ->label('Subject'),
                TextEntry::make('subject.description')
                    ->label('Subject Description'),
                TextEntry::make('term.term_name')
                    ->label('Term'),
                TextEntry::make('assessment_component')
                    ->label('Component')
                    ->placeholder('-'),
                TextEntry::make('current_grade')
                    ->label('Current Grade')
                    ->placeholder('-'),
                TextEntry::make('requested_action')
                    ->label('Requested Action'),
                TextEntry::make('reason'),
                TextEntry::make('status')
                    ->badge()
                    ->formatStateUsing(fn (GradeCorrectionStatus|string $state): string => str($state instanceof GradeCorrectionStatus ? $state->value : $state)->replace('_', ' ')->headline()->toString()),
                TextEntry::make('assignedTo.name')
                    ->label('Assigned To')
                    ->placeholder('-'),
                TextEntry::make('creator.name')
                    ->label('Created By')
                    ->placeholder('-'),
                TextEntry::make('resolved_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }
}
