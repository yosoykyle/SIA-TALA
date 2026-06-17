<?php

namespace App\Filament\Resources\SectionDeliveryGroups\Pages;

use App\Actions\Scheduling\SectionDeliveryGroupService;
use App\Filament\Resources\SectionDeliveryGroups\SectionDeliveryGroupResource;
use App\Models\Section;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateSectionDeliveryGroup extends CreateRecord
{
    protected static string $resource = SectionDeliveryGroupResource::class;

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    protected function handleRecordCreation(array $data): Model
    {
        $section = Section::query()->findOrFail((int) $data['section_id']);
        $actor = auth()->user();

        return app(SectionDeliveryGroupService::class)->save(
            $section,
            $data,
            null,
            $actor instanceof User ? $actor : null,
        );
    }
}
