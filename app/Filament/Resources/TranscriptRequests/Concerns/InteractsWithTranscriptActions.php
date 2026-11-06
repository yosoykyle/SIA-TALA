<?php

namespace App\Filament\Resources\TranscriptRequests\Concerns;

use App\Actions\Completion\IssueTranscript;
use App\Actions\Completion\OfficialOutputRestrictionService;
use App\Actions\Completion\RecordTranscriptRequest;
use App\Actions\Completion\ReplaceTranscript;
use App\Actions\Completion\TranscriptLifecycleProjection;
use App\Actions\Completion\TranscriptPreviewConfirmation;
use App\Actions\Completion\VoidTranscript;
use App\Models\OfficialOutputRestriction;
use App\Models\TranscriptSnapshot;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Illuminate\Validation\ValidationException;
use Throwable;

trait InteractsWithTranscriptActions
{
    /** @return list<Component> */
    protected function transcriptInputFields(): array
    {
        return [
            TextInput::make('signatory_name')->label('Authorized signatory')->required()->maxLength(255),
            TextInput::make('signatory_title')->label('Signatory title')->required()->maxLength(255),
            Textarea::make('seal_placement_instruction')->label('Seal placement instruction')->required()->maxLength(1000)->rows(2)->columnSpanFull(),
        ];
    }

