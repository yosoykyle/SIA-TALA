<?php

namespace App\Filament\Pages\Auth;

use App\Actions\Applicants\ApplicantEntryReadinessService;
use App\Models\User;
use Caresome\FilamentAuthDesigner\Pages\Auth\Login;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TextInput;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

class ContextualLogin extends Login
{
    protected function getEmailFormComponent(): TextInput
    {
        $component = parent::getEmailFormComponent();
        if (! $component instanceof TextInput) {
            throw new LogicException('The Filament login email component must be a text input.');
        }

        return $component->autocomplete('username');
    }

    protected function getPasswordFormComponent(): TextInput
    {
        $component = parent::getPasswordFormComponent();
        if (! $component instanceof TextInput) {
            throw new LogicException('The Filament login password component must be a text input.');
        }

        return $component
            ->hint(Filament::hasPasswordReset() ? view('filament.components.password-recovery-link') : null);
    }

    protected function getRememberFormComponent(): Checkbox
    {
        $component = parent::getRememberFormComponent();
        if (! $component instanceof Checkbox) {
            throw new LogicException('The Filament remember-device component must be a checkbox.');
        }

        return $component->label('Remember device')->default(false)
            ->visible(fn (): bool => Filament::getCurrentOrDefaultPanel()->getId() !== 'admin');
    }

    public function getSubheading(): string|Htmlable|null
    {
        if (! filled($this->userUndertakingMultiFactorAuthentication) && Filament::getCurrentOrDefaultPanel()->getId() === 'applicant') {
            try {
                $registrationAvailable = app(ApplicantEntryReadinessService::class)->registrationIsAvailable();
            } catch (QueryException $exception) {
                report($exception);
                $registrationAvailable = false;
            }
            if (! $registrationAvailable) {
                return new HtmlString(view('filament.components.admission-availability-link')->render());
            }
        }

        return parent::getSubheading();
    }

    public const AuthenticatingSessionKey = 'tala.contextual_login_in_progress';

    public ?string $requestedContext = null;

    public function mount(): void
    {
        $queryContext = request()->query('context');
        $panelContext = match (Filament::getCurrentOrDefaultPanel()->getId()) {
            'applicant' => 'applicant',
            'student' => 'student',
            default => null,
        };
        $this->requestedContext = is_string($queryContext) ? $queryContext : $panelContext;

        if ($this->requestedContext !== null) {
            session()->put('tala.requested_context', $this->requestedContext);
        } else {
            session()->forget('tala.requested_context');
        }

        parent::mount();
    }

    public function authenticate(): ?LoginResponse
    {
        $email = Str::lower(trim((string) ($this->data['email'] ?? '')));
        $user = User::query()->where('email', $email)->first();
        $rateLimitingKey = 'tala-login:'.$email.'|'.request()->ip();
        $wasMultiFactorChallenge = filled($this->userUndertakingMultiFactorAuthentication);

        if (RateLimiter::tooManyAttempts($rateLimitingKey, maxAttempts: 5)) {
            $this->getRateLimitedNotification(new TooManyRequestsException(
                static::class,
                'authenticate',
                request()->ip(),
                RateLimiter::availableIn($rateLimitingKey),
            ))?->send();

            return null;
        }

        if ($user?->isStaffCapable()) {
            $this->data['remember'] = false;
        }

        session()->put(self::AuthenticatingSessionKey, true);
        $this->clearRateLimiter(method: 'authenticate');

        try {
            $response = parent::authenticate();
        } catch (ValidationException $exception) {
            RateLimiter::hit($rateLimitingKey, 60);

            throw $exception;
        } finally {
            session()->forget(self::AuthenticatingSessionKey);
        }

        if ($response instanceof LoginResponse) {
            RateLimiter::clear($rateLimitingKey);

            $authenticatedUser = Filament::auth()->user();

            if ($wasMultiFactorChallenge && $authenticatedUser instanceof User) {
                RateLimiter::clear($this->multiFactorRateLimitingKey($authenticatedUser));

                activity()
                    ->performedOn($authenticatedUser)
                    ->causedBy($authenticatedUser)
                    ->event('mfa_challenge_succeeded')
                    ->log('MFA challenge succeeded');
            }
        }

        return $response;
    }

    protected function isMultiFactorChallengeRateLimited(Authenticatable $user): bool
    {
        $rateLimitingKey = $this->multiFactorRateLimitingKey($user);

        if (RateLimiter::tooManyAttempts($rateLimitingKey, maxAttempts: 5)) {
            $this->getRateLimitedNotification(new TooManyRequestsException(
                static::class,
                'authenticate',
                request()->ip(),
                RateLimiter::availableIn($rateLimitingKey),
            ))?->send();

            return true;
        }

        RateLimiter::hit($rateLimitingKey, 60);

        return false;
    }

    private function multiFactorRateLimitingKey(Authenticatable $user): string
    {
        return 'tala-mfa:'.$user->getAuthIdentifier().'|'.request()->ip();
    }

    public function getHeading(): string|Htmlable|null
    {
        if (filled($this->userUndertakingMultiFactorAuthentication)) {
            return parent::getHeading();
        }

        $label = match ($this->requestedContext) {
            'applicant' => 'Applicant',
            'student' => 'Student',
            User::StaffRoleRegistrar => 'Registrar',
            User::StaffRoleAccounting => 'Accounting',
            User::StaffRoleFaculty => 'Faculty',
            User::StaffRoleAcademicHead => 'Academic Head',
            User::StaffRoleSystemSuperAdmin => 'System Administrator',
            default => Filament::getCurrentOrDefaultPanel()->getId() === 'admin' ? 'Staff' : 'TALA',
        };

        return "Sign in to {$label}";
    }
}
