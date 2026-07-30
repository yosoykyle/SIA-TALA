<?php

namespace App\Filament\Resources\GraduationReviewBatches\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class GraduationReviewBatchInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Completion eligibility review')
                ->description('This review records eligibility evidence. It does not confer or post a degree.')
                ->schema([
                    TextEntry::make('name'),
                    TextEntry::make('academicYear.label')->label('Academic Year'),
                    TextEntry::make('term.label')->label('Term'),
                    TextEntry::make('state')->badge(),
                    TextEntry::make('creator.name')->label('Created By')->placeholder('System'),
                    TextEntry::make('created_at')->dateTime(),
                    TextEntry::make('closed_at')->dateTime()->placeholder('Review remains open'),
                ])
                ->columns(2),
            Section::make('Review queue')
                ->description('Counts reflect active students and each student’s latest generated review.')
                ->schema([
                    TextEntry::make('active_members_count')->label('Active students')->numeric(),
                    TextEntry::make('awaiting_evaluation_count')->label('Awaiting evaluation')->numeric(),
                    TextEntry::make('blocked_members_count')->label('Blocked')->numeric(),
                    TextEntry::make('ready_members_count')->label('Ready for review')->numeric(),
                    TextEntry::make('complete_members_count')->label('Requirements complete')->numeric(),
                ])
                ->columns(5),
        ]);
    }
}