    protected function transcriptActions(): array
    {
        return [
            Action::make('viewIssued')
                ->label('Open issued TOR')
                ->icon('heroicon-o-document-text')
                ->url(fn (): ?string => $this->currentSnapshot() ? route('transcript-snapshots.show', $this->currentSnapshot()) : null)
                ->visible(fn (): bool => (auth()->user()?->hasRole(User::StaffRoleRegistrar) ?? false) && $this->currentSnapshot() !== null),
            Action::make('recordRestriction')
                ->label('Record TOR restriction')
                ->modalSubmitActionLabel('Record restriction')
                ->icon('heroicon-o-lock-closed')
                ->visible(fn (): bool => auth()->user()?->hasRole(User::StaffRoleAccounting) ?? false)
                ->modalDescription('Record an actual Accounting restriction for this student’s official TOR. This applies across TOR requests until Accounting resolves it. No restriction is needed when issuance is allowed.')
                ->schema([
                    Textarea::make('reason')->label('Reason issuance is restricted')->required()->maxLength(1000),
                    TextInput::make('source_reference')->label('Source reference (optional)')->maxLength(255),
                ])
                ->action(function (array $data): void {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User, 403);
                    app(OfficialOutputRestrictionService::class)->record($this->request()->studentProfile, $actor, $data['reason'], $data['source_reference'] ?? null);
                    $this->request()->refresh();
                    Notification::make()->title('TOR restriction recorded')->success()->send();
                }),
            Action::make('resolveRestriction')
                ->label('Resolve TOR restriction')
                ->modalSubmitActionLabel('Resolve restriction')
                ->icon('heroicon-o-lock-open')
                ->visible(fn (): bool => (auth()->user()?->hasRole(User::StaffRoleAccounting) ?? false)
                    && app(OfficialOutputRestrictionService::class)->activeFor($this->request()->studentProfile)->isNotEmpty())
                ->schema([
                    Select::make('restriction_id')->label('Restriction')->options(fn (): array => app(OfficialOutputRestrictionService::class)->activeFor($this->request()->studentProfile)->pluck('reason', 'id')->all())->required(),
                    Textarea::make('resolution_reason')->label('Why issuance is now allowed')->required()->maxLength(1000),
                ])
                ->action(function (array $data): void {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User, 403);
                    $restriction = OfficialOutputRestriction::query()->where('student_profile_id', $this->request()->student_profile_id)
                        ->where('output_type', OfficialOutputRestriction::OutputTor)->findOrFail($data['restriction_id']);
                    app(OfficialOutputRestrictionService::class)->resolve($restriction, $actor, $data['resolution_reason']);
                    $this->request()->refresh();
                    Notification::make()->title('TOR restriction resolved')->success()->send();
                }),
            Action::make('prepare')
                ->label('Prepare TOR')
                ->color(fn (): string => filled($this->request()->signatory_name) && filled($this->request()->signatory_title) ? 'gray' : 'primary')
                ->modalSubmitActionLabel('Save document inputs')
                ->icon('heroicon-o-document-text')
                ->visible(fn (): bool => (auth()->user()?->hasRole(User::StaffRoleRegistrar) ?? false) && $this->currentSnapshot() === null)
                ->fillForm(fn (): array => $this->request()->only(['signatory_name', 'signatory_title', 'seal_placement_instruction']))
                ->modalDescription('Use the school’s current authorized signatory and seal placement. Saving these inputs keeps the original request and due dates.')
                ->schema($this->transcriptInputFields())
                ->action(function (array $data): void {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User, 403);
                    app(RecordTranscriptRequest::class)->prepare($this->request(), $actor, $data);
                    $this->request()->refresh();
                    Notification::make()->title('TOR inputs saved')->body('Preview the TOR before issuing it.')->success()->send();
                }),
            Action::make('preview')
                ->label('Preview TOR')
                ->color('gray')
                ->icon('heroicon-o-eye')
                ->url(fn (): string => route('transcripts.preview', $this->request()))
                ->openUrlInNewTab()
                ->visible(fn (): bool => (auth()->user()?->hasRole(User::StaffRoleRegistrar) ?? false)
                    && $this->request()->snapshots()->doesntExist()),
            Action::make('previewReplacement')
                ->label('Preview replacement')
                ->icon('heroicon-o-eye')
                ->url(fn (): string => route('transcripts.preview', [
                    'transcriptRequest' => $this->request(),
                    'operation' => 'replacement',
                    'predecessor' => $this->latestSnapshotOrFail()->id,
                ]))
                ->openUrlInNewTab()
                ->visible(fn (): bool => (auth()->user()?->hasRole(User::StaffRoleRegistrar) ?? false)
                    && $this->latestSnapshotIsVoided()),
            Action::make('issue')
                ->label('Issue TOR')
                ->icon('heroicon-o-document-check')
                ->color('success')
                ->disabled(fn (): bool => ! $this->hasUncompletedTorReview(TranscriptPreviewConfirmation::OperationIssue))
                ->tooltip('Review Preview TOR before confirming issuance.')
                ->requiresConfirmation()
                ->modalDescription(fn (): string => 'Issue '.$this->nextTorReference().' for request '.$this->request()->external_request_reference.' after reviewing its preview. TALA rechecks the academic record, Accounting restrictions and signatory inputs. Physical signing, sealing and delivery remain external.')
                ->visible(fn (): bool => (auth()->user()?->hasRole(User::StaffRoleRegistrar) ?? false)
                    && $this->currentSnapshot() === null && ! $this->latestSnapshotIsVoided())
                ->action(function (): void {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User, 403);
                    try {
                        $snapshot = app(IssueTranscript::class)->execute(
                            $this->request(),
                            $actor,
                        );
                        $this->redirect(route('transcript-snapshots.show', $snapshot));
                    } catch (ValidationException $exception) {
                        Notification::make()->title('TOR was not issued')->body(collect($exception->errors())->flatten()->implode(' '))->danger()->send();
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
                    Textarea::make('reason')->required()->maxLength(1000),
                ])
                ->visible(fn (): bool => (auth()->user()?->hasRole(User::StaffRoleRegistrar) ?? false)
                    && $this->currentSnapshot() !== null)
                ->action(function (array $data): void {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User, 403);
                    app(VoidTranscript::class)->execute($this->latestSnapshotOrFail(), $actor, $data['authority_reference'] ?? null, (string) $data['reason']);
                    Notification::make()->title('TOR void recorded')->success()->send();
                }),
            Action::make('replaceLatest')
                ->label('Replace latest')
                ->disabled(fn (): bool => ! $this->hasUncompletedTorReview(TranscriptPreviewConfirmation::OperationReplacement))
                ->tooltip('Review the replacement document before confirming.')
                ->icon('heroicon-o-arrow-path')
                ->requiresConfirmation()
                ->modalDescription('This requires a voided predecessor and creates a new immutable snapshot and reference. The predecessor remains attributable history.')
                ->schema([
                    Textarea::make('reason')->required()->maxLength(1000),
                ])
                ->visible(fn (): bool => (auth()->user()?->hasRole(User::StaffRoleRegistrar) ?? false)
                    && $this->latestSnapshotIsVoided())
                ->action(function (array $data): void {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User, 403);
                    try {
                        $snapshot = app(ReplaceTranscript::class)->execute($this->latestSnapshotOrFail(), $actor, $data['authority_reference'] ?? null, (string) $data['reason']);
                        $this->redirect(route('transcript-snapshots.show', $snapshot));
                    } catch (Throwable $exception) {
                        report($exception);
                        Notification::make()->title('TOR was not replaced')->body('Confirm that the predecessor is void and refresh the request before retrying.')->danger()->send();
                    }
                }),
        ];
    }

    private function hasUncompletedTorReview(string $operation): bool
    {
        $actor = auth()->user();
        if (! $actor instanceof User || ! $actor->canAuthenticate()) {
            return false;
        }
        $confirmations = app(TranscriptPreviewConfirmation::class);
        $reference = $confirmations->latestFor($actor, $this->request(), $operation);

        return $reference !== null && $confirmations->completedSnapshot($reference, $actor, $this->request(), $operation) === null;
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

    private function currentSnapshot(): ?TranscriptSnapshot
    {
        return app(TranscriptLifecycleProjection::class)->currentSnapshot(
            $this->request()->load(['snapshots.events', 'events']),
        );
    }

    private function latestSnapshotIsVoided(): bool
    {
        $snapshot = $this->latestSnapshot();

        return $snapshot instanceof TranscriptSnapshot
            && app(TranscriptLifecycleProjection::class)->statusForSnapshot($snapshot) === 'Voided';
    }

    private function nextTorReference(): string
    {
        $version = ((int) $this->request()->snapshots()->max('version')) + 1;

        return sprintf('TOR-%s-%06d-V%d', now()->format('Y'), $this->request()->id, $version);
    }
}
