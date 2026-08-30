<?php

namespace App\Filament\Student\Pages;

use App\Actions\Academics\AcademicEnrollmentEffect;
use App\Models\StudentProfile;
use App\Models\User;
use DateTimeInterface;
use Filament\Pages\Page;
use Filament\Pages\PageConfiguration;
use Filament\Panel;

class Profile extends Page
{
    protected static bool $isDiscovered = false;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user';

    protected static ?string $navigationLabel = 'My Profile';

    protected static ?string $title = 'My Profile';

    protected string $view = 'filament.student.pages.profile';

    /** @var array<int, array{heading:string,items:array<int, array{label:string,value:string}>}> */
    public array $profileSections = [];

    /** @var list<array{event:string,effective_at:string,reason:string}> */
    public array $correctionHistory = [];

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function getLabel(): string
    {
        return static::$title ?? 'My Profile';
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

        $this->profileSections = [
            [
                'heading' => 'Official Student Record',
                'items' => [
                    ['label' => 'Legal name', 'value' => $this->value(collect([$profile->first_name, $profile->middle_name, $profile->last_name])->filter()->implode(' '))],
                    ['label' => 'Student number', 'value' => $this->value($profile->student_number)],
                    ['label' => 'Program', 'value' => $this->value($profile->program?->name)],
                    ['label' => 'Curriculum', 'value' => $this->value($profile->curriculumVersion?->name)],
                    ['label' => 'Entry Term', 'value' => $this->value($profile->entryTerm?->label)],
                    ['label' => 'Lifecycle status', 'value' => $this->status($profile->lifecycle_status)],
                    ['label' => 'Academic standing', 'value' => $this->status($profile->academic_standing)],
                    ['label' => 'Enrollment guidance', 'value' => $this->status(app(AcademicEnrollmentEffect::class)->forStudent($profile)['effect'])],
                ],
            ],
            [
                'heading' => 'Official Contact Projection',
                'items' => [
                    ['label' => 'Email', 'value' => $this->value($profile->email)],
                    ['label' => 'Phone', 'value' => $this->value($profile->phone)],
                    ['label' => 'Address', 'value' => $this->value($profile->address)],
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

    private function value(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('M d, Y');
        }

        return filled($value) ? (string) $value : 'Not recorded';
    }

    private function status(mixed $value): string
    {
        return filled($value) ? str((string) $value)->replace('_', ' ')->headline()->toString() : 'Not recorded';
    }
}
