<?php

namespace App\Filament\Student\Pages;

use App\Actions\Cor\BuildCorOutput;
use Filament\Actions\Action;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Http\Request;

class CorView extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-check';

    protected static ?string $navigationLabel = 'COR';

    protected static ?string $title = 'Certificate of Registration';

    /**
     * @var array<string, mixed>
     */
    public array $cor = [];

    public function mount(Request $request): void
    {
        $actor = auth()->user();

        abort_unless($actor !== null, 403);

        $this->cor = app(BuildCorOutput::class)->forStudent($actor);

        if (($this->cor['available'] ?? false) === true) {
            app(BuildCorOutput::class)->recordAccess($this->cor, $actor, BuildCorOutput::ActionView, $request);
        }
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->state($this->cor['state'] ?? [])
            ->components([
                Section::make('Current COR Status')
                    ->schema([
                        TextEntry::make('availability_status')
                            ->label('Status')
                            ->badge()
                            ->color(fn (string $state): string => $state === 'Available' ? 'success' : 'warning'),
                        TextEntry::make('term')->label('Term'),
                        TextEntry::make('notice')
                            ->label('Notice')
                            ->columnSpanFull(),
                    ])
                    ->columns(['default' => 1, 'sm' => 2]),
                Section::make('Student Information')
                    ->visible(fn (): bool => ($this->cor['available'] ?? false) === true)
                    ->schema([
                        TextEntry::make('student_number')->label('Student No.'),
                        TextEntry::make('student_name')->label('Full Name'),
                        TextEntry::make('program')->label('Program'),
                        TextEntry::make('curriculum_level')->label('Curriculum Level'),
                        TextEntry::make('registration_date')->label('Registration Date'),
                        TextEntry::make('course_delivery_mix')->label('Course Delivery Mix'),
                        TextEntry::make('payment_status')->label('Payment Status'),
                        TextEntry::make('balance')->label('Balance'),
                    ])
                    ->columns(['default' => 1, 'sm' => 2, 'xl' => 4]),
                Section::make('Current Enrolled Subjects')
                    ->visible(fn (): bool => ($this->cor['available'] ?? false) === true)
                    ->schema([
                        TextEntry::make('total_units')->label('Total Units'),
                        RepeatableEntry::make('subjects')
                            ->label('Subjects and Schedule')
                            ->schema([
                                TextEntry::make('subject_code')->label('Code'),
                                TextEntry::make('subject_description')->label('Description'),
                                TextEntry::make('units')->label('Units'),
                                TextEntry::make('lecture_hours')->label('Lec Hrs'),
                                TextEntry::make('laboratory_hours')->label('Lab Hrs'),
                                TextEntry::make('section')->label('Section'),
                                TextEntry::make('day')->label('Day'),
                                TextEntry::make('time')->label('Time'),
                                TextEntry::make('room')->label('Room'),
                                TextEntry::make('instructor')->label('Instructor'),
                                TextEntry::make('modality')->label('Modality')->badge(),
                            ])
                            ->columns(['default' => 1, 'sm' => 2, 'lg' => 3])
                            ->columnSpanFull(),
                    ])
                    ->columns(1),
                Section::make('Installment Schedule')
                    ->visible(fn (): bool => ($this->cor['available'] ?? false) === true
                        && (bool) ($this->cor['state']['installment_applicable'] ?? false))
                    ->schema([
                        RepeatableEntry::make('installment_rows')
                            ->label('Installments')
                            ->schema([
                                TextEntry::make('number')->label('No.'),
                                TextEntry::make('category')->label('Category'),
                                TextEntry::make('due_date')->label('Due Date'),
                                TextEntry::make('amount')->label('Amount'),
                                TextEntry::make('reference')->label('Receipt No. / Reference'),
                                TextEntry::make('date_paid')->label('Date Paid'),
                                TextEntry::make('remaining_balance')->label('Remaining'),
                            ])
                            ->columns(['default' => 1, 'sm' => 2, 'lg' => 3])
                            ->columnSpanFull(),
                    ])
                    ->columns(1),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('print')
                ->label('Print / Save as PDF')
                ->icon('heroicon-o-printer')
                ->labeledFrom('sm')
                ->tooltip('Print or save the current COR as PDF')
                ->url(fn (): string => route('cor.print', $this->cor['summary']['enrollment_id'] ?? 0))
                ->openUrlInNewTab()
                ->visible(fn (): bool => ($this->cor['available'] ?? false) === true),
        ];
    }
}
