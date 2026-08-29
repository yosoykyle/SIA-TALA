<?php

namespace App\Models;

use App\Actions\Authentication\WorkspaceContextResolver;
use App\Filament\Pages\Auth\ContextualLogin;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, HasName, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, TwoFactorAuthenticatable {
        HasRoles::hasRole as protected hasAssignedRoleFromTrait;
    }

    public const StatusActive = 'active';

    public const StatusInactive = 'inactive';

    public const StatusArchived = 'archived';

    public const StatusApplicantPending = 'pending';

    public const StatusApplicantActionRequired = 'action_required';

    public const StatusApplicantForEvaluation = 'for_evaluation';

    public const StatusApplicantApproved = 'approved';

    public const StatusApplicantWithdrawn = 'withdrawn';

    public const StatusInvitationPending = 'invitation_pending';

    public const StatusVerificationRequired = 'verification_required';

    public const StatusDisabled = 'disabled';

    public const StaffRoleRegistrar = 'registrar';

    public const StaffRoleAccounting = 'accounting';

    public const StaffRoleFaculty = 'faculty';

    public const StaffRoleAcademicHead = 'academic-head';

    public const StaffRoleSystemSuperAdmin = 'system-super-admin';

    public function canAccessPanel(Panel $panel): bool
    {
        if (session(ContextualLogin::AuthenticatingSessionKey) === true) {
            return $this->canAuthenticate();
        }

        $resolver = app(WorkspaceContextResolver::class);
        $selected = $resolver->selected($this);
        $available = $resolver->availableContexts($this);

        if ($selected === null && count($available) === 1) {
            $selected = array_key_first($available);
        }

        return match ($panel->getId()) {
            'admin' => in_array($selected, self::staffRoleNames(), true),
            'student' => $selected === 'student',
            'applicant' => $selected === 'applicant',
            default => false,
        };
    }

    public function hasAccessibleStudentProfile(): bool
    {
        return $this->studentProfile()
            ->active()
            ->where('lifecycle_status', '!=', StudentProfile::LifecycleArchived)
            ->exists();
    }

    public function canAuthenticate(): bool
    {
        if (! $this->hasAnyAssignedRole(['applicant', 'student', ...self::staffRoleNames()])) {
            return false;
        }

        return in_array($this->status, [self::StatusActive, self::StatusVerificationRequired], true);
    }

    public function authorizedWorkspacePath(): ?string
    {
        $resolver = app(WorkspaceContextResolver::class);
        $contexts = $resolver->availableContexts($this);

        if (count($contexts) > 1 && $resolver->selected($this) === null) {
            return '/workspace-chooser';
        }

        return $resolver->destinationFor($this, $resolver->selected($this) ?? array_key_first($contexts));
    }

    public function authorizedWorkspaceName(): ?string
    {
        return match ($this->authorizedWorkspacePath()) {
            '/admin' => 'Staff Workspace',
            '/student' => 'Student Hub',
            '/applicant' => 'Applicant Workspace',
            default => null,
        };
    }

    public function facultyQualifications(): HasMany
    {
        return $this->hasMany(FacultyQualification::class, 'faculty_user_id');
    }

    public function facultyTermLoadOverrides(): HasMany
    {
        return $this->hasMany(FacultyTermLoadOverride::class, 'faculty_user_id');
    }

    /**
     * Backward-compatible reference to the latest application record.
     *
     * @return HasOne<ApplicantIntake, $this>
     */
    public function applicantIntake(): HasOne
    {
        return $this->hasOne(ApplicantIntake::class)->latestOfMany();
    }

    /** @return HasMany<ApplicantIntake, $this> */
    public function applicantIntakes(): HasMany
    {
        return $this->hasMany(ApplicantIntake::class);
    }

    /** @return HasMany<AdmissionApplication, $this> */
    public function admissionApplications(): HasMany
    {
        return $this->hasMany(AdmissionApplication::class, 'user_id');
    }

    /** @return HasOne<AdmissionApplication, $this> */
    public function currentAdmissionApplication(): HasOne
    {
        return $this->hasOne(AdmissionApplication::class, 'user_id')
            ->canonical()
            ->where('application_state', '!=', AdmissionApplication::StateWithdrawn)
            ->latestOfMany();
    }

    /** @return HasOne<ApplicantIntake, $this> */
    public function currentApplicantIntake(): HasOne
    {
        return $this->hasOne(ApplicantIntake::class)
            ->where('status', '!=', ApplicantIntake::StatusWithdrawn)
            ->latestOfMany();
    }

    /** @return HasOne<StudentProfile, $this> */
    public function studentProfile(): HasOne
    {
        return $this->hasOne(StudentProfile::class);
    }

    /** @return HasOne<StaffAccessProfile, $this> */
    public function staffAccessProfile(): HasOne
    {
        return $this->hasOne(StaffAccessProfile::class);
    }

    /** @return HasMany<StaffInvitation, $this> */
    public function staffInvitations(): HasMany
    {
        return $this->hasMany(StaffInvitation::class);
    }

    /** @return HasMany<PendingEmailChange, $this> */
    public function pendingEmailChanges(): HasMany
    {
        return $this->hasMany(PendingEmailChange::class);
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'first_name',
        'middle_name',
        'last_name',
        'username',
        'email',
        'password',
        'status',
        'privacy_notice_reference',
        'privacy_acknowledged_at',
        'email_verification_nonce',
        'disabled_at',
        'disabled_by',
        'disabled_reason',
        'disabled_authority',
        'disabled_evidence_reference',
        'last_successful_sign_in_at',
        'two_factor_recovery_codes_acknowledged_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'disabled_at' => 'datetime',
            'last_successful_sign_in_at' => 'datetime',
            'privacy_acknowledged_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_recovery_codes_acknowledged_at' => 'datetime',
        ];
    }

    public function isStaffCapable(): bool
    {
        return $this->hasAnyAssignedRole(self::staffRoleNames());
    }

    public function hasAssignedRole(mixed $roles, ?string $guard = null): bool
    {
        if (is_array($roles) || $roles instanceof Collection) {
            return collect($roles)->contains(fn (mixed $role): bool => $this->hasAssignedRoleFromTrait($role, $guard));
        }

        return $this->hasAssignedRoleFromTrait($roles, $guard);
    }

    /** @param list<mixed> $roles */
    public function hasAnyAssignedRole(array $roles): bool
    {
        return $this->hasAssignedRole($roles);
    }

    public function hasRole($roles, ?string $guard = null): bool
    {
        if (! $this->hasAssignedRole($roles, $guard)) {
            return false;
        }

        if (auth()->id() !== $this->getKey()) {
            return true;
        }

        $selected = session(WorkspaceContextResolver::SessionKey);

        if (! is_string($selected)) {
            return true;
        }

        return $this->roleInputContains($roles, $selected);
    }

    private function roleInputContains(mixed $roles, string $selected): bool
    {
        if ($roles instanceof \BackedEnum) {
            return $roles->value === $selected;
        }

        if ($roles instanceof Role) {
            return $roles->name === $selected;
        }

        if ($roles instanceof Collection || is_array($roles)) {
            return collect($roles)->contains(fn (mixed $role): bool => $this->roleInputContains($role, $selected));
        }

        if (is_string($roles)) {
            return in_array($selected, explode('|', $roles), true);
        }

        if (is_int($roles)) {
            return $this->roles->firstWhere('name', $selected)?->getKey() === $roles;
        }

        return false;
    }

    public function getAppAuthenticationSecret(): ?string
    {
        if (blank($this->two_factor_secret)) {
            return null;
        }

        return Fortify::currentEncrypter()->decrypt($this->two_factor_secret);
    }

    public function saveAppAuthenticationSecret(#[\SensitiveParameter] ?string $secret): void
    {
        $this->forceFill([
            'two_factor_secret' => $secret === null ? null : Fortify::currentEncrypter()->encrypt($secret),
            'two_factor_confirmed_at' => $secret === null ? null : now(),
        ])->save();
    }

    public function getAppAuthenticationHolderName(): string
    {
        return $this->email;
    }

    /** @return ?array<string> */
    public function getAppAuthenticationRecoveryCodes(): ?array
    {
        if (blank($this->two_factor_recovery_codes)) {
            return null;
        }

        return json_decode(Fortify::currentEncrypter()->decrypt($this->two_factor_recovery_codes), true);
    }

    /** @param ?array<string> $codes */
    public function saveAppAuthenticationRecoveryCodes(#[\SensitiveParameter] ?array $codes): void
    {
        $attributes = [
            'two_factor_recovery_codes' => $codes === null
                ? null
                : Fortify::currentEncrypter()->encrypt(json_encode($codes, JSON_THROW_ON_ERROR)),
            'two_factor_recovery_codes_acknowledged_at' => $codes === null ? null : now(),
        ];

        if ($codes !== null && $this->isStaffCapable() && filled($this->two_factor_secret)) {
            $attributes['status'] = self::StatusActive;
        }

        $this->forceFill($attributes)->save();
    }

    public function acknowledgeRecoveryCodeStorage(): void
    {
        $attributes = ['two_factor_recovery_codes_acknowledged_at' => now()];

        if ($this->isStaffCapable() && filled($this->two_factor_secret) && filled($this->two_factor_recovery_codes)) {
            $attributes['status'] = self::StatusActive;
        }

        $this->forceFill($attributes)->save();
    }

    public function getFilamentName(): string
    {
        return filled($this->name) ? (string) $this->name : 'Applicant account';
    }

    public function getEmailForVerification(): string
    {
        if (blank($this->email_verification_nonce)) {
            return $this->email;
        }

        return "{$this->email}|{$this->email_verification_nonce}";
    }

    public function markEmailAsVerified(): bool
    {
        return $this->forceFill([
            'email_verified_at' => $this->freshTimestamp(),
            'email_verification_nonce' => null,
        ])->save();
    }

    protected static function booted(): void
    {
        static::saving(function (User $user): void {
            if ($user->hasCanonicalNameParts()) {
                $user->name = $user->composedFullName();
            }
        });

        static::updated(function (User $user): void {
            if (! $user->wasChanged('email')) {
                return;
            }

            $activity = activity()
                ->performedOn($user)
                ->event('sign_in_email_changed')
                ->withProperties([
                    'previous_domain' => str((string) $user->getOriginal('email'))->after('@')->toString(),
                    'current_domain' => str($user->email)->after('@')->toString(),
                ]);

            if (auth()->user() instanceof User) {
                $activity->causedBy(auth()->user());
            }

            $activity->log('Sign-in email changed');
        });
    }

    public function hasCanonicalNameParts(): bool
    {
        return filled($this->first_name) && filled($this->last_name);
    }

    public function composedFullName(): string
    {
        $parts = [
            $this->first_name,
            $this->middle_name,
            $this->last_name,
        ];

        return collect($parts)
            ->filter(fn (?string $part): bool => filled($part))
            ->map(fn (string $part): string => Str::squish($part))
            ->implode(' ');
    }

    /**
     * @return array{first_name: string, middle_name: ?string, last_name: string, name: string}
     */
    public static function staffNamePayload(string $firstName, ?string $middleName, string $lastName, ?string $suffix = null): array
    {
        $nameParts = [
            'first_name' => Str::squish($firstName),
            'middle_name' => filled($middleName) ? Str::squish((string) $middleName) : null,
            'last_name' => Str::squish($lastName),
        ];

        return [
            ...$nameParts,
            'name' => collect([...array_values($nameParts), $suffix])
                ->filter(fn (?string $part): bool => filled($part))
                ->map(fn (string $part): string => Str::squish($part))
                ->implode(' '),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function staffEditableStatusOptions(): array
    {
        return [
            self::StatusActive => 'Active',
            self::StatusInactive => 'Inactive',
        ];
    }

    /**
     * @return list<string>
     */
    public static function staffEditableStatusValues(): array
    {
        return array_keys(self::staffEditableStatusOptions());
    }

    /**
     * @return array<string, string>
     */
    public static function applicantWorkspaceStatusOptions(): array
    {
        return [
            self::StatusActive => 'Active',
        ];
    }

    /**
     * @return list<string>
     */
    public static function applicantWorkspaceStatusValues(): array
    {
        return array_keys(self::applicantWorkspaceStatusOptions());
    }

    /**
     * @return array<string, string>
     */
    public static function staffRoleOptions(): array
    {
        return [
            self::StaffRoleRegistrar => 'Registrar',
            self::StaffRoleAccounting => 'Accounting',
            self::StaffRoleFaculty => 'Faculty',
            self::StaffRoleAcademicHead => 'Academic Head',
            self::StaffRoleSystemSuperAdmin => 'System Administrator',
        ];
    }

    /**
     * @return list<string>
     */
    public static function staffRoleNames(): array
    {
        return array_keys(self::staffRoleOptions());
    }

    public function canProcessPayments(): bool
    {
        if ($this->hasRole(self::StaffRoleAccounting)) {
            return true;
        }

        try {
            return $this->can('process-payments');
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }
}
