<?php

namespace App\Filament\Resources\GradeRosters\RelationManagers;

use App\Actions\Grades\RecordApprovedGradeCorrection;
use App\Actions\Grades\RecordIncResolution;
use App\Models\GradeRoster;
use App\Models\GradeRosterRow;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;

class RowsRelationManager extends RelationManager
{
    protected static string $relationship = 'rows';

    protected static ?string $title = 'Released Grade Rows';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof GradeRoster
            && $ownerRecord->state === GradeRoster::StateReleased
            && (auth()->user()?->hasRole(User::StaffRoleRegistrar) ?? false);
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->modifyQueryUsing(fn ($query) => $query->with([
                'courseEnrollment.enrollment.studentProfile',
            ]))
            ->columns([
                TextColumn::make('courseEnrollment.enrollment.studentProfile.student_number')
                    ->label('Student No.')
                    ->searchable()
                    ->placeholder('-'),
                TextColumn::make('courseEnrollment.enrollment.studentProfile.last_name')
                    ->label('Last Name')
                    ->searchable()
                    ->placeholder('-'),
                TextColumn::make('computed_average')
                    ->label('Average')
                    ->placeholder('-'),
                TextColumn::make('current_outcome_code')
                    ->label('Outcome')
                    ->badge()
                    ->placeholder('-'),
                TextColumn::make('current_outcome_category')
                    ->label('Category')
                    ->placeholder('-'),
                TextColumn::make('released_at')
                    ->label('Released')
                    ->dateTime()
                    ->placeholder('-'),
            ])
            ->recordActions([
                Action::make('resolveInc')
                    ->label('Resolve INC')
                    ->visible(fn (GradeRosterRow $record): bool => (bool) auth()->user()?->can('resolveInc', $record))
                    ->schema([
                        Select::make('replacement_code')
                            ->label('Replacement / lapsed result')
                            ->options(self::incReplacementOptions())
                            ->required(),
                        TextInput::make('authority')
                            ->label('Decision authority')
                            ->required()
                            ->maxLength(255),
                        Textarea::make('reason')
                            ->required()
                            ->maxLength(2000),
                        TextInput::make('evidence_reference')
                            ->label('Evidence reference')
                            ->maxLength(255),
                    ])
                    ->action(function (array $data, GradeRosterRow $record): void {
                        $actor = auth()->user();

                        if (! $actor instanceof User) {
                            return;
                        }

                        app(RecordIncResolution::class)->execute(
                            $record,
                            (string) $data['replacement_code'],
                            (string) $data['authority'],
                            (string) $data['reason'],
                            $data['evidence_reference'] === null ? null : (string) $data['evidence_reference'],
                            $actor,
                        );

                        Notification::make()->title('INC resolution recorded')->success()->send();
                    }),
                Action::make('recordCorrection')
                    ->label('Record Correction')
                    ->visible(fn (GradeRosterRow $record): bool => (bool) auth()->user()?->can('recordCorrection', $record))
                    ->schema([
                        Select::make('corrected_code')
                            ->label('Corrected grade')
                            ->options(self::correctionOptions())
                            ->required(),
                        TextInput::make('authority')
                            ->label('Approving authority')
                            ->required()
                            ->maxLength(255),
                        Textarea::make('reason')
                            ->required()
                            ->maxLength(2000),
                        TextInput::make('evidence_reference')
                            ->label('Evidence reference')
                            ->maxLength(255),
                    ])
                    ->action(function (array $data, GradeRosterRow $record): void {
                        $actor = auth()->user();

                        if (! $actor instanceof User) {
                            return;
                        }

                        app(RecordApprovedGradeCorrection::class)->execute(
                            $record,
                            (string) $data['corrected_code'],
                            (string) $data['authority'],
                            (string) $data['reason'],
                            $data['evidence_reference'] === null ? null : (string) $data['evidence_reference'],
                            $actor,
                        );

                        Notification::make()->title('Posted grade correction recorded')->success()->send();
                    }),
            ])
            ->toolbarActions([]);
    }

    /**
     * Numeric scale codes plus the Pending mark; the lapsed INC result is a numeric scale code.
     *
     * @return array<string, string>
     */
    private static function incReplacementOptions(): array
    {
        $options = self::numericScaleOptions();
        $options['P'] = 'P (Pending Grade)';

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private static function correctionOptions(): array
    {
        $options = self::numericScaleOptions();
        $options['P'] = 'P (Pending Grade)';
        $options['INC'] = 'INC (Incomplete)';

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private static function numericScaleOptions(): array
    {
        $options = [];

        foreach (Config::array('grades.servitech_v1.scale') as $band) {
            $code = (string) $band['code'];
            $options[$code] = $code.' ('.((string) $band['category']).')';
        }

        return $options;
    }
}
