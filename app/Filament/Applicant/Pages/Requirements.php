<?php

namespace App\Filament\Applicant\Pages;

use App\Actions\Applicants\ApplicantEvidenceService;
use App\Actions\Applicants\ApplicantIntakeWorkflowPresenter;
use App\Models\ApplicantIntake;
use App\Models\ChecklistItem;
use App\Models\User;
use BackedEnum;
use Carbon\CarbonInterface;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use LogicException;

class Requirements extends Page
{
    use RestrictsFileUploadsToSchemaComponents;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $navigationLabel = 'Requirements';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.applicant.pages.requirements';

    /** @var array<string, mixed> | null */
    public ?array $data = [];

    public function mount(): void
    {
        $this->replacementForm()->fill();
    }

    public static function canAccess(): bool
    {
        return Auth::user() instanceof User;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Replace Rejected Evidence')
                    ->description('Choose a rejected digital requirement and upload its corrected version. The rejected file remains in the audit history.')
                    ->schema([
                        Select::make('requirement_id')
                            ->label('Requirement to Correct')
                            ->options(fn (): array => $this->rejectedDigitalRequirements())
                            ->required()
                            ->native(false),
                        FileUpload::make('replacement_file')
                            ->label('Corrected File')
                            ->disk('local')
                            ->directory(fn (): string => 'applicant-evidence-replacements/'.Auth::id())
                            ->visibility('private')
                            ->preventFilePathTampering(
                                allowFilePathUsing: fn (string $file): bool => $this->pathIsInside(
                                    $file,
                                    'applicant-evidence-replacements/'.Auth::id(),
                                ),
                            )
                            ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])
                            ->maxFiles(1)
                            ->maxSize(5120)
                            ->helperText('PDF, JPG, or PNG; maximum 5 MB. Upload a corrected file, not the rejected version.')
                            ->required(),
                    ])
                    ->visible(fn (): bool => $this->intake()?->status === ApplicantIntake::StatusActionRequired)
                    ->columnSpanFull(),
            ])
            ->statePath('data');
    }

    private function pathIsInside(string $path, string $directory): bool
    {
        $normalizedPath = str_replace('\\', '/', $path);
        $normalizedDirectory = trim(str_replace('\\', '/', $directory), '/').'/';

        return ! str_contains($normalizedPath, '../')
            && str_starts_with($normalizedPath, $normalizedDirectory)
            && strlen($normalizedPath) > strlen($normalizedDirectory);
    }

    public function replaceEvidence(): void
    {
        $applicant = Auth::user();
        abort_unless($applicant instanceof User, 403);
        $intake = $this->intake();
        abort_unless($intake instanceof ApplicantIntake, 404);
        $state = $this->replacementForm()->getState();
        $item = $intake->checklistItems()->findOrFail((int) $state['requirement_id']);

        try {
            app(ApplicantEvidenceService::class)->replace(
                intake: $intake,
                checklistItem: $item,
                applicant: $applicant,
                path: (string) $state['replacement_file'],
            );
        } catch (ValidationException $exception) {
            $message = $exception->validator->errors()->first();
            $this->addError('data.replacement_file', $message);
            Notification::make()
                ->title('Corrected evidence cannot be submitted')
                ->body($message)
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title('Corrected evidence submitted')
            ->body('The Registrar can now review the new version. The earlier rejected version remains recorded.')
            ->success()
            ->send();

        $this->redirect(Dashboard::getUrl());
    }

    public function intake(): ?ApplicantIntake
    {
        $applicant = Auth::user();

        if (! $applicant instanceof User) {
            return null;
        }

        return ApplicantIntake::query()
            ->with([
                'checklistItems.documentEvidence.reviewer',
                'program',
                'term',
                'withdrawalActivity.causer',
            ])
            ->whereBelongsTo($applicant)
            ->orderByRaw('status != ? desc', [ApplicantIntake::StatusWithdrawn])
            ->latest('id')
            ->first();
    }

    /**
     * @return array{
     *     stage:string,
     *     responsible_party:string,
     *     next_action:string,
     *     handover_blocker_count:int,
     *     requirement_count:int,
     *     resolved_requirement_count:int,
     *     outstanding_requirement_count:int,
     *     requirements_summary:string,
     *     ready_for_handover:bool,
     *     last_activity_at:?CarbonInterface
     * }
     */
    public function workflowSummary(ApplicantIntake $intake): array
    {
        return app(ApplicantIntakeWorkflowPresenter::class)->present($intake);
    }

    /** @return array<int, string> */
    private function rejectedDigitalRequirements(): array
    {
        $intake = $this->intake();

        if (! $intake instanceof ApplicantIntake) {
            return [];
        }

        return $intake->checklistItems
            ->filter(fn (ChecklistItem $item): bool => $item->status === ChecklistItem::StatusRejected
                && $item->evidence_method === ChecklistItem::EvidenceMethodDigitalUpload)
            ->mapWithKeys(fn (ChecklistItem $item): array => [
                $item->id => str($item->requirement_type)->replace('_', ' ')->title()->toString(),
            ])
            ->all();
    }

    private function replacementForm(): Schema
    {
        return $this->getSchema('form') ?? throw new LogicException('Applicant requirements form schema is unavailable.');
    }
}
