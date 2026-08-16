<?php

namespace App\Filament\Resources\AdmissionApplications;

use App\Filament\Resources\AdmissionApplications\Pages\ListAdmissionApplications;
use App\Filament\Resources\AdmissionApplications\Pages\ViewAdmissionApplication;
use App\Filament\Resources\AdmissionApplications\Schemas\AdmissionApplicationInfolist;
use App\Filament\Resources\AdmissionApplications\Tables\AdmissionApplicationsTable;
use App\Models\AdmissionApplication;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class AdmissionApplicationResource extends Resource
{
    protected static ?string $model = AdmissionApplication::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxStack;

    protected static string|UnitEnum|null $navigationGroup = 'Registrar';

    protected static ?string $navigationLabel = 'Admissions';

    protected static ?string $pluralModelLabel = 'Admissions';

    protected static ?string $recordTitleAttribute = 'application_reference';

    public static function infolist(Schema $schema): Schema
    {
        return AdmissionApplicationInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AdmissionApplicationsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAdmissionApplications::route('/'),
            'view' => ViewAdmissionApplication::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /** @return Builder<AdmissionApplication> */
    public static function getEloquentQuery(): Builder
    {
        return AdmissionApplication::query()
            ->canonical()
            ->with([
                'user',
                'admissionCycle',
                'term',
                'program',
                'currentSubmissionVersion.requirementSet',
                'correctionRequests.items',
                'decisions',
                'credentialResults.requirement',
                'identityMatchReviews',
                'evidenceVersions.admissionRequirement',
                'evidenceVersions.preliminaryReviews',
                'events',
            ]);
    }
}
