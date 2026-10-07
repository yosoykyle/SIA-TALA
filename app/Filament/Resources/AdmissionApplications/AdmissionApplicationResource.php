<?php

namespace App\Filament\Resources\AdmissionApplications;

use App\Filament\Clusters\Admissions;
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
use Illuminate\Database\Eloquent\Model;

class AdmissionApplicationResource extends Resource
{
    protected static ?string $model = AdmissionApplication::class;

    protected static ?string $cluster = Admissions::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxStack;

    protected static ?string $navigationLabel = 'Application queue';

    protected static ?int $navigationSort = 1;

    protected static ?string $pluralModelLabel = 'Admissions';

    protected static ?string $recordTitleAttribute = 'application_reference';

    public static function getRecordTitle(?Model $record): string
    {
        return collect([$record?->first_name, $record?->last_name])->filter()->implode(' ') ?: 'Application';
    }

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
                'currentSubmissionVersion.requirementSet.requirements',
                'correctionRequests.items',
                'decisions',
                'credentialResults.requirement',
                'identityMatchReviews',
                'evidenceVersions.admissionRequirement',
                'evidenceVersions.preliminaryReviews.reviewer',
                'evidenceVersions.preliminaryReviews.successor',
                'events',
            ]);
    }
}
