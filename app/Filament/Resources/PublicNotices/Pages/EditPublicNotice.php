<?php

namespace App\Filament\Resources\PublicNotices\Pages;

use App\Filament\Resources\PublicNotices\PublicNoticeResource;
use App\Filament\Support\EditsPublicContent;
use Filament\Resources\Pages\EditRecord;

class EditPublicNotice extends EditRecord
{
    use EditsPublicContent;

    protected static string $resource = PublicNoticeResource::class;
}
