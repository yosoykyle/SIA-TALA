<?php

namespace Tests\Feature;

use App\Http\Controllers\PublicApplicationStatusController;
use App\Mail\ApplicationStatusLinkMail;
use App\Models\AdmissionApplication;
use App\Models\Term;
use App\Models\User;
use App\Support\AdmissionApplicationReference;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PublicApplicationStatusTrackingTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('test_tala_db', config('database.connections.mysql.database'));
        config(['cache.default' => 'array']);
        Role::findOrCreate('applicant', 'web');
    }

    public function test_verified_owner_receives_a_scoped_link_with_only_safe_current_status(): void
    {
        Mail::fake();
        $owner = $this->owner();
        $application = AdmissionApplication::factory()->recycle(Term::query()->firstOrFail())->submitted()->create([
            'user_id' => $owner->id,
            'email' => 'private-contact@example.test',
            'first_name' => 'PrivateFirstname',
            'last_name' => 'PrivateLastname',
        ]);

        $this->post(route('applications.status.request'), [
            'reference' => strtolower($application->application_reference), 'email' => strtoupper($owner->email),
        ])->assertRedirect(route('home').'#application-status')
            ->assertSessionHas('tracking_status', PublicApplicationStatusController::Confirmation);
        Mail::assertQueued(ApplicationStatusLinkMail::class, fn ($mail): bool => $mail->hasTo($owner->email));
        $url = Mail::queued(ApplicationStatusLinkMail::class)->first()->statusUrl;
        $this->get($url)->assertOk()->assertSee('Under Registrar review')
            ->assertSee('No action is needed now.')
            ->assertDontSee('PrivateFirstname')->assertDontSee('private-contact@example.test')
            ->assertDontSee('View evidence')->assertDontSee('Acknowledgment')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertGuest();
        $this->assertSame(AdmissionApplication::StateSubmitted, $application->fresh()->application_state);

        $application->update(['application_state' => AdmissionApplication::StateActionNeeded]);
        $this->get($url)->assertOk()->assertSee('Corrections requested')->assertSee('Sign in to review');
    }

    public function test_missing_mismatched_disabled_and_unverified_requests_have_the_same_confirmation(): void
    {
        foreach (['missing', 'mismatched', 'disabled', 'unverified', 'wrong-role'] as $case) {
            Mail::fake();
            $owner = $this->owner();
            $application = AdmissionApplication::factory()->recycle(Term::query()->firstOrFail())->submitted()->create(['user_id' => $owner->id]);
            if ($case === 'disabled') {
                $owner->update(['status' => User::StatusDisabled]);
            }
            if ($case === 'unverified') {
                $owner->forceFill(['email_verified_at' => null])->save();
            }
            if ($case === 'wrong-role') {
                $owner->syncRoles([]);
            }
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.random_int(1, 200)])
                ->post(route('applications.status.request'), [
                    'reference' => $case === 'missing' ? 'APP-2026-MISSING' : $application->application_reference,
                    'email' => $case === 'mismatched' ? 'other-'.$owner->email : $owner->email,
                ])->assertRedirect(route('home').'#application-status')
                ->assertSessionHas('tracking_status', PublicApplicationStatusController::Confirmation);
            $this->assertCount(0, Mail::queued(ApplicationStatusLinkMail::class), $case);
        }
    }

    public function test_tampering_expiry_owner_and_email_changes_revoke_link_access(): void
    {
        Mail::fake();
        $this->freezeTime();
        $owner = $this->owner();
        $application = AdmissionApplication::factory()->recycle(Term::query()->firstOrFail())->submitted()->create(['user_id' => $owner->id]);
        $this->post(route('applications.status.request'), ['reference' => $application->application_reference, 'email' => $owner->email]);
        $url = Mail::queued(ApplicationStatusLinkMail::class)->first()->statusUrl;
        $this->get($url.'&changed=1')->assertForbidden()->assertSee('This status link is unavailable')->assertDontSee($application->application_reference);
        $verifiedAt = $owner->email_verified_at;
        $owner->update(['status' => User::StatusDisabled]);
        $this->get($url)->assertForbidden();
        $owner->forceFill(['status' => User::StatusActive, 'email_verified_at' => null])->save();
        $this->get($url)->assertForbidden();
        $owner->forceFill(['email_verified_at' => $verifiedAt])->save();
        $owner->update(['email' => 'changed-'.$owner->email]);
        $this->get($url)->assertForbidden()->assertSee('Request a new link');
        $owner->refresh();
        $this->post(route('applications.status.request'), ['reference' => $application->application_reference, 'email' => $owner->email]);
        $url = Mail::queued(ApplicationStatusLinkMail::class)->last()->statusUrl;
        $application->update(['user_id' => $this->owner()->id]);
        $this->get($url)->assertForbidden()->assertDontSee($application->application_reference);
        $application->update(['user_id' => $owner->id]);
        $this->travel(16)->minutes();
        $this->get($url)->assertForbidden()->assertSee('This status link is unavailable');
    }

    public function test_request_limit_and_mail_failure_preserve_uniform_outcome(): void
    {
        Mail::fake();
        $owner = $this->owner();
        $application = AdmissionApplication::factory()->recycle(Term::query()->firstOrFail())->submitted()->create(['user_id' => $owner->id]);
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $this->post(route('applications.status.request'), ['reference' => $application->application_reference, 'email' => $owner->email])
                ->assertSessionHas('tracking_status', PublicApplicationStatusController::Confirmation);
        }
        Mail::assertQueued(ApplicationStatusLinkMail::class, 3);
        $other = $this->owner();
        $otherApplication = AdmissionApplication::factory()->recycle(Term::query()->firstOrFail())->submitted()->create(['user_id' => $other->id]);
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('Mail unavailable'));
        $this->post(route('applications.status.request'), ['reference' => $otherApplication->application_reference, 'email' => $other->email])
            ->assertSessionHas('tracking_status', PublicApplicationStatusController::Confirmation);
    }

    public function test_missing_fields_have_actionable_validation_and_unverified_link_cannot_reveal_status(): void
    {
        Mail::fake();
        $this->from(route('home'))->post(route('applications.status.request'), [])
            ->assertSessionHasErrors(['reference', 'email']);
        Mail::assertNothingQueued();
        $this->get(route('applications.status.show', ['token' => str_repeat('a', 64)]))
            ->assertForbidden()->assertSee('This status link is unavailable')->assertSee('Sign in');
    }

    public function test_new_references_are_readable_unique_and_do_not_rewrite_existing_values(): void
    {
        $existing = AdmissionApplication::factory()->recycle(Term::query()->firstOrFail())->submitted()->create(['application_reference' => 'APP-2026-AAAA-AAAA-AAAA']);
        $sequence = 0;
        Str::createRandomStringsUsing(function (int $length) use (&$sequence): string {
            return str_repeat($sequence++ === 0 ? 'A' : 'B', $length);
        });
        try {
            $this->assertSame('APP-2026-BBBB-BBBB-BBBB', AdmissionApplicationReference::generate(2026));
            $this->assertSame('APP-2026-AAAA-AAAA-AAAA', $existing->fresh()->application_reference);
        } finally {
            Str::createRandomStringsNormally();
        }
        $this->assertMatchesRegularExpression('/^APP-2026-(?:[2-9A-HJKMNP-Z]{4}-){2}[2-9A-HJKMNP-Z]{4}$/', AdmissionApplicationReference::generate(2026));
    }

    public function test_role_removal_and_missing_cached_scope_make_issued_links_unavailable(): void
    {
        Mail::fake();
        $owner = $this->owner();
        $application = AdmissionApplication::factory()->recycle(Term::query()->firstOrFail())->submitted()->create(['user_id' => $owner->id]);
        $this->post(route('applications.status.request'), ['reference' => $application->application_reference, 'email' => $owner->email]);
        $url = Mail::queued(ApplicationStatusLinkMail::class)->first()->statusUrl;
        $owner->syncRoles([]);
        $this->get($url)->assertForbidden()->assertDontSee($application->application_reference);
        $owner->assignRole('applicant');
        $token = basename(parse_url($url, PHP_URL_PATH));
        Cache::forget('application-tracking:'.hash('sha256', $token));
        $this->get($url)->assertForbidden()->assertSee('Request a new link');
    }

    public function test_ip_limit_applies_across_different_email_addresses(): void
    {
        Mail::fake();
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $owner = $this->owner();
            $application = AdmissionApplication::factory()->recycle(Term::query()->firstOrFail())->submitted()->create(['user_id' => $owner->id]);
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.57'])
                ->post(route('applications.status.request'), ['reference' => $application->application_reference, 'email' => $owner->email])
                ->assertSessionHas('tracking_status', PublicApplicationStatusController::Confirmation);
        }
        Mail::assertQueued(ApplicationStatusLinkMail::class, 5);
    }

    public function test_public_status_uses_current_admission_readiness_and_escapes_published_office_text(): void
    {
        Mail::fake();
        $owner = $this->owner();
        $application = AdmissionApplication::factory()->recycle(Term::query()->firstOrFail())->submitted()->create(['user_id' => $owner->id]);
        $application->admissionCycle->update(['support_contact' => '<script>tracking-xss</script>']);
        $this->post(route('applications.status.request'), ['reference' => $application->application_reference, 'email' => $owner->email]);
        $url = Mail::queued(ApplicationStatusLinkMail::class)->first()->statusUrl;
        $application->update(['application_state' => AdmissionApplication::StateAdmitted]);
        $this->get($url)->assertOk()->assertSee('Admitted — clearance pending')
            ->assertSee('&lt;script&gt;tracking-xss&lt;/script&gt;', false)
            ->assertDontSee('<script>tracking-xss</script>', false);
    }

    public function test_repeated_link_reads_are_rate_limited(): void
    {
        $url = route('applications.status.show', ['token' => str_repeat('a', 64)]);
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.58'])->get($url)->assertForbidden();
        }
        $this->get($url)->assertTooManyRequests();
    }

    public function test_reference_allocation_exhaustion_is_actionable_and_preserves_the_existing_record(): void
    {
        $existing = AdmissionApplication::factory()->recycle(Term::query()->firstOrFail())->submitted()
            ->create(['application_reference' => 'APP-2026-AAAA-AAAA-AAAA']);
        Str::createRandomStringsUsing(fn (int $length): string => str_repeat('A', $length));
        try {
            AdmissionApplicationReference::generate(2026);
            $this->fail('An occupied reference must not be reused.');
        } catch (ValidationException $exception) {
            $this->assertSame('A reference could not be assigned. Your draft remains saved; please try submitting again.', $exception->errors()['application_reference'][0]);
            $this->assertSame('APP-2026-AAAA-AAAA-AAAA', $existing->fresh()->application_reference);
        } finally {
            Str::createRandomStringsNormally();
        }
    }

    private function owner(): User
    {
        $owner = User::factory()->create(['status' => User::StatusActive, 'email_verified_at' => now()]);
        $owner->assignRole('applicant');

        return $owner;
    }
}
