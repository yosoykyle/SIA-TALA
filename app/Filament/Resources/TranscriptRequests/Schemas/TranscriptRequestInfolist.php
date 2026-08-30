<?php

namespace App\Filament\Resources\TranscriptRequests\Schemas;

use App\Actions\Completion\TranscriptLifecycleProjection;
use App\Models\TranscriptRequest;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TranscriptRequestInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Request and readiness')->schema([
                    TextEntry::make('external_request_reference')->label('External request'),
                    TextEntry::make('studentProfile.student_number')->label('Student number'),
                    TextEntry::make('student_name')->label('Student')->state(fn (TranscriptRequest $record): string => collect([
                        $record->studentProfile->last_name,
                        $record->studentProfile->first_name,
                        $record->studentProfile->middle_name,
                    ])->filter()->implode(', ')),
                    TextEntry::make('requested_on')->date(),
                    TextEntry::make('due_on')->label('30-day service target')->date(),
                    TextEntry::make('clearance_state')->label('Accounting clearance')->state(fn (TranscriptRequest $record): string => $record->clearanceState())->badge(),
                    TextEntry::make('lifecycle_state')
                        ->label('TOR state')
                        ->state(fn (TranscriptRequest $record): string => app(TranscriptLifecycleProjection::class)->statusForRequest($record))
                        ->badge(),
                    TextEntry::make('template_version'),
                    TextEntry::make('signatory_name'),
                    TextEntry::make('signatory_title'),
                    TextEntry::make('seal_input_type')->label('Seal input'),
                ])->columns(3),
                Section::make('Issuance history')->schema([
                    RepeatableEntry::make('events')->schema([
                        TextEntry::make('type')->badge(),
                        TextEntry::make('reference'),
                        TextEntry::make('authority_reference')->label('Authority'),
                        TextEntry::make('reason')->placeholder('Not applicable'),
                        TextEntry::make('recorded_at')->dateTime(),
                    ])->columns(3),
                ]),
            ]);
    }
}
