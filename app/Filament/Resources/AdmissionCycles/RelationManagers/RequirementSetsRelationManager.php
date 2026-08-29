<?php

namespace App\Filament\Resources\AdmissionCycles\RelationManagers;

use App\Actions\Admissions\PublishAdmissionRequirementSet;
use App\Models\AdmissionCycle;
use App\Models\AdmissionRequirement;
use App\Models\AdmissionRequirementSet;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

class RequirementSetsRelationManager extends RelationManager
{
    protected static string $relationship = 'requirementSets';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Version authority')
                    ->schema([
                        Select::make('application_path')
                            ->options([
                                AdmissionCycle::PathFirstYear => 'First year',
                                AdmissionCycle::PathTransferee => 'Transferee',
                            ])
                            ->required(),
                        TextInput::make('version')->numeric()->minValue(1)->required(),
                        TextInput::make('authority_reference')->required()->maxLength(255),
                        DateTimePicker::make('effective_at')->native(false)->required(),
                        Select::make('replaces_requirement_set_id')
                            ->label('Replaces version')
                            ->options(fn (Get $get): array => AdmissionRequirementSet::query()
                                ->where('admission_cycle_id', $this->getOwnerRecord()->getKey())
                                ->where('application_path', (string) $get('application_path'))
                                ->where('state', AdmissionRequirementSet::StatePublished)
                                ->orderByDesc('version')
                                ->get()
                                ->mapWithKeys(fn (AdmissionRequirementSet $set): array => [
                                    $set->id => 'Version '.$set->version.' — '.($set->effective_at?->timezone(config('app.display_timezone'))->format('M j, Y g:i A') ?? 'effective time unavailable'),
                                ])
                                ->all())
                            ->searchable()
                            ->preload()
                            ->nullable(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
                Repeater::make('requirements')
                    ->relationship()
                    ->schema([
                        TextInput::make('code')->required()->maxLength(64),
                        TextInput::make('label')->required()->maxLength(160),
                        TextInput::make('authority_reference')->required()->maxLength(255),
                        Textarea::make('purpose')->required()->maxLength(1000)->columnSpanFull(),
                        Select::make('credential_classification')
                            ->label('Credential classification')
                            ->options([
                                AdmissionRequirement::ClassificationCoreFirstYearCompletionCredential => 'Core — first-year completion credential',
                                AdmissionRequirement::ClassificationCoreTransferCredential => 'Core — transfer credential',
                                AdmissionRequirement::ClassificationCoreOtherOfficialCredential => 'Core — other official credential',
                                AdmissionRequirement::ClassificationNonCore => 'Non-core requirement',
                            ])
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Set $set, ?string $state): void {
                                if (AdmissionRequirement::isCoreClassification($state)) {
                                    $set('exception_permitted', false);
                                    $set('required_approving_authority', null);
                                }
                            }),
                        Checkbox::make('requires_preliminary_evidence')->label('Requires preliminary evidence'),
                        Select::make('official_submission_method')
                            ->options([
                                AdmissionRequirement::SubmissionInPerson => 'In person',
                                AdmissionRequirement::SubmissionSchoolToSchool => 'School to school',
                                AdmissionRequirement::SubmissionNone => 'No official submission',
                            ])
                            ->required(),
                        Select::make('due_stage')
                            ->options([
                                AdmissionRequirement::DuePreliminaryReview => 'Preliminary review',
                                AdmissionRequirement::DueEnrollmentReadiness => 'Enrollment readiness',
                                AdmissionRequirement::DuePostEnrollmentFollowUp => 'Post-enrollment follow-up',
                            ])
                            ->required(),
                        Textarea::make('applicant_instructions')->required()->maxLength(1500)->columnSpanFull(),
                        Textarea::make('registrar_instructions')->required()->maxLength(1500)->columnSpanFull(),
                        Checkbox::make('exception_permitted')
                            ->label('Eligible for a bounded authorized exception')
                            ->live()
                            ->visible(fn (Get $get): bool => $get('credential_classification') === AdmissionRequirement::ClassificationNonCore)
                            ->afterStateUpdated(function (Set $set, bool $state): void {
                                if (! $state) {
                                    $set('required_approving_authority', null);
                                }
                            }),
                        TextInput::make('required_approving_authority')
                            ->required(fn (Get $get): bool => $get('credential_classification') === AdmissionRequirement::ClassificationNonCore
                                && (bool) $get('exception_permitted'))
                            ->visible(fn (Get $get): bool => $get('credential_classification') === AdmissionRequirement::ClassificationNonCore
                                && (bool) $get('exception_permitted'))
                            ->maxLength(255),
                        TextInput::make('display_order')->numeric()->minValue(1)->required(),
                    ])
                    ->columns(2)
                    ->minItems(1)
                    ->reorderable()
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('version')
            ->columns([
                TextColumn::make('version')
                    ->sortable(),
                TextColumn::make('application_path')
                    ->label('Path')
                    ->formatStateUsing(fn (string $state): string => str($state)->headline()->toString()),
                TextColumn::make('state')->badge(),
                TextColumn::make('requirements_count')->counts('requirements')->label('Requirements'),
                TextColumn::make('authority_reference')->wrap(),
                TextColumn::make('published_at')->dateTime()->placeholder('Not published'),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                Action::make('publish')
                    ->label('Publish version')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Publishing makes this exact requirement version immutable. Review every path, authority, method, and due stage first.')
                    ->schema([
                        TextInput::make('authority_reference')
                            ->label('Publication authority reference')
                            ->required()
                            ->maxLength(255),
                    ])
                    ->visible(fn (AdmissionRequirementSet $record): bool => $record->state === AdmissionRequirementSet::StateDraft)
                    ->action(function (AdmissionRequirementSet $record, array $data): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User, 403);

                        try {
                            app(PublishAdmissionRequirementSet::class)->execute(
                                $record,
                                $actor,
                                (string) $data['authority_reference'],
                                $record->replacedRequirementSet,
                            );
                            Notification::make()->title('Requirement Set published')->success()->send();
                        } catch (ValidationException $exception) {
                            Notification::make()
                                ->title('Requirement Set cannot be published')
                                ->body($exception->validator->errors()->first())
                                ->danger()
                                ->send();
                        }
                    }),
                EditAction::make()
                    ->visible(fn (AdmissionRequirementSet $record): bool => $record->state === AdmissionRequirementSet::StateDraft),
                DeleteAction::make()
                    ->visible(fn (AdmissionRequirementSet $record): bool => $record->state === AdmissionRequirementSet::StateDraft),
            ]);
    }
}
