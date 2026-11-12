<?php

namespace Tests\Feature;

use App\Actions\Academics\AcademicEnrollmentGuidance;
use App\Actions\Enrollment\RegistrationEntryContext;
use App\Actions\Enrollment\StartRegistrationCase;
use App\Actions\StudentHub\StudentHubPriorityResolver;
use App\Filament\Resources\StudentProfiles\Pages\ViewStudentProfile;
use App\Filament\Student\Pages\Academics;
use App\Filament\Student\Pages\Enrollment as StudentEnrollment;
use App\Models\AcademicDecision;
use App\Models\Enrollment;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCalendarWindow;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AcademicEnrollmentGuidanceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-11 12:00:00', 'Asia/Manila'));
        $this->assertSame((string) env('TALA_TEST_DATABASE', 'test_tala_db'), DB::selectOne('SELECT DATABASE() AS name')->name);
        Role::findOrCreate('student', 'web');
        Filament::setCurrentPanel(Filament::getPanel('student'));
    }

    public function test_open_calendar_keeps_exact_term_academic_block_and_safe_recovery(): void
    {
        $student = $this->student();
        $term = $this->openTerm('Next enrollment');
        $this->decision($student, $term, AcademicDecision::EffectBlocked, 'Registrar review is required for this Term.');

        $context = app(RegistrationEntryContext::class)->forUser($student->user);
        $guidance = app(AcademicEnrollmentGuidance::class)->forStudent($student);

        $this->assertFalse($context['can_start']);
        $this->assertSame('academic_restriction', $context['context_state']);
        $this->assertSame($term->id, $guidance['term_id']);
        $this->assertSame(AcademicDecision::EffectBlocked, $guidance['effect']);
        $this->assertStringContainsString($term->academicYear->label, $guidance['scope_label']);
        $this->assertSame('Registrar review is required for this Term.', $context['reason']);
        $notice = app(StudentHubPriorityResolver::class)->resolve($student);
        $this->assertSame('Academic Guidance', $notice['tier']);
        $this->assertSame($term->id, $notice['term_id']);

        Livewire::actingAs($student->user)->withQueryParams(['view' => 'plan'])->test(Academics::class)
            ->assertSee('Blocked')->assertSee($guidance['scope_label'])
            ->assertSee('Registrar review is required for this Term.')
            ->assertSee(StudentEnrollment::getUrl(['term' => $term->id], panel: 'student'), escape: false);
        Livewire::actingAs($student->user)->withQueryParams(['term' => $term->id])->test(StudentEnrollment::class)
            ->assertSee('Academic review needed')
            ->assertSee($term->academicYear->label)
            ->assertSee('Registrar Office')
            ->assertSee('Registrar review is required for this Term.')
            ->assertActionHidden('startRegistration');

        try {
            app(StartRegistrationCase::class)->forContinuingStudent($student, $term, $student->user);
            $this->fail('The academic block must still prevent self-service enrollment.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('academic_effect', $exception->errors());
        }
        $this->assertDatabaseMissing('enrollments', ['credential_user_id' => $student->user_id]);

        Role::findOrCreate(User::StaffRoleRegistrar, 'web');
        $registrar = User::factory()->create();
        $registrar->assignRole(User::StaffRoleRegistrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($registrar)->test(ViewStudentProfile::class, ['record' => $student->id])
            ->assertSee($guidance['scope_label'])
            ->assertSee('Registrar review is required for this Term.');
    }

    public function test_multiple_open_terms_require_confirmation_without_silently_choosing_current_studies(): void
    {
        $student = $this->student();
        $first = $this->openTerm('First open Term');
        $second = $this->openTerm('Second open Term');

        $guidance = app(AcademicEnrollmentGuidance::class)->forStudent($student);
        $this->assertSame('ambiguous', $guidance['context_state']);
        $this->assertNull($guidance['term_id']);
        $this->assertTrue($guidance['needs_attention']);
        $this->assertSame('Enrollment Term needs confirmation', $guidance['label']);
        $this->assertStringContainsString('Registrar', $guidance['student_reason']);
        $this->assertFalse(app(RegistrationEntryContext::class)->forUser($student->user)['can_start']);

        $this->decision($student, $second, AcademicDecision::EffectPendingDecision, 'Wait for the decision on the second Term.');
        $explicit = app(RegistrationEntryContext::class)->forUser($student->user, $second);
        $this->assertSame($second->id, $explicit['guidance_term']->id);
        $this->assertSame('academic_restriction', $explicit['context_state']);
        $this->assertSame('Wait for the decision on the second Term.', $explicit['reason']);
        $this->assertTrue(app(RegistrationEntryContext::class)->forUser($student->user, $first)['can_start']);
    }

    public function test_closed_calendar_and_student_wide_scope_are_distinct_from_an_open_term_restriction(): void
    {
        $student = $this->student();
        $term = $this->openTerm('Closed enrollment Term');
        TermCalendarWindow::query()->whereHas('package', fn ($query) => $query->where('term_id', $term->id))
            ->update(['closes_on' => '2026-11-10']);
        $this->decision($student, $term, AcademicDecision::EffectBlocked, 'Only this Term is blocked.');

        $guidance = app(AcademicEnrollmentGuidance::class)->forStudent($student);
        $this->assertSame('closed', $guidance['context_state']);
        $this->assertNull($guidance['term_id']);
        $this->assertSame(AcademicDecision::EffectAllowed, $guidance['effect']);
        $this->assertStringContainsString('Student-wide', $guidance['scope_label']);
        $this->assertSame('closed', app(RegistrationEntryContext::class)->forUser($student->user, $term)['context_state']);

        $this->decision($student, null, AcademicDecision::EffectPendingDecision, 'A student-wide review is pending.');
        $guidance = app(AcademicEnrollmentGuidance::class)->forStudent($student);
        $this->assertSame(AcademicDecision::EffectPendingDecision, $guidance['effect']);
        $this->assertTrue($guidance['needs_attention']);
        $this->assertSame('closed', $guidance['context_state']);
    }

    public function test_guidance_uses_next_enrollment_term_while_an_existing_record_keeps_its_own_term(): void
    {
        $student = $this->student();
        $current = Term::factory()->create(['state' => Term::StateActive, 'label' => 'Current studies']);
        $case = Enrollment::factory()->create([
            'student_profile_id' => $student->id, 'credential_user_id' => $student->user_id,
            'term_id' => $current->id, 'canonical_outcome' => Enrollment::OutcomeInProgress,
        ]);
        $next = $this->openTerm('Next studies');
        $unrelated = Term::factory()->create();
        $this->decision($student, $unrelated, AcademicDecision::EffectBlocked, 'Unrelated decision must stay out.');

        $guidance = app(AcademicEnrollmentGuidance::class)->forStudent($student);
        $this->assertSame($next->id, $guidance['term_id']);
        $this->assertSame(AcademicDecision::EffectAllowed, $guidance['effect']);
        $this->assertSame($current->id, $case->fresh()->term_id);
        $this->assertSame('Next studies', app(RegistrationEntryContext::class)->forUser($student->user)['guidance_term']->label);

        $this->decision($student, $next, AcademicDecision::EffectBlocked, 'Future decision is not active yet.', ['effective_from' => '2026-11-12']);
        $this->assertSame(AcademicDecision::EffectAllowed, app(AcademicEnrollmentGuidance::class)->forStudent($student)['effect']);
    }

    public function test_recovery_context_never_opens_an_unconfigured_or_wrong_audience_window(): void
    {
        $student = $this->student();
        $wrongAudience = $this->openTerm('Applicants only');
        $wrongAudience->update(['registration_applicability' => 'ReadyApplicants']);
        $this->decision($student, $wrongAudience, AcademicDecision::EffectBlocked, 'Wrong-audience decision.');
        $this->assertNull(app(AcademicEnrollmentGuidance::class)->forStudent($student)['term_id']);
        $this->assertFalse(app(RegistrationEntryContext::class)->forUser($student->user, $wrongAudience)['can_start']);

        $unconfigured = Term::factory()->create();
        Livewire::actingAs($student->user)->withQueryParams(['term' => $unconfigured->id])->test(StudentEnrollment::class)
            ->assertSee('Enrollment gate is not configured for this term.')
            ->assertActionHidden('startRegistration');
        Livewire::actingAs($student->user)->withQueryParams(['term' => PHP_INT_MAX])->test(StudentEnrollment::class)
            ->assertNotFound();
    }

    private function student(): StudentProfile
    {
        $student = StudentProfile::factory()->create();
        $student->user->assignRole('student');

        return $student;
    }

    private function openTerm(string $label): Term
    {
        $term = Term::factory()->create(['label' => $label, 'state' => Term::StateActive, 'registration_applicability' => 'AllEligible']);
        $package = TermCalendarPackage::factory()->create(['term_id' => $term->id, 'state' => TermCalendarPackage::StateActive]);
        TermCalendarWindow::factory()->create([
            'term_calendar_package_id' => $package->id, 'window_type' => TermCalendarWindow::TypeEnrollment,
            'opens_on' => '2026-11-01', 'closes_on' => '2026-11-30',
        ]);

        return $term;
    }

    /** @param array<string, mixed> $attributes */
    private function decision(StudentProfile $student, ?Term $term, string $effect, string $reason, array $attributes = []): void
    {
        AcademicDecision::query()->create([
            'student_profile_id' => $student->id, 'term_id' => $term?->id, 'effect' => $effect,
            'state' => 'ACTIVE', 'reason' => $reason, 'effective_from' => '2026-11-01',
            'recorded_by' => $student->user_id, 'recorded_at' => now(), ...$attributes,
        ]);
    }
}
