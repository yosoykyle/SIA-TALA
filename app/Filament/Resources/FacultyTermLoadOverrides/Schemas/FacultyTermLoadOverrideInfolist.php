<?php

namespace App\Filament\Resources\FacultyTermLoadOverrides\Schemas;

use App\Filament\Resources\FacultyTermLoadOverrides\FacultyTermLoadOverrideResource;
use App\Filament\Resources\FacultyTermLoadOverrides\Pages\ViewFacultyTermLoadOverride;
use App\Models\FacultyTermLoadOverride;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Livewire\Component as LivewireComponent;

class FacultyTermLoadOverrideInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Approved teaching load')
                    ->description(fn (FacultyTermLoadOverride $record): string => $record->is_active
                        ? 'The base limit plus approved additional units replaces this Term’s default for this teacher.'
                        : 'This inactive exception does not replace the Term’s default for this teacher.')
                    ->schema([
                        Group::make([
                            TextEntry::make('faculty.name')->label('Teacher')->placeholder('Unavailable'),
                            TextEntry::make('is_active')
                                ->label('Status')
                                ->formatStateUsing(fn (bool $state): string => $state ? 'Active' : 'Inactive')
                                ->icon(fn (bool $state): string => $state ? 'heroicon-o-check-circle' : 'heroicon-o-minus-circle')
                                ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
                            TextEntry::make('term.label')->label('Term')->placeholder('Unavailable'),
                            TextEntry::make('default_max_units_snapshot')
                                ->label('Base limit')
                                ->formatStateUsing(fn (mixed $state): string => self::formatUnits((float) $state)),
                            TextEntry::make('approved_overload_units')
                                ->label('Approved additional units')
                                ->formatStateUsing(fn (mixed $state): string => self::formatUnits((float) $state)),
                            TextEntry::make('allowed_load_units')
                                ->label('Allowed teaching load')
                                ->state(fn (FacultyTermLoadOverride $record): float => $record->allowedLoadUnits())
                                ->formatStateUsing(fn (mixed $state): string => self::formatUnits((float) $state)),
                        ])->columns(2),
                        Section::make('Approval evidence')
                            ->contained(false)
                            ->schema([
                                TextEntry::make('authority')->label('Approval reference')->placeholder('Not recorded'),
                                TextEntry::make('reason')->label('Reason')->placeholder('Not recorded')->columnSpanFull(),
                            ])
                            ->columns(1),
                    ])
                    ->columns(1)
                    ->divided()
                    ->columnSpanFull(),
                Actions::make([
                    Action::make('backToLoadExceptions')
                        ->label('Back to load exceptions')
                        ->visible(fn (LivewireComponent $livewire): bool => ! ($livewire instanceof ViewFacultyTermLoadOverride && $livewire->termId !== null))
                        ->color('gray')
                        ->url(fn (LivewireComponent $livewire): string => $livewire instanceof ViewFacultyTermLoadOverride
                            ? $livewire->getResourceUrl('index')
                            : FacultyTermLoadOverrideResource::getUrl('index')),
                ])->columnSpanFull(),
            ]);
    }

    private static function formatUnits(float $units): string
    {
        return rtrim(rtrim(number_format($units, 2, '.', ''), '0'), '.').' units';
    }
}
