<?php

namespace App\Filament\Support;

use App\Actions\PublicContent\ManagePublicContent;
use App\Models\FaqEntry;
use App\Models\PublicNotice;
use Filament\Actions\Action;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use LogicException;

trait EditsPublicContent
{
    #[Locked]
    public int $loadedRevision = 1;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $this->loadedRevision = (int) $data['revision'];

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof PublicNotice && ! $record instanceof FaqEntry) {
            throw new LogicException('Public Content editing requires a notice or FAQ record.');
        }

        try {
            $saved = app(ManagePublicContent::class)->save($record, auth()->user(), $data, $this->loadedRevision);
        } catch (ValidationException $exception) {
            PublicContentActions::reportFailure($exception);
            throw new Halt;
        }
        $this->record = $saved;
        $this->loadedRevision = $saved->revision;

        return $saved;
    }

    protected function getSaveFormAction(): Action
    {
        $record = $this->getRecord();
        if (! $record instanceof PublicNotice && ! $record instanceof FaqEntry) {
            throw new LogicException('Public Content editing requires a notice or FAQ record.');
        }

        return parent::getSaveFormAction()->label($record->wasPublished() ? 'Save successor draft' : 'Save draft');
    }

    protected function getRedirectUrl(): ?string
    {
        return $this->getResourceUrl('edit', ['record' => $this->getRecord()]);
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Draft saved. Review and publish it from Public Content.';
    }
}
