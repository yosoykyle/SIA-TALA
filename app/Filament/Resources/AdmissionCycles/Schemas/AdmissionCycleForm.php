<?php

namespace App\Filament\Resources\AdmissionCycles\Schemas;

use App\Models\AdmissionCycle;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class AdmissionCycleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Name this admission cycle')
                ->compact()
                ->description('Name the cycle and choose its target term to save a draft. Complete the remaining setup before publishing; a draft is never public.')
                ->schema([
                    TextInput::make('label')->label('Applicant-facing name')->placeholder('First semester admissions 2026–2027')
                        ->helperText('The name applicants see when choosing an admission cycle.')->required()->maxLength(160),
                    TextInput::make('code')->label('Internal cycle code')->placeholder('AY2026-2027-SEM1')
                        ->helperText('A unique, stable reference for staff and retained records.')->required()->maxLength(40)->unique(ignoreRecord: true),
                    Select::make('term_id')->label('Target term')->placeholder('Choose the term applicants will enter')
                        ->helperText('The academic term for this intake.')->relationship('term', 'label')->searchable()->preload()->required(),
                    Select::make('registrar_owner_id')->label('Responsible Registrar')->placeholder('Choose the Registrar responsible for this intake')
                        ->helperText('Required before publication; owns setup and resolves blockers.')->relationship('registrarOwner', 'email')->searchable()->preload(),
                ])->columns(2)->columnSpanFull(),
            Section::make('When applications open')
                ->compact()
                ->description('The application window and correction window have separate purposes.')
                ->schema([
                    DateTimePicker::make('opens_at')->label('Applications open')->native(false)
                        ->displayFormat('M j, Y · g:i A')
                        ->timezone('Asia/Manila')
                        ->prefixIcon('heroicon-o-calendar')
                        ->placeholder('Select date and time')
                        ->helperText('New applications become available at this time once the cycle is published (Asia/Manila).'),
                    DateTimePicker::make('closes_at')->label('New applications close')->native(false)
                        ->after(fn (Get $get): ?string => filled($get('opens_at')) ? 'opens_at' : null)
                        ->displayFormat('M j, Y · g:i A')
                        ->timezone('Asia/Manila')
                        ->prefixIcon('heroicon-o-calendar')
                        ->placeholder('Select date and time')
                        ->helperText('Stops new starts and first submissions. Existing corrections and Registrar reviews continue (Asia/Manila). Must be after opening time.'),
                    DateTimePicker::make('correction_closes_at')->label('Last time to request a new correction')->native(false)
                        ->displayFormat('M j, Y · g:i A')
                        ->timezone('Asia/Manila')
                        ->prefixIcon('heroicon-o-clock')
                        ->placeholder('Select date and time')
                        ->helperText('Required before publication, at or after application closing. Existing correction requests remain actionable after this boundary (Asia/Manila).'),
                ])->columns(['default' => 1, 'lg' => 3])->columnSpanFull(),
            Section::make('Who can apply')
                ->compact()
                ->description('The selected student types apply to each selected program.')
                ->schema([
                    CheckboxList::make('accepted_paths')->label('Student types')
                        ->options(AdmissionCycle::studentTypeOptions())
                        ->default([AdmissionCycle::PathFirstYear, AdmissionCycle::PathTransferee])->dehydrated(false),
                    Select::make('programs')->label('Accepting programs')->placeholder('Choose programs accepting applicants')
                        ->relationship('programs', 'name')->multiple()->searchable()->preload()->default([])
                        ->helperText('Each program needs published requirements before this cycle can be published.')
                        ->saveRelationshipsUsing(function (AdmissionCycle $record, array $state, Get $get): void {
                            $paths = (array) $get('accepted_paths');
                            $record->programs()->sync(collect($state)->mapWithKeys(fn (int|string $programId): array => [
                                (int) $programId => [
                                    'accepts_first_year' => in_array(AdmissionCycle::PathFirstYear, $paths, true),
                                    'accepts_transferee' => in_array(AdmissionCycle::PathTransferee, $paths, true),
                                ],
                            ])->all());
                        }),
                ])->columns(2)->columnSpanFull(),
            Section::make('Applicant guidance')
                ->compact()
                ->description('Required before publication. Give applicants instructions, a support contact and the approved privacy notice.')
                ->schema([
                    Textarea::make('applicant_instructions')->label('Instructions for applicants')->placeholder('Explain how to apply and where to ask for help.')
                        ->helperText('Shown to applicants for this cycle. Program requirements carry their own evidence instructions.')
                        ->maxLength(2000)->rows(3)->columnSpanFull(),
                    TextInput::make('support_contact')->label('Admissions support contact')->placeholder('Admissions office email or phone')
                        ->helperText('A contact applicants can use for questions about this intake.')->maxLength(255),
                    TextInput::make('privacy_notice_reference')->label('Approved privacy notice reference')->placeholder('Approved notice URL or document reference')
                        ->helperText('Identifies the approved notice governing applicant information.')->maxLength(255),
                ])->columns(2)->columnSpanFull(),
        ]);
    }
}
