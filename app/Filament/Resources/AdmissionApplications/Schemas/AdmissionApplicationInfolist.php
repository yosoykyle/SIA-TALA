<?php

namespace App\Filament\Resources\AdmissionApplications\Schemas;

use App\Models\AdmissionApplication;
use App\Models\AdmissionDecision;
use App\Models\IdentityMatchReview;
use App\Models\OfficialCredentialResult;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AdmissionApplicationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Current state and next action')
                    ->schema([
                        TextEntry::make('application_reference')->label('Application reference')->placeholder('Draft'),
                        TextEntry::make('application_state')
                            ->label('State')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => str($state)->headline()->toString()),
                        TextEntry::make('responsible_party')
                            ->label('Responsible party')
                            ->state(fn (AdmissionApplication $record): string => match ($record->application_state) {
                                AdmissionApplication::StateActionNeeded => 'Applicant',
                                default => 'Registrar',
                            }),
                        TextEntry::make('next_action')
                            ->label('Next action')
                            ->state(fn (AdmissionApplication $record): string => match ($record->application_state) {
                                AdmissionApplication::StateSubmitted => 'Review preliminary evidence and identity warnings.',
                                AdmissionApplication::StateActionNeeded => 'Wait for the Applicant to respond to the scoped correction.',
                                AdmissionApplication::StateAdmitted => 'Record and verify due official credentials.',
                                AdmissionApplication::StateNotAdmitted => 'Retain the immutable decision history.',
                                AdmissionApplication::StateWithdrawn => 'Retain history or reopen only with authority before registration begins.',
                                default => 'Review the application record.',
                            })
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
                Section::make('Private identity-match warning')
                    ->schema([
                        TextEntry::make('identity_warning')
                            ->label('Identity integrity review')
                            ->state(function (AdmissionApplication $record): string {
                                $pending = $record->identityMatchReviews
                                    ->where('outcome', IdentityMatchReview::OutcomePending)
                                    ->count();

                                return $pending > 0
                                    ? "{$pending} private identity warning(s) require resolution before admission."
                                    : 'No unresolved identity warning.';
                            }),
                    ])
                    ->columnSpanFull(),
                Section::make('Application scope and minimum applicant facts')
                    ->schema([
                        TextEntry::make('admissionCycle.label')->label('Admission cycle'),
                        TextEntry::make('term.label')->label('Target term'),
                        TextEntry::make('program.name')->label('Program'),
                        TextEntry::make('application_path')->label('Path')->formatStateUsing(fn (string $state): string => str($state)->headline()->toString()),
                        TextEntry::make('legal_name')
                            ->label('Legal name')
                            ->state(fn (AdmissionApplication $record): string => collect([
                                $record->first_name,
                                $record->middle_name,
                                $record->last_name,
                                $record->extension_name,
                            ])->filter()->implode(' ')),
                        TextEntry::make('email')->label('Verified account email'),
                        TextEntry::make('birth_date')->date(),
                        TextEntry::make('phone')->label('Mobile'),
                        TextEntry::make('current_locality')
                            ->label('Current locality')
                            ->state(fn (AdmissionApplication $record): string => collect([
                                $record->current_city_municipality,
                                $record->current_province,
                            ])->filter()->implode(', ')),
                        TextEntry::make('prior_school_name')->label('Prior school'),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
                Section::make('Preliminary evidence')
                    ->schema([
                        TextEntry::make('evidence_summary')
                            ->label('Evidence versions')
                            ->state(fn (AdmissionApplication $record): string => $record->evidenceVersions->isEmpty()
                                ? 'No preliminary evidence version recorded.'
                                : $record->evidenceVersions
                                    ->groupBy('admission_requirement_id')
                                    ->map(fn ($versions): string => $versions->count().' version(s)')
                                    ->implode('; ')),
                    ])
                    ->columnSpanFull(),
                Section::make('Decisions and supersession history')
                    ->schema([
                        TextEntry::make('decision_history')
                            ->label('Admission decisions')
                            ->state(fn (AdmissionApplication $record): string => $record->decisions
                                ->sortByDesc('decided_at')
                                ->map(fn (AdmissionDecision $decision): string => sprintf(
                                    '%s — %s%s',
                                    $decision->decided_at->format('M j, Y g:i A'),
                                    str($decision->decision)->headline(),
                                    $decision->supersedes_admission_decision_id ? ' (superseding)' : '',
                                ))
                                ->implode("\n") ?: 'No admission decision recorded.')
                            ->listWithLineBreaks(),
                    ])
                    ->columnSpanFull(),
                Section::make('Official credentials')
                    ->schema([
                        TextEntry::make('credential_history')
                            ->label('Current and historical results')
                            ->state(fn (AdmissionApplication $record): string => $record->credentialResults
                                ->sortByDesc('recorded_at')
                                ->map(fn (OfficialCredentialResult $result): string => sprintf(
                                    '%s — %s — %s',
                                    $result->requirement->label,
                                    str($result->result)->headline(),
                                    $result->recorded_at->format('M j, Y g:i A'),
                                ))
                                ->implode("\n") ?: 'Not yet applicable.')
                            ->listWithLineBreaks(),
                    ])
                    ->visible(fn (AdmissionApplication $record): bool => $record->application_state === AdmissionApplication::StateAdmitted
                        || $record->credentialResults->isNotEmpty())
                    ->columnSpanFull(),
                Section::make('Activity, delivery, and technical evidence')
                    ->schema([
                        TextEntry::make('submitted_at')->dateTime()->placeholder('Not submitted'),
                        TextEntry::make('updated_at')->label('Last record update')->dateTime(),
                        TextEntry::make('currentSubmissionVersion.version')->label('Current submitted version')->placeholder('None'),
                        TextEntry::make('currentSubmissionVersion.requirementSet.version')->label('Requirement Set version')->placeholder('None'),
                        TextEntry::make('id')->label('Internal application ID'),
                    ])
                    ->columns(2)
                    ->collapsible()
                    ->collapsed()
                    ->columnSpanFull(),
            ]);
    }
}
