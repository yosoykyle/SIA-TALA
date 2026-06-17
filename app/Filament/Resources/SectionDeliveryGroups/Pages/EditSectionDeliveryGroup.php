<?php

namespace App\Filament\Resources\SectionDeliveryGroups\Pages;

use App\Actions\Scheduling\SectionDeliveryGroupService;
use App\Filament\Resources\SectionDeliveryGroups\SectionDeliveryGroupResource;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\User;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditSectionDeliveryGroup extends EditRecord
{
    protected static string $resource = SectionDeliveryGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var SectionDeliveryGroup $record */
        $section = Section::query()->findOrFail((int) ($data['section_id'] ?? $record->section_id));
        $actor = auth()->user();

        return app(SectionDeliveryGroupService::class)->save(
            $section,
            $data,
            $record,
            $actor instanceof User ? $actor : null,
        );
    }
}
