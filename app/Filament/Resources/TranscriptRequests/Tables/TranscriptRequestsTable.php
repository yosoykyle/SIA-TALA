<?php

namespace App\Filament\Resources\TranscriptRequests\Tables;

use App\Actions\Completion\TranscriptLifecycleProjection;
use App\Models\Term;
use App\Models\TranscriptRequest;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TranscriptRequestsTable
{
    public static function statusLabel(string $state): string
    {
        return match (strtolower($state)) {
            'open' => 'Open',
            'issued' => 'Issued',
            'replacement' => 'Replacement issued',
            'voided' => 'Voided',
            'superseded' => 'Superseded',
            default => ucfirst($state),
        };
    }

    public static function statusColor(string $state): string
    {
        return match (strtolower($state)) {
            'issued', 'replacement' => 'success',
            'open' => 'warning',
            'voided' => 'danger',
            default => 'gray',
        };
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->deferFilters(false)
            ->extraAttributes(['class' => 'tala-data-table'])
            ->emptyState(fn (Table $table, $livewire) => $livewire->hasTableSearch() ? null : view('filament.components.accounting-table-empty-state', ['table' => $table]))
            ->columns([
                TextColumn::make('studentProfile.last_name')->label('Student')
                    ->formatStateUsing(fn ($record): string => $record->studentProfile->last_name.', '.$record->studentProfile->first_name)
                    ->description(fn ($record): string => $record->studentProfile->student_number)
                    ->searchable(['last_name', 'first_name', 'student_number']),
                TextColumn::make('external_request_reference')->label('Request')->searchable()
                    ->description(fn ($record): string => 'Requested '.$record->requested_on->format('M j, Y')),
                TextColumn::make('due_on')->label('Due date')->date('M j, Y')->sortable()
                    ->description(fn ($record): string => $record->state !== TranscriptRequest::StateIssued && $record->due_on->endOfDay()->isPast() ? 'Due date passed · follow up' : '30 days from the actual request'),
                TextColumn::make('lifecycle_state')->label('Status')
                    ->state(fn ($record): string => app(TranscriptLifecycleProjection::class)->statusForRequest($record))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::statusLabel($state))
                    ->color(fn (string $state): string => self::statusColor($state))
                    ->description(fn ($record): string => $record->officialOutputRestrictions->whereNull('resolved_at')->isNotEmpty() ? 'Active TOR hold · open request' : 'No active TOR restriction'),
            ])
            ->searchPlaceholder('Student or request reference')
            ->filters([
                SelectFilter::make('restriction')
                    ->label('Accounting restriction')
                    ->placeholder('All requests')
                    ->native(false)
                    ->options([
                        'active' => 'Active TOR hold',
                        'none' => 'No active TOR restriction',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'active' => $query->whereHas('officialOutputRestrictions', fn (Builder $restriction): Builder => $restriction->whereNull('resolved_at')),
                        'none' => $query->whereDoesntHave('officialOutputRestrictions', fn (Builder $restriction): Builder => $restriction->whereNull('resolved_at')),
                        default => $query,
                    }),
                SelectFilter::make('state')
                    ->label('Status')
                    ->placeholder('All statuses')
                    ->native(false)
                    ->options([
                        TranscriptRequest::StateOpen => 'Open',
                        TranscriptRequest::StateIssued => 'Issued',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null) ? $query->where('state', (string) $data['value']) : $query),
                SelectFilter::make('term')
                    ->label('Term')
                    ->placeholder('All terms')
                    ->searchable()
                    ->preload()
                    ->options(fn (): array => Term::query()
                        ->with('academicYear')
                        ->orderByDesc('starts_on')
                        ->get()
                        ->mapWithKeys(fn (Term $term): array => [
                            $term->getKey() => collect([$term->academicYear?->label, $term->label])->filter()->implode(' · '),
                        ])
                        ->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas('studentProfile.enrollments', fn (Builder $enrollment): Builder => $enrollment->where('term_id', (int) $data['value']))
                        : $query),
            ])
            ->recordActions([ViewAction::make()->label('Open request')->icon(Heroicon::Eye)])
            ->emptyStateHeading(fn ($livewire): string => $livewire->hasTableSearch() ? 'No matching TOR requests' : 'No TOR requests recorded')
            ->emptyStateDescription(fn ($livewire): string => $livewire->hasTableSearch() ? 'Try another student name, number or request reference.' : 'The Registrar records an actual request from Completion & TOR. Graduation is not a prerequisite.')
            ->stackedOnMobile()
            ->defaultSort('requested_on', 'asc');
    }
}
