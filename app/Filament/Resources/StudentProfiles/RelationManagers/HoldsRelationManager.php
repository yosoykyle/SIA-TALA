<?php

namespace App\Filament\Resources\StudentProfiles\RelationManagers;

use App\Actions\StudentLifecycle\CreateHold;
use App\Actions\StudentLifecycle\ResolveHold;
use App\Actions\StudentLifecycle\WaiveHold;
use App\Models\Hold;
use App\Models\StudentProfile;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class HoldsRelationManager extends RelationManager
{
    protected static string $relationship = 'holds';

    public function table(Table $table): Table
    {
        return $table->recordTitleAttribute('reason')->columns([
            TextColumn::make('hold_type')->badge()->sortable(),
            TextColumn::make('blocking_level')->badge()->sortable(),
            TextColumn::make('status')->badge()->sortable(),
            TextColumn::make('reason')->limit(50)->searchable(),
            TextColumn::make('effective_at')->dateTime()->sortable(),
            TextColumn::make('expires_at')->dateTime()->sortable(),
        ])->filters([
            SelectFilter::make('status')->options([
                Hold::StatusActive => 'Active', Hold::StatusResolved => 'Resolved',
                Hold::StatusWaived => 'Waived', Hold::StatusExpired => 'Expired',
            ]),
            SelectFilter::make('hold_type')->options(self::holdTypeOptions()),
        ])->headerActions([
            Action::make('createHold')->label('Record Hold')->authorize(fn (): bool => auth()->user()?->can('create', Hold::class) ?? false)
                ->schema(self::holdForm())
                ->action(function (array $data): void {
                    /** @var StudentProfile $student */
                    $student = $this->getOwnerRecord();
                    /** @var User $actor */
                    $actor = auth()->user();
                    app(CreateHold::class)->execute($student, $data, $actor);
                    Notification::make()->title('Hold recorded')->success()->send();
                }),
        ])->recordActions([
            Action::make('resolve')->authorize('resolve')->visible(fn (Hold $record): bool => $record->status === Hold::StatusActive)
                ->schema([Textarea::make('evidence')->required()])
                ->action(function (Hold $record, array $data): void {
                    app(ResolveHold::class)->execute($record, auth()->user(), $data['evidence']);
                    Notification::make()->title('Hold resolved')->success()->send();
                }),
            Action::make('waive')->authorize('waive')->visible(fn (Hold $record): bool => $record->status === Hold::StatusActive)
                ->schema([Textarea::make('authority')->required(), Textarea::make('reason')->required()])
                ->action(function (Hold $record, array $data): void {
                    app(WaiveHold::class)->execute($record, auth()->user(), $data['authority'], $data['reason']);
                    Notification::make()->title('Hold waived')->success()->send();
                }),
        ])->defaultSort('created_at', 'desc');
    }

    /** @return list<mixed> */
    private static function holdForm(): array
    {
        return [
            Select::make('hold_type')->options(self::holdTypeOptions())->required(),
            Select::make('blocking_level')->options([
                Hold::BlockingEnrollment => 'Blocks Enrollment', Hold::BlockingCorPrint => 'Blocks COR Print',
                Hold::BlockingClearance => 'Blocks Clearance', Hold::BlockingRecordRelease => 'Blocks Record Release',
                Hold::BlockingGraduationEligibility => 'Blocks Graduation Eligibility', Hold::BlockingReactivation => 'Blocks Reactivation',
                Hold::BlockingAdvisoryOnly => 'Advisory Only',
            ])->required(),
            DateTimePicker::make('effective_at')->default(now())->required(),
            DateTimePicker::make('expires_at'),
            Textarea::make('reason')->required(),
            Textarea::make('staff_only_reason'),
            Textarea::make('student_message'),
            Textarea::make('resolution_requirement')->required(),
        ];
    }

    /** @return array<string,string> */
    private static function holdTypeOptions(): array
    {
        return [
            Hold::TypeFinancial => 'Financial', Hold::TypeDocumentary => 'Documentary',
            Hold::TypeBehavioral => 'Behavioral', Hold::TypeDisciplinary => 'Disciplinary',
            Hold::TypeAcademicDeficit => 'Academic Deficit', Hold::TypePrerequisite => 'Prerequisite',
            Hold::TypeEnrollment => 'Enrollment', Hold::TypeCorDownload => 'COR Download',
            Hold::TypeClearance => 'Clearance', Hold::TypeGraduationEligibility => 'Graduation Eligibility',
            Hold::TypeReactivation => 'Reactivation', Hold::TypeTransferOut => 'Transfer Out',
            Hold::TypeRecordRelease => 'Record Release',
        ];
    }
}
