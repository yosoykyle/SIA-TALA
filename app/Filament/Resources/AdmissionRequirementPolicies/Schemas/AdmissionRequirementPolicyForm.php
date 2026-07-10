<?php

namespace App\Filament\Resources\AdmissionRequirementPolicies\Schemas;

use App\Models\AdmissionRequirementPolicy;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AdmissionRequirementPolicyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Admission Requirement Policy')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                Select::make('admission_category')
                                    ->options(AdmissionRequirementPolicy::admissionCategoryOptions())
                                    ->required()
                                    ->native(false),
                                Select::make('credential_basis')
                                    ->options(AdmissionRequirementPolicy::credentialBasisOptions())
                                    ->required()
                                    ->native(false),
                                Select::make('requirement_type')
                                    ->options(AdmissionRequirementPolicy::requirementTypeOptions())
                                    ->required()
                                    ->native(false),
                                Select::make('evidence_method')
                                    ->options(AdmissionRequirementPolicy::evidenceMethodOptions())
                                    ->required()
                                    ->native(false),
                                Select::make('blocking_level')
                                    ->options(AdmissionRequirementPolicy::blockingLevelOptions())
                                    ->required()
                                    ->native(false),
                                Select::make('state')
                                    ->options(AdmissionRequirementPolicy::statusOptions())
                                    ->default(AdmissionRequirementPolicy::StateDraft)
                                    ->required()
                                    ->native(false),
                                DatePicker::make('effective_from')
                                    ->required(),
                                DatePicker::make('effective_until')
                                    ->helperText('Leave blank while this policy remains in effect.'),
                                TextInput::make('authority')
                                    ->required()
                                    ->maxLength(255)
                                    ->columnSpanFull(),
                            ]),
                    ]),
            ]);
    }
}
