<?php

namespace TalaPreview;

use BackedEnum;
use Filament\Pages\Page;

class Guidance extends Page
{
    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-book-open';
    protected static ?string $title = 'Theme guidance';
    protected static ?int $navigationSort = 2;
    protected string $view = 'guidance';
}
