<?php

namespace App\Filament\Resources;

use App\Actions\Enrollment\PersonalDataCorrectionService;
use App\Filament\Resources\PersonalDataCorrectionRequestResource\Pages\ListPersonalDataCorrectionRequests;
use App\Filament\Resources\PersonalDataCorrectionRequestResource\Pages\ViewPersonalDataCorrectionRequest;
use App\Models\PersonalDataCorrectionRequest;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class PersonalDataCorrectionRequestResource extends Resource
{
    protected static ?string $model = PersonalDataCorrectionRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPencilSquare;

    protected static string|UnitEnum|null $navigationGroup = 'Registrar';

    protected static ?string $navigationLabel = 'Correction Requests';

    protected static ?int $navigationSort = 40;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Request Details')
                    ->schema([
                        Select::make('student_profile_id')
                            ->relationship('studentProfile', 'student_id')
                            ->disabled()
                            ->dehydrated(),
                        TextInput::make('status')
                            ->disabled()
                            ->dehydrated(),
                        Select::make('resolved_by')
                            ->relationship('resolver', 'name')
                            ->disabled()
                            ->dehydrated(),
                        TextInput::make('resolved_at')
                            ->disabled()
                            ->dehydrated(),
                        Textarea::make('reject_reason')
                            ->disabled()
                            ->dehydrated()
                            ->columnSpanFull(),
                        KeyValue::make('requested_changes')
                            ->disabled()
                            ->dehydrated()
                            ->columnSpanFull(),
                        KeyValue::make('old_values')
                            ->disabled()
                            ->dehydrated()
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('studentProfile.student_id')
                    ->label('Student ID')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('studentProfile.user.name')
                    ->label('Student Name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        PersonalDataCorrectionRequest::STATUS_PENDING => 'warning',
                        PersonalDataCorrectionRequest::STATUS_APPROVED => 'success',
                        PersonalDataCorrectionRequest::STATUS_REJECTED => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('resolver.name')
                    ->label('Resolved By')
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('resolved_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('approve')
                    ->label('Approve')
                    ->color('success')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->requiresConfirmation()
                    ->visible(fn (PersonalDataCorrectionRequest $record): bool => $record->status === PersonalDataCorrectionRequest::STATUS_PENDING
                    )
                    ->action(function (PersonalDataCorrectionRequest $record): void {
                        try {
                            app(PersonalDataCorrectionService::class)->resolveRequest($record, auth()->user(), 'approve');

                            Notification::make()
                                ->title('Request approved successfully')
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Approval failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('reject')
                    ->label('Reject')
                    ->color('danger')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->schema([
                        Textarea::make('reject_reason')
                            ->label('Reason for Rejection')
                            ->required()
                            ->maxLength(500),
                    ])
                    ->visible(fn (PersonalDataCorrectionRequest $record): bool => $record->status === PersonalDataCorrectionRequest::STATUS_PENDING
                    )
                    ->action(function (PersonalDataCorrectionRequest $record, array $data): void {
                        try {
                            app(PersonalDataCorrectionService::class)->resolveRequest($record, auth()->user(), 'reject', $data['reject_reason']);

                            Notification::make()
                                ->title('Request rejected')
                                ->danger()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Rejection failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPersonalDataCorrectionRequests::route('/'),
            'view' => ViewPersonalDataCorrectionRequest::route('/{record}'),
        ];
    }
}
