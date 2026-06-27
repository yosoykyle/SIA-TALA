<?php

namespace App\Filament\Resources\ApplicantIntakes\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ApplicantIntakeInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Applicant')
                    ->schema([
                        TextEntry::make('user.name')->label('Applicant Name'),
                        TextEntry::make('user.email')->label('Email'),
                        TextEntry::make('lrn')->label('LRN')->placeholder('Not provided'),
                        TextEntry::make('birthdate')->date(),
                        TextEntry::make('contact_number')->placeholder('Not provided'),
                        TextEntry::make('applicant_type')->badge(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
                Section::make('Application')
                    ->schema([
                        TextEntry::make('term.term_name')->label('Admission Term'),
                        TextEntry::make('program.name')->label('Preferred Program'),
                        TextEntry::make('year_level'),
                        TextEntry::make('preferred_modality'),
                        TextEntry::make('status')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'approved' => 'success',
                                'action_required' => 'danger',
                                'for_evaluation' => 'info',
                                default => 'warning',
                            }),
                        TextEntry::make('submitted_at')->dateTime(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
                Section::make('Identity Evidence')
                    ->schema([
                        TextEntry::make('identity_document_url')
                            ->label('Private Identity Document')
                            ->formatStateUsing(fn (?string $state): string => filled($state)
                                ? basename($state)
                                : 'Not uploaded'),
                    ])
                    ->columnSpanFull(),
            ]);
    }
}
