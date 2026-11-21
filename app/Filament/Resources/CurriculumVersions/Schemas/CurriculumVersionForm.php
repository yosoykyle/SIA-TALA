<?php

namespace App\Filament\Resources\CurriculumVersions\Schemas;

use App\Models\CurriculumVersion;
use App\Models\Program;
use App\Models\Term;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class CurriculumVersionForm
{
    public static function configure(Schema $schema, ?int $programId = null): Schema
    {
        return $schema->components([
            Grid::make(1)
                ->schema([
                    $programId !== null
                        ? Hidden::make('program_id')->default($programId)
                        : Select::make('program_id')
                            ->label('Program')
                            ->options(fn (): array => self::programOptions())
                            ->default(fn (): ?int => request()->integer('programId') ?: null)
                            ->searchable()
                            ->preload()
                            ->required(),
                    ...self::planFields(),
                ])
                ->columnSpanFull(),
        ]);
    }

    /** @return array<TextInput|Select> */
    public static function planFields(): array
    {
        return [
            TextInput::make('name')
                ->label('Curriculum name')
                ->placeholder('e.g. BSIT Curriculum — Batch 2026')
                ->required()
                ->maxLength(255),
            Select::make('effective_entry_term_id')->label('First student intake')
                ->helperText('New students starting from this intake will use the plan. Class dates are set in Term Planning.')
                ->options(fn (): array => self::termOptions())->searchable()->preload()->nullable(),
        ];
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function draftAttributes(array $data): array
    {
        return [...$data, 'version_code' => self::draftCodeFor((int) $data['program_id']), 'state' => CurriculumVersion::StateDraft,
            'approved_by' => null, 'approved_at' => null];
    }

    public static function draftCodeFor(int $programId): string
    {
        $program = Program::query()->findOrFail($programId);

        return Str::limit($program->code, 64, '').'-'.Str::ulid();
    }

    /**
     * @return array<int, string>
     */
    private static function programOptions(): array
    {
        return Program::query()
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn (Program $program): array => [$program->id => "{$program->code} - {$program->name}"])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private static function termOptions(): array
    {
        return Term::query()
            ->with('academicYear')
            ->orderByDesc('starts_on')
            ->get()
            ->mapWithKeys(fn (Term $term): array => [
                $term->id => collect([
                    $term->academicYear?->label,
                    $term->label,
                ])->filter()->implode(' | '),
            ])
            ->all();
    }
}
