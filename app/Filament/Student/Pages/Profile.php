<?php

namespace App\Filament\Student\Pages;

use App\Actions\StudentHub\StudentStudyContext;
use App\Models\StudentProfile;
use App\Models\User;
use DateTimeInterface;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Pages\PageConfiguration;
use Filament\Panel;
use Filament\Support\Enums\Width;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class Profile extends Page
{
    protected static bool $isDiscovered = false;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user';

    protected static ?string $navigationLabel = 'My profile';

    protected static ?string $title = 'My profile';

    protected string $view = 'filament.student.pages.profile';

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
        return static::$title ?? 'My profile';
    }

    /** @return array<string|int, string> */
    public function getBreadcrumbs(): array
    {
        return [Dashboard::getUrl(panel: 'student') => 'Overview', 'My profile'];
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
            ->with(['program', 'curriculumVersion', 'entryTerm', 'profileEvents'])
            ->active()
            ->where('lifecycle_status', '!=', StudentProfile::LifecycleArchived)
            ->firstOrFail();

        $studyContext = app(StudentStudyContext::class)->forStudent($user);

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
                    : 'Current enrollment details become available after official enrollment.',
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
                    ['label' => 'Curriculum', 'value' => $this->value($profile->curriculumVersion?->name)],
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
    }

    public function changePhotoAction(): Action
    {
        return Action::make('changePhoto')
            ->label('Change photo')
            ->icon('heroicon-o-camera')
            ->color('gray')
            ->modalHeading('Change profile photo')
            ->modalWidth(Width::Large)
            ->modalDescription('Choose a photo for your profile. Your official school record stays the same.')
            ->modalSubmitActionLabel('Save photo')
            ->authorize(fn (): bool => auth()->user() instanceof User
                && auth()->user()->hasRole('student') && auth()->user()->canAuthenticate()
                && auth()->user()->hasAccessibleStudentProfile())
            ->schema([
                FileUpload::make('photo')->label('Profile photo')->image()->storeFiles(false)
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])->maxSize(2048)->required()
                    ->helperText('JPG, PNG or WebP · up to 2 MB. Your photo stays private.'),
            ])
            ->action(function (array $data): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User && $actor->hasRole('student')
                    && $actor->canAuthenticate() && $actor->hasAccessibleStudentProfile(), 403);
                Validator::make($data, ['photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048']])->validate();
                /** @var UploadedFile $photo */
                $photo = $data['photo'];
                $prefix = 'profile-photos/'.$actor->id.'/';
                $path = $photo->store($prefix, ['disk' => 'local', 'visibility' => 'private']);
                if ($path === false || ! Storage::disk('local')->exists($path)) {
                    throw ValidationException::withMessages(['photo' => 'Unable to save your photo. Try again.']);
                }

                try {
                    $oldPath = DB::transaction(function () use ($actor, $path): ?string {
                        $locked = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
                        $previous = $locked->avatar_path;
                        $locked->forceFill(['avatar_path' => $path])->save();

                        return $previous;
                    });
                } catch (\Throwable $exception) {
                    Storage::disk('local')->delete($path);
                    throw $exception;
                }

                if (is_string($oldPath) && str_starts_with($oldPath, $prefix) && ! str_contains($oldPath, '../')) {
                    Storage::disk('local')->delete($oldPath);
                }
                $actor->refresh();
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
