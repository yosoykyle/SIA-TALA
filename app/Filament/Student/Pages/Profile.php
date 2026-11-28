<?php

namespace App\Filament\Student\Pages;

use App\Actions\Authentication\ManageUserProfilePhoto;
use App\Actions\StudentHub\StudentStudyContext;
use App\Filament\Resources\StudentProfiles\StudentProfileResource;
use App\Models\AcademicYear;
use App\Models\CurriculumVersion;
use App\Models\StudentLifecycleChange;
use App\Models\StudentProfile;
use App\Models\User;
use DateTimeInterface;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Pages\PageConfiguration;
use Filament\Panel;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

class Profile extends Page
{
    use WithPagination;

    protected static bool $isDiscovered = false;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user';

    protected static ?string $navigationLabel = 'Student details';

    protected static ?string $title = 'Student details';

    protected string $view = 'filament.student.pages.profile';

    #[Url]
    public ?int $change = null;

    /** @var array<int, array{heading:string,secondary?:bool,description?:string,items:array<int, array{label:string,value:string}>}> */
    public array $profileSections = [];

    /** @var list<array{event:string,effective_at:string,reason:string}> */
    public array $correctionHistory = [];

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function getLabel(): string
    {
        return static::$title ?? 'Student details';
    }

    /** @return array<string|int, string> */
    public function getBreadcrumbs(): array
    {
        return [Dashboard::getUrl(panel: 'student') => 'Overview', 'Student details'];
    }

    public static function getRelativeRouteName(Panel $panel): string
    {
        return 'student-profile';
    }

    public static function isTenantSubscriptionRequired(Panel $panel): bool
    {
        return false;
    }

    public static function registerRoutes(Panel $panel, ?PageConfiguration $configuration = null): void
    {
        static::routes($panel, $configuration);
    }

    public static function getRouteName(?Panel $panel = null): string
    {
        $panel ??= filament()->getCurrentOrDefaultPanel();

        return $panel->generateRouteName(static::getRelativeRouteName($panel));
    }

    public static function getSlug(?Panel $panel = null): string
    {
        return static::$slug ?? 'student-profile';
    }

    public function mount(): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        $profile = $user->studentProfile()
            ->with(['program', 'curriculumVersion.academicYears', 'entryAcademicYear', 'entryTerm.academicYear', 'profileEvents'])
            ->active()
            ->where('lifecycle_status', '!=', StudentProfile::LifecycleArchived)
            ->firstOrFail();

        $studyContext = app(StudentStudyContext::class)->forStudent($user);
        $resolvedEntryYear = $profile->resolvedEntryAcademicYear();

        $this->profileSections = [
            [
                'heading' => 'Student details',
                'items' => [
                    ['label' => 'Name', 'value' => $this->value(collect([$profile->first_name, $profile->middle_name, $profile->last_name])->filter()->implode(' '))],
                    ['label' => 'Student number', 'value' => $this->value($profile->student_number)],
                ],
            ],
            [
                'heading' => 'Current studies',
                'description' => $studyContext['is_official']
                    ? 'Year level follows the subjects in your current official enrollment.'
                    : ($studyContext['study_level_label'] !== 'Unavailable'
                        ? 'Provisional year standing based on your recorded prior credits. Official term details appear after enrollment.'
                        : 'Current enrollment details become available after official enrollment.'),
                'items' => [
                    ['label' => 'Program', 'value' => $this->value($studyContext['program_name'])],
                    ['label' => 'Year level', 'value' => $studyContext['study_level_label']],
                    ['label' => 'Term', 'value' => $this->value($studyContext['semester'])],
                    ['label' => 'School year', 'value' => $this->value($studyContext['academic_year'])],
                ],
            ],
            [
                'heading' => 'Contact details',
                'items' => [
                    ['label' => 'Email', 'value' => $this->value($profile->email)],
                    ['label' => 'Phone', 'value' => $this->value($profile->phone)],
                    ['label' => 'Address', 'value' => $this->value($profile->address)],
                ],
            ],
            [
                'heading' => 'School record',
                'secondary' => true,
                'items' => [
                    ['label' => 'Student status', 'value' => $this->status($profile->lifecycle_status)],
                    ['label' => 'Curriculum', 'value' => $this->value(StudentProfileResource::curriculumAndBatchLabel($profile))],
                    ['label' => 'Entry batch', 'value' => $this->value($resolvedEntryYear instanceof AcademicYear ? 'Batch '.CurriculumVersion::formatBatchShort($resolvedEntryYear) : null)],
                    ['label' => 'Entry term', 'value' => $this->value($profile->entryTerm?->label)],
                    ['label' => 'Academic standing', 'value' => $this->status($profile->academic_standing)],
                ],
            ],
        ];

