<?php

namespace App\Filament\Applicant\Pages;

use App\Models\AdmissionApplication;
use App\Models\AdmissionRequirement;
use App\Models\ApplicationCorrectionItem;
use App\Models\ApplicationCorrectionRequest;
use App\Models\DocumentEvidence;
use App\Models\OfficialCredentialResult;
use App\Models\PreliminaryEvidenceReview;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;

class Requirements extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $navigationLabel = 'Requirements';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.applicant.pages.requirements';

    protected Width|string|null $maxContentWidth = Width::SevenExtraLarge;

    #[Url(as: 'application')]
    public ?int $sourceApplicationId = null;

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public function getBreadcrumbs(): array
    {
        return [Dashboard::getUrl(['application' => $this->sourceApplicationId ?? $this->application()?->id]) => 'Home', 'Application status'];
    }

    public function getTitle(): string
    {
        return 'Application status';
    }

    public function getSubheading(): ?string
    {
        return match ($this->application()?->application_state) {
            AdmissionApplication::StateDraft => 'Draft · Your application has not been submitted.',
            AdmissionApplication::StateSubmitted => 'Submitted · Your application is with the Registrar.',
            AdmissionApplication::StateAdmitted => 'Admitted · Check your enrollment clearance below.',
            AdmissionApplication::StateNotAdmitted => 'Not admitted · The Registrar’s decision remains available on Home.',
            AdmissionApplication::StateWithdrawn => 'Withdrawn · This application remains in your records.',
            default => null,
        };
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('receipt')->label('View receipt')->icon('heroicon-o-document-text')->color('gray')->outlined()
            ->visible(fn (): bool => $this->application()?->current_submission_version_id !== null)
            ->url(fn (): string => SubmissionReceipt::getUrl(['application' => $this->application()->id, 'version' => $this->application()->current_submission_version_id]))];
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->hasRole('applicant');
    }

    public function application(): ?AdmissionApplication
    {
        $query = AdmissionApplication::query()
            ->canonical()
            ->with([
                'admissionCycle',
                'currentSubmissionVersion.requirementSet.requirements',
                'evidenceVersions.preliminaryReviews',
                'credentialResults.requirement',
                'correctionRequests.items.admissionRequirement',
            ])
            ->where('user_id', Auth::id());

        return $this->sourceApplicationId !== null
            ? $query->findOrFail($this->sourceApplicationId)
            : $query->latest('updated_at')->first();
    }

    public function table(Table $table): Table
    {
        $application = $this->application();
        $rows = collect($application ? $this->preliminaryRows($application) : [])
            ->keyBy(fn (array $row): int => $row['requirement']->id);
        $row = fn (AdmissionRequirement $record): array => $rows->get($record->id, []);

        return $table
            ->query(AdmissionRequirement::query()->whereIn('id', $rows->keys()))
            ->defaultSort('display_order')
            ->paginated(false)
            ->heading('Review copies')
            ->description($application !== null && in_array($application->application_state, [AdmissionApplication::StateNotAdmitted, AdmissionApplication::StateWithdrawn], true)
                ? 'These copies remain with your submitted application history.'
                : 'The Registrar reviews the copies received with your application. Requested corrections appear here.')
            ->columns([
                Split::make([
                    Stack::make([
                        TextColumn::make('label')->label('Review copy')->weight('semibold')->wrap(),
                        TextColumn::make('review_result')
                            ->label('Result')
                            ->state(fn (AdmissionRequirement $record): string => $this->resultLabel($row($record)['result'] ?? 'NotSubmitted'))
                            ->badge()
                            ->color(fn (AdmissionRequirement $record): string => $this->resultColor($row($record)['result'] ?? 'NotSubmitted')),
                        TextColumn::make('last_review_update')
                            ->label('Last update (Asia/Manila)')
                            ->state(fn (AdmissionRequirement $record) => $row($record)['updated_at'] ?? null)
                            ->dateTime('M j, Y · g:i A')->timezone('Asia/Manila')
                            ->placeholder('No copy submitted'),
                    ])->space(1),
                    TextColumn::make('instruction')
                        ->label('Instruction')
                        ->state(fn (AdmissionRequirement $record): string => ($row($record)['instruction'] ?? '') ?: ($row($record)['action'] ?? ''))
                        ->description(fn (AdmissionRequirement $record): string => filled($row($record)['instruction'] ?? null) ? $row($record)['action'] : '')
                        ->wrap()
                        ->visible($rows->contains(fn (array $item): bool => filled($item['instruction']) || filled($item['action']))),
                ])->from('md'),
            ])
            ->recordActions([
                Action::make('viewReviewCopy')
                    ->label('View copy')
                    ->color('gray')->outlined()->button()
                    ->icon('heroicon-o-eye')
                    ->extraAttributes(fn (AdmissionRequirement $record): array => ['aria-label' => e('View '.$record->label.' private copy')])
                    ->visible(fn (AdmissionRequirement $record): bool => ($row($record)['evidence'] ?? null) instanceof DocumentEvidence
                        && in_array($row($record)['evidence']->mime_type, ['application/pdf', 'image/jpeg', 'image/png'], true))
                    ->url(fn (AdmissionRequirement $record): ?string => ($row($record)['evidence'] ?? null) instanceof DocumentEvidence
                        ? route('admissions.evidence.view', ['evidence' => $row($record)['evidence']]) : null)
                    ->openUrlInNewTab(),
                Action::make('downloadReviewCopy')
                    ->label('Download private copy')
                    ->color('gray')->outlined()->button()
                    ->icon('heroicon-o-arrow-down-tray')
                    ->extraAttributes(fn (AdmissionRequirement $record): array => ['aria-label' => e('Download '.$record->label.' private copy')])
                    ->visible(fn (AdmissionRequirement $record): bool => ($row($record)['evidence'] ?? null) instanceof DocumentEvidence)
                    ->url(fn (AdmissionRequirement $record): ?string => ($row($record)['evidence'] ?? null) instanceof DocumentEvidence
                        ? route('admissions.evidence.download', ['evidence' => $row($record)['evidence']])
                        : null),
            ]);
    }

    /** @return array<int, array{requirement: AdmissionRequirement, evidence: ?DocumentEvidence, result: string, instruction: string, updated_at: mixed, action: string}> */
    public function preliminaryRows(AdmissionApplication $application): array
    {
        $requirements = $application->currentSubmissionVersion?->requirementSet?->requirements
            ?->where('requires_preliminary_evidence', true)
            ->sortBy('display_order') ?? collect();

        return $requirements->map(function (AdmissionRequirement $requirement) use ($application): array {
            $evidence = $application->evidenceVersions
                ->where('admission_requirement_id', $requirement->id)
                ->sortByDesc('id')
                ->first();
            $review = $evidence instanceof DocumentEvidence ? $application->currentPreliminaryReview($evidence) : null;
            $result = $review instanceof PreliminaryEvidenceReview
                ? $review->result
                : ($evidence instanceof DocumentEvidence
                    ? ($evidence->reviewed_by !== null && $evidence->reviewed_at !== null ? 'LegacyReviewRecorded' : 'AwaitingReview')
                    : 'NotSubmitted');

            return [
                'requirement' => $requirement,
                'evidence' => $evidence,
                'result' => $result,
                'instruction' => $review instanceof PreliminaryEvidenceReview && filled($review->reason)
                    ? $review->reason
                    : ($evidence === null ? $requirement->applicant_instructions : ''),
                'updated_at' => $review instanceof PreliminaryEvidenceReview
                    ? $review->reviewed_at
                    : ($evidence instanceof DocumentEvidence ? $evidence->uploaded_at : null),
                'action' => $application->application_state === AdmissionApplication::StateActionNeeded
                    && $application->correctionRequests->where('state', ApplicationCorrectionRequest::StateActive)
                        ->contains(fn (ApplicationCorrectionRequest $request): bool => $request->items
                            ->where('scope_type', ApplicationCorrectionItem::ScopeEvidence)
                            ->contains('admission_requirement_id', $requirement->id))
                    ? 'Open Application to replace only this evidence item.'
                    : '',
            ];
        })->values()->all();
    }

    public function resultLabel(string $result): string
    {
        return match ($result) {
            'AwaitingReview' => 'Awaiting Registrar review',
            'NotSubmitted' => 'No copy received',
            'LegacyReviewRecorded' => 'Earlier copy review recorded',
            PreliminaryEvidenceReview::ResultAccepted => 'Review copy accepted',
            default => ucfirst(str($result)->headline()->lower()->toString()),
        };
    }

    public function resultColor(string $result): string
    {
        return match ($result) {
            PreliminaryEvidenceReview::ResultAccepted,
            OfficialCredentialResult::ResultVerified,
            OfficialCredentialResult::ResultAuthorizedException => 'success',
            PreliminaryEvidenceReview::ResultActionNeeded,
            OfficialCredentialResult::ResultActionNeeded => 'warning',
            OfficialCredentialResult::ResultNotYetDue => 'gray',
            default => 'gray',
        };
    }
}
