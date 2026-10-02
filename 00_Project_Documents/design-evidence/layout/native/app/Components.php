<?php

namespace TalaPreview;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class Components extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-squares-2x2';
    protected static ?string $title = 'Native components';
    protected static ?string $navigationLabel = 'Components';
    protected static ?int $navigationSort = 1;
    protected ?string $subheading = 'Filament components with TALA colors and restrained surface styling.';
    protected string $view = 'components';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(['name' => 'Alex Santos', 'email' => 'alex@example.test', 'program' => 'bsit', 'reference' => 'DEMO-2026-001', 'notifications' => true, 'delivery' => 'email', 'confirmed' => false]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('Student details')
                ->description('Fictional fields demonstrating native layout, validation, and control states.')
                ->compact()
                ->columns(['default' => 1, 'md' => 2])
                ->schema([
                    TextInput::make('name')->label('Full name')->required()->maxLength(100),
                    TextInput::make('email')->label('Email address')->email()->required(),
                    Select::make('program')->options(['bsit' => 'BS Information Technology', 'bsba' => 'BS Business Administration'])->searchable()->required(),
                    TextInput::make('reference')->label('Reference number')->default('DEMO-2026-001')->disabled(),
                    Radio::make('delivery')->label('Preferred contact')->options(['email' => 'Email', 'phone' => 'Phone'])->inline(),
                    Toggle::make('notifications')->label('Receive updates')->helperText('Demonstrates a native on/off control.'),
                    Checkbox::make('confirmed')->label('I have reviewed these fictional details')->columnSpanFull(),
                ]),
        ]);
    }

    public function save(): void
    {
        $this->form->getState();
        Notification::make()->title('Example validated')->body('No school record was created or changed.')->success()->send();
    }

    public function confirmationAction(): Action
    {
        return Action::make('confirmation')
            ->label('Open confirmation')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Confirm the example action?')
            ->modalDescription('This uses Filament’s native dialog, focus handling, and action lifecycle. No real record is affected.')
            ->modalSubmitActionLabel('Confirm example')
            ->action(fn () => Notification::make()->title('Example confirmed')->success()->send());
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Sample records')
            ->description('Native table search, sorting, badges, and record actions. All rows are fictional.')
            ->records(function (?string $search, ?string $sortColumn, ?string $sortDirection): Collection {
                return collect([
                    'DEMO-001' => ['name' => 'Alex Santos', 'program' => 'BS Information Technology', 'status' => 'Complete'],
                    'DEMO-002' => ['name' => 'Jamie Reyes', 'program' => 'BS Business Administration', 'status' => 'Needs review'],
                    'DEMO-003' => ['name' => 'Morgan Cruz', 'program' => 'BS Information Technology', 'status' => 'Pending'],
                ])->when(filled($search), fn (Collection $rows): Collection => $rows->filter(fn (array $record): bool => str_contains(Str::lower(implode(' ', $record)), Str::lower($search))))
                    ->when(filled($sortColumn), fn (Collection $rows): Collection => $rows->sortBy($sortColumn, SORT_REGULAR, $sortDirection === 'desc'));
            })
            ->searchable()
            ->columns([
                TextColumn::make('name')->label('Student')->sortable()->weight('medium'),
                TextColumn::make('program')->label('Program')->wrap(),
                TextColumn::make('status')->badge()->color(fn (string $state): string => match ($state) {'Complete' => 'success', 'Needs review' => 'warning', default => 'gray'}),
            ])
            ->recordActions([
                Action::make('view')->label('View')->icon('heroicon-o-eye')->modalHeading(fn (array $record): string => $record['name'])->modalDescription(fn (array $record): string => $record['program'].' · '.$record['status'])->modalSubmitAction(false)->modalCancelActionLabel('Close'),
            ])
            ->emptyStateHeading('No matching sample records')
            ->emptyStateDescription('Clear the search to see the fictional records.');
    }
}
