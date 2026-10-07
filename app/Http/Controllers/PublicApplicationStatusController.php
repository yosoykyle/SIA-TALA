<?php

namespace App\Http\Controllers;

use App\Http\Requests\RequestApplicationStatusLink;
use App\Mail\ApplicationStatusLinkMail;
use App\Models\AdmissionApplication;
use App\Models\User;
use App\Queries\Admissions\ReadyApplicantProjectionQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;

class PublicApplicationStatusController extends Controller
{
    public const Confirmation = 'If these details match an eligible application, we will email a private status link. You can also sign in.';

    public function store(RequestApplicationStatusLink $request): RedirectResponse
    {
        $ipKey = 'application-tracking:ip:'.hash('sha256', (string) $request->ip());
        $emailKey = 'application-tracking:email:'.hash('sha256', $request->string('email')->toString());

        try {
            if (RateLimiter::tooManyAttempts($ipKey, 5) || RateLimiter::tooManyAttempts($emailKey, 3)) {
                return $this->confirmation();
            }

            RateLimiter::hit($ipKey, 60);
            RateLimiter::hit($emailKey, 900);
            $application = AdmissionApplication::query()
                ->where('application_reference', $request->string('reference')->toString())
                ->first();
            $owner = $application?->user;

            if ($application && $this->eligible($owner)
                && hash_equals(strtolower($owner->email), $request->string('email')->toString())) {
                $token = Str::random(64);
                $expires = now()->addMinutes(15);
                Cache::put('application-tracking:'.hash('sha256', $token), [
                    'application_id' => $application->id,
                    'owner_id' => $owner->id,
                    'email_binding' => $this->emailBinding($owner),
                ], $expires);
                $url = URL::temporarySignedRoute('applications.status.show', $expires, ['token' => $token]);
                Mail::to($owner->email)->queue(new ApplicationStatusLinkMail($url));
            }
        } catch (Throwable) {
            Log::warning('An application-status link request could not be completed.');
        }

        return $this->confirmation();
    }

    public function show(Request $request, string $token): Response
    {
        $application = null;

        try {
            $access = $request->hasValidSignature()
                ? Cache::get('application-tracking:'.hash('sha256', $token))
                : null;

            if (is_array($access)) {
                $candidate = AdmissionApplication::query()->find($access['application_id'] ?? null);
                $owner = $candidate?->user;

                if ($candidate && $this->eligible($owner)
                    && $owner->id === ($access['owner_id'] ?? null)
                    && hash_equals($this->emailBinding($owner), (string) ($access['email_binding'] ?? ''))) {
                    $application = $candidate;
                }
            }

            $projection = $application ? $this->projection($application) : null;
        } catch (Throwable) {
            Log::warning('An application-status view could not be completed.');
            $projection = null;
            $application = null;
        }

        return response()->view('admissions.public-status', [
            'reference' => $application?->application_reference,
            'projection' => $projection,
        ], $application ? 200 : 403)->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    private function confirmation(): RedirectResponse
    {
        return redirect()->to(route('home').'#application-status')
            ->with('tracking_status', self::Confirmation);
    }

    private function eligible(?User $owner): bool
    {
        return $owner !== null && $owner->status === User::StatusActive
            && $owner->hasRole('applicant') && $owner->hasVerifiedEmail() && $owner->canAuthenticate();
    }

    private function emailBinding(User $owner): string
    {
        return hash('sha256', strtolower($owner->email).'|'.$owner->email_verified_at?->toIso8601String());
    }

    /** @return array{status: string, next_action: string, office: string} */
    private function projection(AdmissionApplication $application): array
    {
        [$status, $nextAction] = match ($application->application_state) {
            AdmissionApplication::StateDraft => ['Draft saved', 'Sign in to continue your application and check the application deadline.'],
            AdmissionApplication::StateSubmitted => ['Under Registrar review', 'No action is needed now. Sign in to check requirements and messages.'],
            AdmissionApplication::StateActionNeeded => ['Corrections requested', 'Sign in to review the requested changes and due date.'],
            AdmissionApplication::StateAdmitted => [
                app(ReadyApplicantProjectionQuery::class)->forApplication($application)['ready']
                    ? 'Ready for enrollment' : 'Admitted — clearance pending',
                'Sign in for your current enrollment steps. Admission does not yet mean official enrollment.',
            ],
            AdmissionApplication::StateNotAdmitted => ['Not admitted', 'Sign in to read the admission decision. Contact the Registrar for guidance.'],
            AdmissionApplication::StateWithdrawn => ['Withdrawn', 'Contact the Registrar if you need guidance about this application.'],
            default => ['Status unavailable', 'Sign in or contact the Registrar for guidance.'],
        };

        return [
            'status' => $status,
            'next_action' => $nextAction,
            'office' => $application->admissionCycle?->support_contact ?: 'Contact the Registrar through school Support.',
        ];
    }
}
