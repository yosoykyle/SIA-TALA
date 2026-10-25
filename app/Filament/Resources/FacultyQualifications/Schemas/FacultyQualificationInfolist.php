<?php

namespace App\Filament\Resources\FacultyQualifications\Schemas;

use App\Filament\Resources\FacultyQualifications\FacultyQualificationResource;
use App\Filament\Resources\FacultyQualifications\Pages\ViewFacultyQualification;
use App\Models\CourseSpecification;
use App\Models\FacultyQualification;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Livewire\Component as LivewireComponent;

class FacultyQualificationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Approved qualification')
                    ->description('This qualification applies across Terms.')
                    ->schema([
                        Group::make([
                            TextEntry::make('faculty.name')->label('Teacher')->placeholder('Unavailable'),
                            TextEntry::make('approved_course_title')
                                ->label('Approved course title')
                                ->state(fn (FacultyQualification $record): ?string => $record->course?->specifications()
                                    ->where('state', CourseSpecification::StateActive)
                                    ->latest('id')
                                    ->value('title'))
                                ->placeholder('No active course title recorded'),
                            TextEntry::make('course.code')->label('Course code')->placeholder('Unavailable'),
                            TextEntry::make('is_active')
                                ->label('Status')
                                ->formatStateUsing(fn (bool $state): string => $state ? 'Active' : 'Inactive')
                                ->icon(fn (bool $state): Heroicon => $state ? Heroicon::CheckCircle : Heroicon::MinusCircle)
                                ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
                        ])->columns(2),
                        Section::make('Recorded evidence')
                            ->contained(false)
                            ->schema([
                                TextEntry::make('recorder.name')->label('Recorded by')->placeholder('Unavailable'),
                                TextEntry::make('recorded_at')
                                    ->label('Recorded at (Asia/Manila)')
                                    ->dateTime('M j, Y, g:i A')
                                    ->timezone('Asia/Manila')
                                    ->placeholder('Unavailable'),
                                TextEntry::make('notes')->label('Notes')->placeholder('No notes recorded')->columnSpanFull(),
                            ])
                            ->columns(2),
                    ])
                    ->columns(1)
                    ->divided()
                    ->columnSpanFull(),
                Actions::make([
                    Action::make('backToQualifications')
                        ->label('Back to qualifications')
                        ->visible(fn (LivewireComponent $livewire): bool => ! ($livewire instanceof ViewFacultyQualification && $livewire->termId !== null))
                        ->color('gray')
                        ->url(fn (LivewireComponent $livewire): string => $livewire instanceof ViewFacultyQualification
                            ? $livewire->getResourceUrl('index')
                            : FacultyQualificationResource::getUrl('index')),
                ])->columnSpanFull(),
            ]);
    }
}
