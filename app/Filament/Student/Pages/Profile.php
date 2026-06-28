<?php

namespace App\Filament\Student\Pages;

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

    /**
     * @var array<int, array{heading: string, items: array<int, array{label: string, value: string}>}>
     */
    public array $profileSections = [];

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
        return 'profile';
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

        return $panel->generateRouteName('auth.'.static::getRelativeRouteName($panel));
    }

    public static function getSlug(?Panel $panel = null): string
    {
        return static::$slug ?? 'profile';
    }

    public function mount(): void
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        $studentProfile = $user->studentProfile()->first();
        $applicantIntake = $user->applicantIntake()->first();

        $this->profileSections = [
            [
                'heading' => 'Account',
                'items' => [
                    [
                        'label' => 'Name',
                        'value' => $this->firstFilled(
                            $this->joinedName(
                                $studentProfile?->getAttribute('first_name'),
                                $studentProfile?->getAttribute('middle_name'),
                                $studentProfile?->getAttribute('last_name'),
                            ),
                            $user->hasCanonicalNameParts() ? $user->composedFullName() : null,
                            $user->getAttribute('name'),
                        ),
                    ],
                    [
                        'label' => 'Email',
                        'value' => $this->firstFilled(
                            $studentProfile?->getAttribute('email'),
                            $user->getAttribute('email'),
                            $applicantIntake?->getAttribute('email'),
                        ),
                    ],
                    [
                        'label' => 'Account status',
                        'value' => $this->displayValue($user->getAttribute('status')),
                    ],
                ],
            ],
            [
                'heading' => 'Student Record',
                'items' => [
                    [
                        'label' => 'Student number',
                        'value' => $this->displayValue($studentProfile?->getAttribute('student_number')),
                    ],
                    [
                        'label' => 'Lifecycle status',
                        'value' => $this->displayValue($studentProfile?->getAttribute('lifecycle_status')),
                    ],
                    [
                        'label' => 'Academic standing',
                        'value' => $this->displayValue($studentProfile?->getAttribute('academic_standing')),
                    ],
                ],
            ],
            [
                'heading' => 'Admissions Snapshot',
                'items' => [
                    [
                        'label' => 'Admission category',
                        'value' => $this->displayValue($applicantIntake?->getAttribute('admission_category')),
                    ],
                    [
                        'label' => 'Credential basis',
                        'value' => $this->displayValue($applicantIntake?->getAttribute('credential_basis')),
                    ],
                    [
                        'label' => 'Birth date',
                        'value' => $this->firstFilled(
                            $studentProfile?->getAttribute('birth_date'),
                            $applicantIntake?->getAttribute('birth_date'),
                        ),
                    ],
                ],
            ],
        ];
    }

    private function firstFilled(mixed ...$values): string
    {
        foreach ($values as $value) {
            if (filled($value)) {
                return $this->displayValue($value);
            }
        }

        return 'Not available';
    }

    private function joinedName(mixed ...$parts): ?string
    {
        $name = collect($parts)
            ->filter(fn (mixed $part): bool => filled($part))
            ->map(fn (mixed $part): string => (string) $part)
            ->implode(' ');

        return filled($name) ? $name : null;
    }

    private function displayValue(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('M d, Y');
        }

        if (blank($value)) {
            return 'Not available';
        }

        return (string) $value;
    }
}
