<?php

namespace App\Filament\Resources\Sections\Schemas;

use App\Filament\Pages\TermClasses;
use App\Filament\Resources\Sections\Pages\CreateSection;
use App\Filament\Resources\Sections\Pages\EditSection;
use App\Filament\Resources\Sections\Pages\ListSections;
use App\Models\Section;
use App\Models\TermOffering;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section as FormSection;
use Filament\Schemas\Schema;

class SectionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                FormSection::make(fn (CreateSection|EditSection|ListSections|TermClasses $livewire): string => $livewire instanceof EditSection && $livewire->isSourceProtected() ? 'Class settings' : 'Class')
                    ->extraAttributes(fn (CreateSection|EditSection|ListSections|TermClasses $livewire): array => $livewire instanceof EditSection && $livewire->isPublished() ? ['class' => 'tala-class-published-banner'] : [])
                    ->description(fn (CreateSection|EditSection|ListSections|TermClasses $livewire): string => $livewire instanceof EditSection && $livewire->isPublished()
                        ? 'This class is in the official timetable. The course, class code, capacity and state are protected; make changes through a validated timetable revision.'
                        : ($livewire instanceof EditSection && $livewire->isSourceProtected()
                            ? 'This confirmed class identity, capacity and state are protected.'
                            : 'One course taught to one or more linked student sections.'))
                    ->icon(fn (CreateSection|EditSection|ListSections|TermClasses $livewire): ?string => $livewire instanceof EditSection && $livewire->isPublished() ? 'heroicon-o-lock-closed' : null)
                    ->headerActions([
                        Action::make('openOfficialTimetable')
                            ->label('Open timetable')
                            ->visible(fn (CreateSection|EditSection|ListSections|TermClasses $livewire): bool => $livewire instanceof EditSection && $livewire->isPublished())
                            ->url(fn (EditSection $livewire): string => $livewire->publishedTimetableUrl()),
                    ])
                    ->schema([
                        Select::make('term_offering_id')
                            ->label('Offered course')
                            ->options(fn (CreateSection|EditSection|ListSections|TermClasses $livewire): array => self::termOfferingOptions(
                                $livewire->termId,
                                $livewire->termId !== null,
                            ))
                            ->searchable()
                            ->preload()
                            ->disabled(fn (CreateSection|EditSection|ListSections|TermClasses $livewire): bool => $livewire instanceof EditSection && $livewire->isSourceProtected())
                            ->required(),
                        TextInput::make('code')
                            ->label('Class code')
                            ->disabled(fn (CreateSection|EditSection|ListSections|TermClasses $livewire): bool => $livewire instanceof EditSection && $livewire->isSourceProtected())
                            ->required()
                            ->maxLength(255),
                        TextInput::make('capacity')
                            ->label('Capacity')
                            ->required()
                            ->integer()
                            ->minValue(1)
                            ->helperText('Maximum learners in this class.')
                            ->disabled(fn (CreateSection|EditSection|ListSections|TermClasses $livewire): bool => $livewire instanceof EditSection && $livewire->isSourceProtected()),
                        Select::make('state')
                            ->visible(fn ($livewire): bool => $livewire instanceof EditSection)
                            ->options(Section::stateOptions())
                            ->default(Section::StatePlanned)
                            ->disabled(fn (CreateSection|EditSection|ListSections|TermClasses $livewire): bool => $livewire instanceof EditSection && $livewire->isSourceProtected())
                            ->required(),
                    ])
                    ->contained(fn ($livewire): bool => ! ($livewire instanceof TermClasses))
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }

    /**
     * @return array<int, string>
     */
    private static function termOfferingOptions(?int $termId, bool $omitTerm = false): array
    {
        return TermOffering::query()
            ->when($termId !== null, fn ($query) => $query->where('term_id', $termId))
            ->with(['term', 'curriculumEntry.courseSpecification.course'])
            ->orderByDesc('id')
            ->get()
            ->mapWithKeys(fn (TermOffering $offering): array => [
                $offering->id => collect([
                    $offering->curriculumEntry?->courseSpecification?->title ?: 'Course title unavailable',
                    $offering->curriculumEntry?->courseSpecification?->course?->code,
                    ...($omitTerm ? [] : [$offering->term?->label]),
                    str(strtolower((string) $offering->delivery_variant))->replace('_', ' ')->headline()->toString(),
                    TermOffering::modalityOptions()[$offering->modality] ?? str((string) $offering->modality)->headline()->toString(),
                    "{$offering->expected_count} expected learners",
                ])->filter()->implode(' | '),
            ])
            ->all();
    }
}
