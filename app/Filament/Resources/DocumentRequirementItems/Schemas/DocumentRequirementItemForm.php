<?php

namespace App\Filament\Resources\DocumentRequirementItems\Schemas;

use App\Models\AdmissionRequirementPolicy;
use App\Models\DocumentRequirementItem;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DocumentRequirementItemForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Requirement Item')
                    ->description('Each row defines one applicant checklist obligation and whether it blocks admission or becomes a retention follow-up.')
                    ->schema([
                        Select::make('admission_requirement_policy_id')
                            ->label('Requirement Policy')
                            ->options(fn (): array => AdmissionRequirementPolicy::query()
                                ->with('admissionOffering.term')
                                ->orderByDesc('id')
                                ->get()
                                ->mapWithKeys(fn (AdmissionRequirementPolicy $policy): array => [$policy->id => $policy->displayLabel()])
                                ->all())
                            ->required()
                            ->searchable(),
                        TextInput::make('key')
                            ->required()
                            ->maxLength(255)
                            ->alphaDash()
                            ->helperText('Stable machine key, for example psa_birth_certificate.'),
                        TextInput::make('label')
                            ->required()
                            ->maxLength(255),
                        Select::make('gate_type')
                            ->options(DocumentRequirementItem::gateTypeOptions())
                            ->required()
                            ->default(DocumentRequirementItem::GateTypeAdmission),
                        TextInput::make('sort_order')
                            ->required()
                            ->integer()
                            ->minValue(0)
                            ->default(0),
                        CheckboxList::make('permitted_evidence_methods')
                            ->options(DocumentRequirementItem::evidenceMethodOptions())
                            ->required()
                            ->columns(2)
                            ->helperText('Applicant uploads remain preliminary until an authorized Registrar review satisfies the requirement.'),
                        Select::make('storage_class')
                            ->options(DocumentRequirementItem::storageClassOptions())
                            ->required()
                            ->default(DocumentRequirementItem::StorageClassCredentialFile),
                        Select::make('sensitivity_class')
                            ->options(DocumentRequirementItem::sensitivityClassOptions())
                            ->required()
                            ->default(DocumentRequirementItem::SensitivityStandard),
                        TextInput::make('deadline_strategy')
                            ->maxLength(255)
                            ->helperText('Optional strategy label for retention deadlines.'),
                        TextInput::make('retention_policy')
                            ->maxLength(255)
                            ->helperText('Optional follow-up/hold policy label.'),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }
}