        $this->correctionHistory = $profile->profileEvents
            ->sortByDesc('effective_at')
            ->map(fn ($event): array => [
                'event' => str((string) $event->event_type)->headline()->toString(),
                'effective_at' => $this->value($event->effective_at),
                'reason' => (string) $event->reason,
            ])
            ->values()
            ->all();

        if ($this->change !== null) {
            $change = $this->schoolChangesQuery($profile)->whereKey($this->change)->first();
            if ($change instanceof StudentLifecycleChange) {
                $newer = $this->schoolChangesQuery($profile)->where(function (Builder $query) use ($change): void {
                    $query->where('effective_on', '>', $change->effective_on)
                        ->orWhere(fn (Builder $sameDate): Builder => $sameDate->where('effective_on', $change->effective_on)->where('id', '>', $change->id));
                })->count();
                $this->setPage(intdiv($newer, 10) + 1, pageName: 'changesPage');
            }
        }
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $student = auth()->user()?->studentProfile;

        if (! $student instanceof StudentProfile) {
            return [
                'lifecycleChanges' => collect(),
                'changeUnavailable' => false,
            ];
        }

        return [
            'lifecycleChanges' => $this->schoolChanges($student),
            'changeUnavailable' => $this->change !== null && ! $this->schoolChangesQuery($student)->whereKey($this->change)->exists(),
        ];
    }

    /** @return Builder<StudentLifecycleChange> */
    private function schoolChangesQuery(StudentProfile $student): Builder
    {
        return StudentLifecycleChange::query()->appliedForStudent($student);
    }

    private function schoolChanges(StudentProfile $student): LengthAwarePaginator
    {
        $query = $this->schoolChangesQuery($student)->with('term.academicYear')->latest('effective_on')->latest('id');
        $changes = $query->paginate(10, pageName: 'changesPage');
        if ($changes->currentPage() > $changes->lastPage()) {
            $this->setPage($changes->lastPage(), pageName: 'changesPage');
            $changes = $query->paginate(10, pageName: 'changesPage');
        }

        return $changes;
    }

    public function changePhotoAction(): Action
    {
        return Action::make('changePhoto')
            ->label('Change photo')
            ->icon('heroicon-o-camera')
            ->color('gray')
            ->modalHeading('Change profile photo')
            ->modalWidth(Width::Large)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalDescription('Choose a photo for your profile. Your official school record stays the same.')
            ->modalSubmitActionLabel('Save photo')
            ->authorize(fn (): bool => auth()->user() instanceof User
                && auth()->user()->hasRole('student') && auth()->user()->canAuthenticate()
                && auth()->user()->hasAccessibleStudentProfile())
            ->schema([
                ManageUserProfilePhoto::fileUploadField(),
            ])
            ->action(function (array $data): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User && $actor->hasRole('student')
                    && $actor->canAuthenticate() && $actor->hasAccessibleStudentProfile(), 403);
                app(ManageUserProfilePhoto::class)->upload($actor, $data['photo'] ?? null);
                Notification::make()->title('Profile photo updated')->success()->send();
            });
    }

    private function value(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('M d, Y');
        }

        return filled($value) ? (string) $value : 'Not recorded';
    }

    private function status(mixed $value): string
    {
        return filled($value) ? str((string) $value)->lower()->replace('_', ' ')->ucfirst()->toString() : 'Not recorded';
    }
}
