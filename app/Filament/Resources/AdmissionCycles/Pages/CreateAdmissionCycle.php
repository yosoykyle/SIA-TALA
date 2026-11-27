<?php

namespace App\Filament\Resources\AdmissionCycles\Pages;

use App\Filament\Resources\AdmissionApplications\AdmissionApplicationResource;
use App\Filament\Resources\AdmissionCycles\AdmissionCycleResource;
use App\Models\AdmissionCycle;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;

class CreateAdmissionCycle extends CreateRecord
{
    protected static string $resource = AdmissionCycleResource::class;

    protected static bool $canCreateAnother = false;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $defaults = AdmissionCycle::defaultGuidance();

        $data['registrar_owner_id'] = $data['registrar_owner_id'] ?? auth()->id();
        $data['applicant_instructions'] = filled($data['applicant_instructions'] ?? null)
            ? $data['applicant_instructions']
            : $defaults['applicant_instructions'];
        $data['support_contact'] = filled($data['support_contact'] ?? null)
            ? $data['support_contact']
            : $defaults['support_contact'];
        $data['privacy_notice_reference'] = filled($data['privacy_notice_reference'] ?? null)
            ? $data['privacy_notice_reference']
            : $defaults['privacy_notice_reference'];

        return $data;
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label('Save & continue to requirements');
    }

    protected function getRedirectUrl(): string
    {
        return AdmissionCycleResource::getUrl('view', [
            'record' => $this->getRecord(),
            'relation' => '0',
        ]);
    }

    public function getTitle(): string
    {
        return 'Prepare admission cycle';
    }

    public function getBreadcrumbs(): array
    {
        return [
            ...(AdmissionApplicationResource::canAccess() ? [AdmissionApplicationResource::getUrl() => 'Admissions'] : []),
            AdmissionCycleResource::getUrl() => 'Admission cycles',
            'Prepare cycle',
        ];
    }
}
