<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->status === 'active'
            && $this->hasAnyRole([
                'registrar',
                'accounting',
                'faculty',
                'academic-head',
                'system-super-admin',
            ]);
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
        'suffix',
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
            $this->suffix,
        ];

        return collect($parts)
            ->filter(fn (?string $part): bool => filled($part))
            ->map(fn (string $part): string => Str::squish($part))
            ->implode(' ');
    }

    /**
     * @return array{first_name: string, middle_name: ?string, last_name: string, suffix: ?string, name: string}
     */
    public static function staffNamePayload(string $firstName, ?string $middleName, string $lastName, ?string $suffix = null): array
    {
        $nameParts = [
            'first_name' => Str::squish($firstName),
            'middle_name' => filled($middleName) ? Str::squish((string) $middleName) : null,
            'last_name' => Str::squish($lastName),
            'suffix' => filled($suffix) ? Str::squish((string) $suffix) : null,
        ];

        return [
            ...$nameParts,
            'name' => collect(Arr::only($nameParts, ['first_name', 'middle_name', 'last_name', 'suffix']))
                ->filter(fn (?string $part): bool => filled($part))
                ->implode(' '),
        ];
    }
}
