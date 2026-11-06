<?php

namespace App\Filament\Resources\TranscriptRequests\Schemas;

use App\Actions\Completion\TranscriptLifecycleProjection;
use App\Actions\Completion\TranscriptPreview;
use App\Models\TranscriptIssuanceEvent;
use App\Models\TranscriptRequest;
use App\Models\User;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;

class TranscriptRequestInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Request')->columnSpanFull()->columns(['default' => 2, 'xl' => 4])->schema([
                TextEntry::make('student_name')->label('Student')->state(fn (TranscriptRequest $record): string => $record->studentProfile->last_name.', '.$record->studentProfile->first_name)
                    ->helperText(fn (TranscriptRequest $record): string => $record->studentProfile->student_number),
                TextEntry::make('external_request_reference')->label('Request reference'),
                TextEntry::make('requested_on')->label('Actual request date')->date('M j, Y'),
                TextEntry::make('due_on')->label('Due date')->date('M j, Y')
                    ->helperText('30 days from the actual request. Resolving blockers does not reset this date.'),
            ]),
            Section::make('Issuance readiness')->columnSpanFull()->columns(2)
                ->description('TALA records issuance. Physical signature, seal and delivery are handled by the school.')->schema([
                    TextEntry::make('lifecycle_state')->label('Current TOR')->state(fn (TranscriptRequest $record): string => app(TranscriptLifecycleProjection::class)->statusForRequest($record))->badge(),
                    TextEntry::make('accounting_restrictions')->label('Accounting')->state(fn (TranscriptRequest $record): string => $record->officialOutputRestrictions->whereNull('resolved_at')->isEmpty()
                        ? 'No active TOR restriction' : $record->officialOutputRestrictions->whereNull('resolved_at')->pluck('reason')->implode('; ')),
                    TextEntry::make('preparation')->label('Next action')->columnSpanFull()->state(function (TranscriptRequest $record): string {
                        if (! (auth()->user()?->hasRole(User::StaffRoleRegistrar) ?? false)) {
                            return 'The Registrar verifies academic sources, prepares the document and reviews its preview before issuance.';
                        }
                        if (app(TranscriptLifecycleProjection::class)->currentSnapshot($record) !== null) {
                            return 'An official TOR has been issued. Its content remains immutable in issuance history.';
                        }
                        try {
                            app(TranscriptPreview::class)->forRequest($record);

                            return 'Ready to preview. Review the exact document, then choose Issue TOR.';
                        } catch (ValidationException $exception) {
                            return collect($exception->errors())->flatten()->implode(' ');
                        }
                    }),
                ]),
            Section::make('Document inputs')->columnSpanFull()->collapsible()->collapsed()->columns(2)->schema([
                TextEntry::make('template_version')->label('Template'),
                TextEntry::make('signatory_name')->label('Signatory')->placeholder('Not prepared'),
                TextEntry::make('signatory_title')->label('Signatory title')->placeholder('Not prepared'),
                TextEntry::make('seal_placement_instruction')->label('Seal placement')->placeholder('Not prepared'),
            ]),
            Section::make('Restriction history')->columnSpanFull()->collapsible()->collapsed()
                ->visible(fn (TranscriptRequest $record): bool => $record->officialOutputRestrictions->isNotEmpty())->schema([
                    RepeatableEntry::make('officialOutputRestrictions')->schema([
                        TextEntry::make('reason')->label('TOR restriction'),
                        TextEntry::make('source_reference')->label('Source reference')->placeholder('None supplied'),
                        TextEntry::make('recorder.name')->label('Recorded by'),
                        TextEntry::make('recorded_at')->label('Recorded')->dateTime(),
                        TextEntry::make('resolved_at')->label('Resolved')->dateTime()->placeholder('Active · Accounting must resolve'),
                        TextEntry::make('resolution_reason')->label('Resolution')->placeholder('Not resolved'),
                        TextEntry::make('resolver.name')->label('Resolved by')->placeholder('Not resolved'),
                    ])->columns(2),
                ]),
            Section::make('Issuance history')->columnSpanFull()->collapsible()->collapsed()->schema([
                RepeatableEntry::make('events')->schema([
                    TextEntry::make('type')->badge(),
                    TextEntry::make('reference')->url(fn (TranscriptIssuanceEvent $record): ?string => (auth()->user()?->hasRole(User::StaffRoleRegistrar) ?? false) && $record->transcript_snapshot_id !== null ? route('transcript-snapshots.show', $record->transcript_snapshot_id) : null),
                    TextEntry::make('authority_reference')->label('External reference')->placeholder('Actor and time recorded by TALA'),
                    TextEntry::make('reason')->placeholder('Not applicable'),
                    TextEntry::make('recorded_at')->dateTime(),
                    TextEntry::make('recorder.name')->label('Recorded by'),
                ])->columns(3),
            ]),
            Section::make('Earlier request clearance records')->columnSpanFull()->collapsible()->collapsed()
                ->description('Historical evidence only. These entries do not determine current TOR readiness.')
                ->visible(fn (TranscriptRequest $record): bool => $record->clearances->isNotEmpty())->schema([
                    RepeatableEntry::make('clearances')->schema([
                        TextEntry::make('state')->badge(),
                        TextEntry::make('safe_reason')->label('Recorded reason'),
                        TextEntry::make('authority_reference')->label('Recorded reference'),
                        TextEntry::make('recorded_at')->dateTime(),
                    ])->columns(2),
                ]),
        ]);
    }
}
