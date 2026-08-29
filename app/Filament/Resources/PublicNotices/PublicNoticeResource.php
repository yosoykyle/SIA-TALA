<?php

namespace App\Filament\Resources\PublicNotices;

use App\Filament\Clusters\PublicContent;
use App\Filament\Resources\PublicNotices\Pages\CreatePublicNotice;
use App\Filament\Resources\PublicNotices\Pages\EditPublicNotice;
use App\Filament\Resources\PublicNotices\Pages\ListPublicNotices;
use App\Filament\Resources\PublicNotices\Schemas\PublicNoticeForm;
use App\Filament\Resources\PublicNotices\Tables\PublicNoticesTable;
use App\Models\PublicNotice;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class PublicNoticeResource extends Resource
{
    protected static ?string $model = PublicNotice::class;

    protected static ?string $cluster = PublicContent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static ?string $navigationLabel = 'Notices';

    protected static ?string $pluralModelLabel = 'Notices';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return PublicNoticeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PublicNoticesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPublicNotices::route('/'),
            'create' => CreatePublicNotice::route('/create'),
            'edit' => EditPublicNotice::route('/{record}/edit'),
        ];
    }
}
