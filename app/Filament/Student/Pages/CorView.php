<?php

namespace App\Filament\Student\Pages;

use App\Actions\Cor\BuildCorOutput;
use App\Models\CorVersion;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

class CorView extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-check';

    protected static ?string $navigationLabel = 'COR';

    protected static ?string $title = 'Certificate of Registration';

    /** @return array<string|int, string> */
    public function getBreadcrumbs(): array
    {
        return [Dashboard::getUrl(panel: 'student') => 'Overview', Enrollment::getUrl(panel: 'student') => 'Enrollment', 'Certificate of registration'];
    }

    protected string $view = 'filament.student.pages.cor-view';

    #[Url(as: 'version')]
    public ?int $version = null;

    /**
     * @var array<string, mixed>
     */
    #[Locked]
    public array $cor = [];

    public function mount(Request $request): void
    {
        $actor = auth()->user();

        abort_unless($actor instanceof User, 403);

        $builder = app(BuildCorOutput::class);
        $selected = $this->version !== null ? $this->ownedVersions($actor)->firstWhere('id', $this->version) : null;
        if ($selected instanceof CorVersion) {
            $this->cor = $builder->forEnrollment($selected->enrollment, $actor, BuildCorOutput::CopyStudent, false, $selected);
        } else {
            $currentCor = $builder->forStudent($actor);
            if (($currentCor['available'] ?? false) === true) {
                $this->cor = $currentCor;
            } else {
                $latestOwned = $this->ownedVersions($actor)->first();
                $this->cor = $latestOwned instanceof CorVersion
                    ? $builder->forEnrollment($latestOwned->enrollment, $actor, BuildCorOutput::CopyStudent, false, $latestOwned)
                    : $currentCor;
            }
        }

        if (($this->cor['available'] ?? false) === true) {
            $builder->recordAccess($this->cor, $actor, BuildCorOutput::ActionView, $request);
        }
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return [
            'output' => $this->cor,
            'versions' => $this->versionChoices(),
            'selectedVersionId' => $this->selectedVersionId(),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('print')
                ->label('Print or save PDF')
                ->icon('heroicon-o-printer')
                ->tooltip('Print or save the current COR as PDF')
                ->url(fn (): string => route('cor.print', array_filter([
                    'enrollment' => $this->cor['summary']['enrollment_id'] ?? 0,
                    'version' => $this->cor['summary']['cor_version'] ?? null,
                ])))
                ->openUrlInNewTab()
                ->visible(fn (): bool => ($this->cor['available'] ?? false) === true),
        ];
    }

    /** @return Collection<int, CorVersion> */
    private function ownedVersions(User $actor)
    {
        return CorVersion::query()
            ->whereHas('enrollment', fn ($query) => $query->where('credential_user_id', $actor->id))
            ->with('enrollment.term')
            ->orderByDesc('issued_at')->orderByDesc('id')
            ->get();
    }

    private function selectedVersionId(): ?int
    {
        $enrollmentId = $this->cor['summary']['enrollment_id'] ?? null;
        $number = $this->cor['summary']['cor_version'] ?? null;

        return $enrollmentId === null ? null : CorVersion::query()->where('enrollment_id', $enrollmentId)->where('version', $number)->value('id');
    }

    /** @return list<array{id:int,label:string,current:bool,url:string}> */
    private function versionChoices(): array
    {
        $actor = auth()->user();
        if (! $actor instanceof User) {
            return [];
        }

        $currentEnrollment = app(BuildCorOutput::class)->forStudent($actor)['enrollment'] ?? null;

        return $this->ownedVersions($actor)->map(function (CorVersion $version) use ($currentEnrollment): array {
            $isLatestForCase = (int) $version->enrollment->current_cor_version_id === (int) $version->id;
            $isCurrentTerm = $currentEnrollment instanceof \App\Models\Enrollment && $currentEnrollment->is($version->enrollment);
            $statusLabel = match (true) {
                $isCurrentTerm && $isLatestForCase => 'Current',
                $isLatestForCase => 'Latest for this Term',
                default => 'Earlier copy',
            };

            return [
                'id' => $version->id,
                'current' => $isCurrentTerm && $isLatestForCase,
                'label' => ($version->enrollment->term?->label ?? 'Term unavailable')
                    .' · '.$statusLabel
                    .' · issued '.$version->issued_at?->timezone(config('app.display_timezone'))->format('M j, Y'),
                'url' => static::getUrl(['version' => $version->id], panel: 'student'),
            ];
        })->all();
    }
}
