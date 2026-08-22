<?php

namespace App\Filament\Student\Pages;

use App\Actions\Academics\AcademicEnrollmentEffect;
use App\Models\StudentProfile;
use App\Models\User;
use DateTimeInterface;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Pages\PageConfiguration;
use Filament\Panel;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use LogicException;

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

    /** @var array<string, mixed> | null */
    public ?array $data = [];

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

        $studentProfile = $this->studentProfileFor($user);
        $applicantIntake = $user->applicantIntake()->first();

        $this->profileSections = [
            [
                'heading' => 'Official Student Record',
                'items' => [
                    [
                        'label' => 'Name',
                        'value' => $this->firstFilled(
                            $this->joinedName(
                                $studentProfile->getAttribute('first_name'),
                                $studentProfile->getAttribute('middle_name'),
                                $studentProfile->getAttribute('last_name'),
                            ),
                            $user->hasCanonicalNameParts() ? $user->composedFullName() : null,
                            $user->getAttribute('name'),
                        ),
                    ],
                    [
                        'label' => 'Email',
                        'value' => $this->firstFilled(
                            $studentProfile->getAttribute('email'),
                            $user->getAttribute('email'),
                            $applicantIntake?->getAttribute('email'),
                        ),
                    ],
                    [
                        'label' => 'Account status',
                        'value' => $this->displayStatus($user->getAttribute('status')),
                    ],
                    [
                        'label' => 'Student number',
                        'value' => $this->displayValue($studentProfile->getAttribute('student_number')),
                    ],
                    [
                        'label' => 'Program',
                        'value' => $this->displayValue($studentProfile->program?->name),
                    ],
                    [
                        'label' => 'Curriculum',
                        'value' => $this->displayValue($studentProfile->curriculumVersion?->name),
                    ],
                    [
                        'label' => 'Lifecycle status',
                        'value' => $this->displayStatus($studentProfile->getAttribute('lifecycle_status')),
                    ],
                    [
                        'label' => 'Enrollment guidance',
                        'value' => $this->displayStatus(app(AcademicEnrollmentEffect::class)->forStudent($studentProfile)['effect']),
                    ],
                ],
            ],
            [
                'heading' => 'Admissions Snapshot',
                'items' => [
                    [
                        'label' => 'Admission category',
                        'value' => $this->displayStatus($applicantIntake?->getAttribute('admission_category')),
                    ],
                    [
                        'label' => 'Credential basis',
                        'value' => $this->displayStatus($applicantIntake?->getAttribute('credential_basis')),
                    ],
                    [
                        'label' => 'Birth date',
                        'value' => $this->firstFilled(
                            $studentProfile->getAttribute('birth_date'),
                            $applicantIntake?->getAttribute('birth_date'),
                        ),
                    ],
                ],
            ],
        ];

        $this->profileForm()->fill($studentProfile->only($this->editableProfileAttributes()));
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Contact Details You Can Update')
                    ->description('Official identity, student number, program, curriculum, lifecycle, grades, finance, and enrollment records stay locked to staff workflows.')
                    ->schema([
                        TextInput::make('phone')
                            ->label('Phone number')
                            ->tel()
                            ->maxLength(255),
                        TextInput::make('email')
                            ->label('Personal email')
                            ->email()
                            ->maxLength(255),
                        Textarea::make('address')
                            ->label('Current home address')
                            ->maxLength(2000)
                            ->columnSpanFull(),
                        TextInput::make('emergency_contact_name')
                            ->label('Emergency contact name')
                            ->maxLength(255),
                        TextInput::make('emergency_contact_phone')
                            ->label('Emergency contact phone')
                            ->tel()
                            ->maxLength(255),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ])
            ->statePath('data');
    }

    public function saveProfile(): void
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        $studentProfile = $this->studentProfileFor($user);
        $state = $this->profileForm()->getState();

        $studentProfile->fill(collect($state)->only($this->editableProfileAttributes())->all());
        $changedFields = array_keys($studentProfile->getDirty());
        $studentProfile->save();

        if ($changedFields !== []) {
            activity()
                ->performedOn($studentProfile)
                ->causedBy($user)
                ->event('student_profile_self_service_update')
                ->withProperties(['updated_fields' => $changedFields])
                ->log('Student self-service profile update');
        }

        Notification::make()
            ->title('Profile contact details saved')
            ->success()
            ->send();
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

    private function displayStatus(mixed $value): string
    {
        if (blank($value)) {
            return 'Not available';
        }

        return str((string) $value)->replace('_', ' ')->headline()->toString();
    }

    private function studentProfileFor(User $user): StudentProfile
    {
        $studentProfile = $user->studentProfile()
            ->with(['program', 'curriculumVersion'])
            ->active()
            ->where('lifecycle_status', '!=', StudentProfile::LifecycleArchived)
            ->first();

        abort_unless($studentProfile instanceof StudentProfile, 403);

        return $studentProfile;
    }

    /** @return list<string> */
    private function editableProfileAttributes(): array
    {
        return [
            'phone',
            'email',
            'address',
            'emergency_contact_name',
            'emergency_contact_phone',
        ];
    }

    private function profileForm(): Schema
    {
        return $this->getSchema('form') ?? throw new LogicException('Student profile form schema is unavailable.');
    }
}
