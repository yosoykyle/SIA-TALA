<?php

namespace App\Filament\Resources\TranscriptRequests\Pages;

use App\Actions\Completion\IssueTranscript;
use App\Actions\Completion\ReplaceTranscript;
use App\Actions\Completion\VoidTranscript;
use App\Actions\Finance\RecordOfficialOutputPaymentClearance;
use App\Filament\Resources\TranscriptRequests\TranscriptRequestResource;
use App\Models\OfficialOutputPaymentClearance;
use App\Models\TranscriptRequest;
use App\Models\TranscriptSnapshot;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Throwable;

class ViewTranscriptRequest extends ViewRecord
{
    protected static string $resource = TranscriptRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('recordClearance')
                ->label('Record clearance')
                ->icon('heroicon-o-banknotes')
                ->visible(fn (): bool => auth()->user()?->hasRole(User::StaffRoleAccounting) ?? false)
                ->schema([
                    Select::make('state')->options([
                        OfficialOutputPaymentClearance::StateCleared => 'Cleared',
                        OfficialOutputPaymentClearance::StateNotRequired => 'Not required — authority backed',
                    ])->required(),
                    TextInput::make('required_amount')->numeric()->minValue(0)->prefix('PHP'),
                    TextInput::make('authority_reference')->required()->maxLength(255),
                    Textarea::make('safe_reason')->required()->maxLength(1000),
                ])
                ->action(function (array $data): void {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User, 403);
                    app(RecordOfficialOutputPaymentClearance::class)->execute(
                        $this->request(),
                        $actor,
                        (string) $data['state'],
                        (string) $data['authority_reference'],
                        (string) $data['safe_reason'],
                        filled($data['required_amount'] ?? null) ? (string) $data['required_amount'] : null,
                    );
                    Notification::make()->title('Request-specific clearance recorded')->success()->send();
                    $this->refreshFormData(['clearances']);
                }),
            Action::make('preview')
                ->label('Preview TOR')
                ->icon('heroicon-o-eye')
                ->url(fn (): string => route('transcripts.preview', $this->request()))
                ->openUrlInNewTab()
                ->visible(fn (): bool => auth()->user()?->hasRole(User::StaffRoleRegistrar) ?? false),
            Action::make('issue')
                ->label('Issue TOR')
                ->icon('heroicon-o-document-check')
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription(fn (): string => 'Issue the immutable '.$this->request()->template_version.' for request '.$this->request()->external_request_reference.'. Current Accounting clearance and signatory inputs will be revalidated. TALA records issuance only; physical signing, sealing, claiming, delivery, and CAV remain external.')
                ->schema([
                    TextInput::make('expected_reference')
                        ->label('Resulting TOR reference')
                        ->default(fn (): string => $this->nextTorReference())
                        ->readOnly()
                        ->required(),
                    TextInput::make('authority_reference')->required()->maxLength(255),
                ])
                ->visible(fn (): bool => auth()->user()?->hasRole(User::StaffRoleRegistrar) ?? false)
                ->action(function (array $data): void {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User, 403);
                    try {
                        $snapshot = app(IssueTranscript::class)->execute(
                            $this->request(),
                            $actor,
                            (string) $data['authority_reference'],
                            (string) $data['expected_reference'],
                        );
                        $this->redirect(route('transcript-snapshots.show', $snapshot));
                    } catch (Throwable $exception) {
                        report($exception);
                        Notification::make()->title('TOR was not issued')->body('Refresh the request and resolve its named readiness source before retrying.')->danger()->send();
                    }
                }),
            Action::make('voidLatest')
                ->label('Void latest')
                ->icon('heroicon-o-no-symbol')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('This appends a Void event. The issued snapshot remains immutable history and can no longer be represented as the current valid TOR.')
                ->schema([
                    TextInput::make('authority_reference')->required()->maxLength(255),
                    Textarea::make('reason')->required()->maxLength(1000),
                ])
                ->visible(fn (): bool => (auth()->user()?->hasRole(User::StaffRoleRegistrar) ?? false) && $this->latestSnapshot() !== null)
                ->action(function (array $data): void {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User, 403);
                    app(VoidTranscript::class)->execute($this->latestSnapshotOrFail(), $actor, (string) $data['authority_reference'], (string) $data['reason']);
                    Notification::make()->title('TOR void recorded')->success()->send();
                }),
            Action::make('replaceLatest')
                ->label('Replace latest')
                ->icon('heroicon-o-arrow-path')
                ->requiresConfirmation()
                ->modalDescription('This requires a voided predecessor and creates a new immutable snapshot and reference. The predecessor remains attributable history.')
                ->schema([
                    TextInput::make('authority_reference')->required()->maxLength(255),
                    Textarea::make('reason')->required()->maxLength(1000),
                ])
                ->visible(fn (): bool => (auth()->user()?->hasRole(User::StaffRoleRegistrar) ?? false) && $this->latestSnapshot() !== null)
                ->action(function (array $data): void {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User, 403);
                    try {
                        $snapshot = app(ReplaceTranscript::class)->execute($this->latestSnapshotOrFail(), $actor, (string) $data['authority_reference'], (string) $data['reason']);
                        $this->redirect(route('transcript-snapshots.show', $snapshot));
                    } catch (Throwable $exception) {
                        report($exception);
                        Notification::make()->title('TOR was not replaced')->body('Confirm that the predecessor is void and refresh the request before retrying.')->danger()->send();
                    }
                }),
        ];
    }

    private function request(): TranscriptRequest
    {
        $record = $this->getRecord();
        abort_unless($record instanceof TranscriptRequest, 404);

        return $record;
    }

    private function latestSnapshot(): ?TranscriptSnapshot
    {
        $snapshot = $this->request()->snapshots()->latest('version')->first();

        return $snapshot instanceof TranscriptSnapshot ? $snapshot : null;
    }

    private function latestSnapshotOrFail(): TranscriptSnapshot
    {
        $snapshot = $this->request()->snapshots()->latest('version')->firstOrFail();

        return $snapshot;
    }

    private function nextTorReference(): string
    {
        $version = ((int) $this->request()->snapshots()->max('version')) + 1;

        return sprintf('TOR-%s-%06d-V%d', now()->format('Y'), $this->request()->id, $version);
    }
}
