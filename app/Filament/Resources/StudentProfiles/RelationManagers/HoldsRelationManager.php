<?php

namespace App\Filament\Resources\StudentProfiles\RelationManagers;

use App\Models\Hold;
use Carbon\Carbon;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class HoldsRelationManager extends RelationManager
{
    protected static string $relationship = 'holds';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Hold details')
                    ->schema([
                        Select::make('hold_type')
                            ->required()
                            ->options([
                                Hold::TypeFinancial => 'Financial',
                                Hold::TypeDocumentary => 'Documentary',
                                Hold::TypeBehavioral => 'Behavioral',
                                Hold::TypeDisciplinary => 'Disciplinary',
                                Hold::TypeAcademicDeficit => 'Academic Deficit',
                                Hold::TypePrerequisite => 'Prerequisite',
                                Hold::TypeEnrollment => 'Enrollment',
                                Hold::TypeCorDownload => 'COR Download',
                                Hold::TypeClearance => 'Clearance',
                                Hold::TypeGraduationEligibility => 'Graduation Eligibility',
                                Hold::TypeReactivation => 'Reactivation',
                                Hold::TypeTransferOut => 'Transfer Out',
                                Hold::TypeRecordRelease => 'Record Release',
                            ]),
                        Select::make('blocking_level')
                            ->required()
                            ->options([
                                Hold::BlockingEnrollment => 'Blocks Enrollment',
                                Hold::BlockingCorPrint => 'Blocks COR Print',
                                Hold::BlockingClearance => 'Blocks Clearance',
                                Hold::BlockingRecordRelease => 'Blocks Record Release',
                                Hold::BlockingGraduationEligibility => 'Blocks Graduation Eligibility',
                                Hold::BlockingReactivation => 'Blocks Reactivation',
                                Hold::BlockingAdvisoryOnly => 'Advisory Only',
                            ]),
                        Select::make('status')
                            ->required()
                            ->options([
                                Hold::StatusActive => 'Active',
                                Hold::StatusResolved => 'Resolved',
                                Hold::StatusWaived => 'Waived',
                                Hold::StatusExpired => 'Expired',
                            ])
                            ->default(Hold::StatusActive),
                        DateTimePicker::make('effective_at')
                            ->required()
                            ->default(now()),
                        DateTimePicker::make('expires_at'),
                        Textarea::make('reason')
                            ->required()
                            ->columnSpanFull(),
                        Textarea::make('staff_only_reason')
                            ->columnSpanFull(),
                        Textarea::make('student_message')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('reason')
            ->columns([
                TextColumn::make('hold_type')
                    ->badge()
                    ->sortable(),
                TextColumn::make('blocking_level')
                    ->badge()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        Hold::StatusActive => 'danger',
                        Hold::StatusResolved => 'success',
                        Hold::StatusWaived => 'warning',
                        Hold::StatusExpired => 'gray',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('reason')
                    ->limit(50)
                    ->searchable(),
                TextColumn::make('effective_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('expires_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        Hold::StatusActive => 'Active',
                        Hold::StatusResolved => 'Resolved',
                        Hold::StatusWaived => 'Waived',
                        Hold::StatusExpired => 'Expired',
                    ]),
                SelectFilter::make('hold_type')
                    ->options([
                        Hold::TypeFinancial => 'Financial',
                        Hold::TypeDocumentary => 'Documentary',
                        Hold::TypeBehavioral => 'Behavioral',
                        Hold::TypeDisciplinary => 'Disciplinary',
                        Hold::TypeAcademicDeficit => 'Academic Deficit',
                        Hold::TypePrerequisite => 'Prerequisite',
                        Hold::TypeEnrollment => 'Enrollment',
                        Hold::TypeCorDownload => 'COR Download',
                        Hold::TypeClearance => 'Clearance',
                        Hold::TypeGraduationEligibility => 'Graduation Eligibility',
                        Hold::TypeReactivation => 'Reactivation',
                        Hold::TypeTransferOut => 'Transfer Out',
                        Hold::TypeRecordRelease => 'Record Release',
                    ]),
            ])
            ->headerActions([
                CreateAction::make()
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['created_by'] = auth()->id();

                        return $data;
                    }),
            ])
            ->actions([
                ViewAction::make(),
                EditAction::make()
                    ->mutateFormDataUsing(function (array $data): array {
                        if ($data['status'] === Hold::StatusResolved) {
                            $data['resolved_by'] = auth()->id();
                            $data['resolved_at'] = Carbon::now();
                        } elseif ($data['status'] === Hold::StatusWaived) {
                            $data['waived_by'] = auth()->id();
                            $data['waived_at'] = Carbon::now();
                        }

                        return $data;
                    }),
            ]);
    }
}
