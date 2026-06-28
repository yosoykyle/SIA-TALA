<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    public const StatusActive = 'active';

    public const StatusInactive = 'inactive';

    public const StatusArchived = 'archived';

    public const StatusApplicantPending = 'pending';

    public const StatusApplicantActionRequired = 'action_required';

    public const StatusApplicantForEvaluation = 'for_evaluation';

    public const StatusApplicantApproved = 'approved';

    public const StaffRoleRegistrar = 'registrar';

    public const StaffRoleAccounting = 'accounting';

    public const StaffRoleFaculty = 'faculty';

    public const StaffRoleAcademicHead = 'academic-head';

    public const StaffRoleSystemSuperAdmin = 'system-super-admin';

    public function canAccessPanel(Panel $panel): bool
    {
        return match ($panel->getId()) {
            'admin' => $this->hasAnyRole(self::staffRoleNames()) && $this->canAuthenticate(),
            'student' => $this->hasRole('student') && $this->canAuthenticate(),
            'applicant' => $this->hasRole('applicant') && $this->canAuthenticate(),
            default => false,
        };
    }

    public function canAuthenticate(): bool
    {
        if (! $this->hasAnyRole(['applicant', 'student', ...self::staffRoleNames()])) {
            return false;
        }

        if ($this->hasRole('applicant')) {
            return in_array($this->status, self::applicantWorkspaceStatusValues(), true);
        }

        return $this->status === self::StatusActive;
    }

    public function facultySubjectEligibilities(): HasMany
    {
        return $this->hasMany(FacultySubjectEligibility::class, 'faculty_id');
    }

    public function facultyAvailabilitySubmissions(): HasMany
    {
        return $this->hasMany(FacultyAvailabilitySubmission::class, 'faculty_id');
    }

    public function facultyAvailabilityChangeRequests(): HasMany
    {
        return $this->hasMany(FacultyAvailabilityChangeRequest::class, 'faculty_id');
    }

    public function facultyQualifications(): HasMany
    {
        return $this->hasMany(FacultyQualification::class, 'faculty_user_id');
    }

    public function facultyTermLoadOverrides(): HasMany
    {
        return $this->hasMany(FacultyTermLoadOverride::class, 'faculty_user_id');
    }

    /** @return HasOne<ApplicantIntake, $this> */
    public function applicantIntake(): HasOne
    {
        return $this->hasOne(ApplicantIntake::class);
    }

    /** @return HasOne<StudentProfile, $this> */
    public function studentProfile(): HasOne
    {
        return $this->hasOne(StudentProfile::class);
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
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (User $user): void {
            if ($user->hasCanonicalNameParts()) {
                $user->name = $user->composedFullName();
            }
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
            self::StatusApplicantPending => 'Pending',
            self::StatusApplicantActionRequired => 'Action Required',
            self::StatusApplicantForEvaluation => 'For Evaluation',
            self::StatusApplicantApproved => 'Approved',
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
            self::StaffRoleSystemSuperAdmin => 'System Super Admin',
        ];
    }

    /**
     * @return list<string>
     */
    public static function staffRoleNames(): array
    {
        return array_keys(self::staffRoleOptions());
    }
}
