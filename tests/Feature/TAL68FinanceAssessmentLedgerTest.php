<?php

namespace Tests\Feature;

use App\Actions\Enrollment\StartRegistrationCase;
use App\Actions\Finance\CreateAssessmentFromPublishedFeePlan;
use App\Actions\Finance\CreateFeePlanDraft;
use App\Actions\Finance\CreateSuccessorFeePlan;
use App\Actions\Finance\PublishFeePlan;
use App\Actions\Finance\UpdateFeePlanDraft;
use App\Filament\Resources\Assessments\AssessmentResource;
use App\Filament\Resources\Enrollments\Pages\ViewEnrollment;
use App\Filament\Resources\FeePlans\FeePlanResource;
use App\Filament\Resources\FeePlans\Pages\ListFeePlans;
use App\Filament\Resources\FeePlans\Pages\ViewFeePlan;
use App\Filament\Resources\FeeRules\FeeRuleResource;
use App\Models\Assessment;
use App\Models\CurriculumEntry;
use App\Models\Enrollment;
use App\Models\FeePlan;
use App\Models\LedgerEntry;
use App\Models\Program;
use App\Models\PublishedTimetableMeeting;
use App\Models\PublishedTimetableVersion;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCalendarWindow;
use App\Models\TermOffering;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\TAL96D5BAcceptanceStateSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL68FinanceAssessmentLedgerTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', app()->environment());
        $this->assertSame((string) env('TALA_TEST_DATABASE', 'test_tala_db'), DB::connection()->getDatabaseName());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Mail::fake();
        foreach ([User::StaffRoleAccounting, User::StaffRoleRegistrar, User::StaffRoleFaculty, User::StaffRoleSystemSuperAdmin, 'student'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_presentation_overlay_keeps_its_fixed_sample_account_without_a_retired_assessment_service(): void
    {
        $accounting = $this->staff(User::StaffRoleAccounting);
        $accounting->update(['email' => 'accounting.demo@example.test']);
        $term = Term::factory()->create();
        $student = StudentProfile::factory()->create();
        $case = Enrollment::factory()->for($student)->for($term)->create();
        $seeder = app(TAL96D5BAcceptanceStateSeeder::class);
        $fixtureAssessment = new \ReflectionMethod($seeder, 'ensureActiveAssessment');

        $assessment = $fixtureAssessment->invoke($seeder, $case, $term);

        $this->assertSame('3000.00', $assessment->total);
        $this->assertSame('2000.00', $assessment->required_downpayment);
        $this->assertSame(['2000.00', '1000.00'], $assessment->obligations()->orderBy('sequence')->pluck('amount')->all());
        $this->assertSame('3000.00', $assessment->lines()->sum('amount'));
        $this->assertSame($student->user_id, $assessment->termAccount->credential_user_id);
        $this->assertSame($assessment->id, $fixtureAssessment->invoke($seeder, $case->fresh(), $term)->id);
        $this->assertSame(1, Assessment::query()->whereBelongsTo($case)->count());
        $charges = LedgerEntry::query()->where('enrollment_id', $case->id)->where('direction', LedgerEntry::DirectionCharge);
        $this->assertSame(1, $charges->count());
        $this->assertSame('3000.00', $charges->sum('amount'));
        $this->assertSame(0, $case->corVersions()->count());
    }

    public function test_accounting_can_create_a_fixed_fee_plan_draft_from_the_bounded_surface(): void
    {
        $accounting = $this->staff(User::StaffRoleAccounting);
        $program = Program::factory()->create();
        $term = Term::factory()->create();

        $component = Livewire::actingAs($accounting)
            ->test(ListFeePlans::class)
            ->mountAction('createDraft');
        $chargeKey = array_key_first($component->get('mountedActions.0.data.charges'));
        $obligationKey = array_key_first($component->get('mountedActions.0.data.obligations'));

        $component->setActionData([
            'program_id' => $program->id,
            'term_id' => $term->id,
            'charges' => [$chargeKey => [
                'code' => 'LAB',
                'label' => 'Laboratory Fee',
                'category' => 'InstitutionalFee',
                'amount' => '350.00',
            ]],
            'obligations' => [$obligationKey => [
                'code' => 'ENROLLMENT',
                'label' => 'Enrollment obligation',
                'purpose' => 'Enrollment',
                'amount' => '350.00',
                'due_at' => now()->addDay(),
                'required_for_enrollment' => true,
            ]],
        ])->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('fee_plans', [
            'program_id' => $program->id,
            'term_id' => $term->id,
            'state' => 'Draft',
        ]);
        $this->assertDatabaseHas('fee_plan_charges', [
            'code' => 'LAB',
            'label' => 'Laboratory Fee',
            'amount' => '350.00',
        ]);
    }

    public function test_fee_rule_per_unit_surface_is_retired_from_accounting_navigation(): void
    {
        $accounting = $this->staff(User::StaffRoleAccounting);
        $this->actingAs($accounting);

        $this->assertFalse(FeeRuleResource::shouldRegisterNavigation());
        $this->assertFalse(FeeRuleResource::canAccess());
        $this->assertTrue(FeePlanResource::shouldRegisterNavigation());
    }

    public function test_new_fee_plan_form_generates_source_codes_and_records_the_first_payment_with_manila_dates(): void
    {
        $actor = $this->staff(User::StaffRoleAccounting);
        $program = Program::factory()->create();
        $term = Term::factory()->create();
        Livewire::actingAs($actor)->test(ListFeePlans::class)->mountAction('createDraft')
            ->setActionData([
                'program_id' => $program->id, 'term_id' => $term->id,
                'charges' => [['label' => 'Tuition', 'amount' => '100.25']],
                'obligations' => [
                    ['label' => 'Enrollment payment', 'amount' => '40.10', 'due_at' => '2026-10-20 09:30:00', 'required_for_enrollment' => false],
                    ['label' => 'Later payment', 'amount' => '60.15', 'due_at' => '2026-11-20 10:45:00', 'required_for_enrollment' => true],
                ],
            ])->callMountedAction()->assertHasNoActionErrors();

        $plan = FeePlan::query()->where('program_id', $program->id)->where('term_id', $term->id)->sole();
        $this->assertMatchesRegularExpression('/^CHG-[a-f0-9-]{36}$/', $plan->charges()->sole()->code);
        $payments = $plan->obligations()->get();
        $this->assertSame(['40.10', '60.15'], $payments->pluck('amount')->all());
        $this->assertSame([true, false], $payments->pluck('required_for_enrollment')->all());
        $this->assertSame(['Enrollment', 'TermPayment'], $payments->pluck('purpose')->all());
        $this->assertNotSame($payments[0]->code, $payments[1]->code);
        $this->assertMatchesRegularExpression('/^PAY-[a-f0-9-]{36}$/', $payments[0]->code);
        $this->assertSame('2026-10-20 01:30:00', $payments[0]->due_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-20 02:45:00', $payments[1]->due_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('100.25', $plan->charges()->sole()->amount);
    }

    public function test_fee_plan_form_rejects_extra_money_precision_without_creating_a_draft(): void
    {
        $actor = $this->staff(User::StaffRoleAccounting);
        $program = Program::factory()->create();
        $term = Term::factory()->create();
        $component = Livewire::actingAs($actor)->test(ListFeePlans::class)->mountAction('createDraft');
        $chargeKey = array_key_first($component->get('mountedActions.0.data.charges'));
        $paymentKey = array_key_first($component->get('mountedActions.0.data.obligations'));
        $component->setActionData([
            'program_id' => $program->id, 'term_id' => $term->id,
            'charges' => [$chargeKey => ['label' => 'Tuition', 'amount' => '100.255']],
            'obligations' => [$paymentKey => ['label' => 'Enrollment payment', 'amount' => '100.255', 'due_at' => '2026-10-20 09:30:00']],
        ])->callMountedAction()->assertHasActionErrors([
            'charges.'.$chargeKey.'.amount' => 'decimal', 'obligations.'.$paymentKey.'.amount' => 'decimal',
        ]);
        $this->assertSame(0, FeePlan::query()->where('program_id', $program->id)->where('term_id', $term->id)->count());
    }

    public function test_fee_plan_action_requires_program_term_and_fixed_lines(): void
    {
        $accounting = $this->staff(User::StaffRoleAccounting);

        Livewire::actingAs($accounting)
            ->test(ListFeePlans::class)
            ->callAction('createDraft', data: [
                'program_id' => null,
                'term_id' => null,
                'charges' => [],
            ])
            ->assertHasActionErrors([
                'program_id' => 'required',
                'term_id' => 'required',
                'charges' => 'min',
            ]);

        $this->assertSame(0, DB::table('fee_plans')->count());
    }

    public function test_fee_plans_are_the_accounting_setup_surface_and_legacy_fee_rules_stay_inaccessible(): void
    {
        $accounting = $this->staff(User::StaffRoleAccounting);
        $this->actingAs($accounting);

        $this->assertTrue(FeePlanResource::shouldRegisterNavigation());
        $this->assertFalse(FeeRuleResource::shouldRegisterNavigation());
        $this->assertFalse(class_exists('App\\Filament\\Resources\\FeeTemplates\\FeeTemplateResource'));
    }

    public function test_non_accounting_user_cannot_publish_or_record_fixed_finance_authority(): void
    {
        [$case, $plan, $assessment] = $this->fixedAssessment();
        $faculty = $this->staff(User::StaffRoleFaculty);
        $this->assertFalse(Gate::forUser($faculty)->allows('create', FeePlan::class));
        $this->assertFalse(Gate::forUser($faculty)->allows('activate', $assessment));
        $this->assertFalse(AssessmentResource::canAccess());
        foreach ([fn () => app(PublishFeePlan::class)->execute($plan, $faculty, 'INVALID-AUTHORITY', CarbonImmutable::now()),
            fn () => app(CreateAssessmentFromPublishedFeePlan::class)->execute($case, $faculty)] as $operation) {
            try {
                $operation();
                $this->fail('A non-Accounting actor must not create institutional financial authority.');
            } catch (AuthorizationException) {
                $this->assertSame(1, $case->termAccount->assessments()->count());
                $this->assertSame(0, LedgerEntry::query()->where('enrollment_id', $case->id)->count());
            }
        }
    }

    public function test_current_assessment_reproduces_fixed_program_term_authority_idempotently(): void
    {
        [$case, $plan, $assessment] = $this->fixedAssessment();
        $accounting = $this->staff(User::StaffRoleAccounting);
        $again = app(CreateAssessmentFromPublishedFeePlan::class)->execute($case->fresh(), $accounting);
        $this->assertTrue($again->is($assessment));
        $this->assertSame($plan->id, $assessment->fee_plan_id);
        $this->assertSame('5800.00', $assessment->total);
        $this->assertSame('5800.00', $assessment->lines()->sole()->amount);
        $this->assertSame('1.0000', $assessment->lines()->sole()->quantity);
        $this->assertSame(['1500.00', '4300.00'], $assessment->obligations()->pluck('amount')->all());
        $this->assertSame([true, false], $assessment->obligations()->get()->pluck('required_for_enrollment')->all());
        $this->assertSame(1, $case->termAccount->assessments()->count());
        $this->assertSame(0, $case->courseEnrollments()->count());
    }

    public function test_fixed_assessment_retries_preserve_retained_ledger_history_and_forbid_record_mutation(): void
    {
        [$case, , $assessment] = $this->fixedAssessment();
        $accounting = $this->staff(User::StaffRoleAccounting);
        $historical = LedgerEntry::factory()->create([
            'student_profile_id' => $case->student_profile_id, 'term_id' => $case->term_id,
            'enrollment_id' => $case->id, 'direction' => LedgerEntry::DirectionCharge, 'amount' => '5800.00',
            'source_type' => Assessment::class, 'source_id' => $assessment->id,
            'description' => 'Synthetic retained fixed assessment history',
        ]);
        $before = $historical->fresh()->getAttributes();
        app(CreateAssessmentFromPublishedFeePlan::class)->execute($case->fresh(), $accounting);
        $this->assertSame($before, $historical->fresh()->getAttributes());
        $this->assertSame(1, LedgerEntry::query()->where('enrollment_id', $case->id)->count());
        $this->assertFalse(Schema::hasColumn('ledger_entries', 'running_balance'));
        $this->assertFalse(Gate::forUser($accounting)->allows('update', $historical));
        $this->assertFalse(Gate::forUser($accounting)->allows('delete', $historical));
        $this->assertSame(0, $case->termAccount->payments()->count());
        $this->assertSame(0, $case->termAccount->paymentAttempts()->count());
    }

    public function test_registrar_views_current_finance_summary_without_accounting_mutation_actions(): void
    {
        [$case, , $assessment] = $this->fixedAssessment();
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $this->assertTrue(Gate::forUser($registrar)->allows('view', $assessment));
        $this->assertFalse(Gate::forUser($registrar)->allows('activate', $assessment));
        Livewire::actingAs($registrar)->test(ViewEnrollment::class, ['record' => $case->getRouteKey()])
            ->assertSee('Enrollment payment')
            ->assertSee('PHP 1,500.00')
            ->assertSee('Total charges')
            ->assertSee('PHP 5,800.00')
            ->assertActionHidden('createAssessment')
            ->assertActionHidden('recordIndividualAssessment');
    }

    public function test_stale_draft_edit_preserves_the_newer_content_even_when_parent_timestamp_did_not_change(): void
    {
        $actor = $this->staff(User::StaffRoleAccounting);
        $plan = $this->draftPlan($actor);
        $fingerprint = $plan->contentFingerprint();
        $updatedAt = $plan->updated_at->toIso8601String();
        $charges = $plan->charges->map->only(['code', 'label', 'category', 'amount'])->all();
        $obligations = $plan->obligations->map->only(['code', 'label', 'purpose', 'amount', 'due_at', 'required_for_enrollment'])->all();
        $charges[0]['label'] = 'Reviewed newer charge label';
        app(UpdateFeePlanDraft::class)->execute($plan, $charges, $obligations, $actor, expectedContentFingerprint: $fingerprint);
        $current = $plan->contentFingerprint();
        $this->assertNotSame($fingerprint, $current);
        $this->assertSame($updatedAt, $plan->fresh()->updated_at->toIso8601String());
        $charges[0]['label'] = 'Obsolete form label';

        try {
            app(UpdateFeePlanDraft::class)->execute($plan, $charges, $obligations, $actor, expectedContentFingerprint: $fingerprint);
            $this->fail('A stale draft form overwrote newer content.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Close this form and review', $exception->errors()['fee_plan'][0]);
        }

        $this->assertSame('Reviewed newer charge label', $plan->charges()->sole()->label);
        $this->assertSame(['LEGACY-CHARGE'], $plan->charges()->pluck('code')->all());
        $this->assertSame(['ENROLLMENT-OLD', 'LATER-OLD'], $plan->obligations()->pluck('code')->all());
        $this->assertSame(['Enrollment', 'Recorded later purpose'], $plan->obligations()->pluck('purpose')->all());
        $this->assertSame($current, $plan->fresh()->contentFingerprint());
    }

    public function test_mounted_draft_edit_preserves_custom_source_codes_purposes_and_due_times(): void
    {
        $actor = $this->staff(User::StaffRoleAccounting);
        $plan = $this->draftPlan($actor);
        $component = Livewire::actingAs($actor)->test(ViewFeePlan::class, ['record' => $plan->id])->mountAction('editDraft');
        $charges = $component->get('mountedActions.0.data.charges');
        $payments = $component->get('mountedActions.0.data.obligations');
        $chargeKey = array_key_first($charges);
        $paymentKey = array_key_first($payments);
        $this->assertSame('2026-10-01 20:00', $payments[$paymentKey]['due_at']);
        $charges[$chargeKey]['label'] = 'Revised visible charge label';

        $component->setActionData(['charges' => $charges])->callMountedAction()->assertHasNoActionErrors();

        $this->assertSame('Revised visible charge label', $plan->charges()->sole()->label);
        $this->assertSame('LEGACY-CHARGE', $plan->charges()->sole()->code);
        $this->assertSame('Recorded category', $plan->charges()->sole()->category);
        $stored = $plan->obligations()->get();
        $this->assertSame(['ENROLLMENT-OLD', 'LATER-OLD'], $stored->pluck('code')->all());
        $this->assertSame(['Enrollment', 'Recorded later purpose'], $stored->pluck('purpose')->all());
        $this->assertSame('2026-10-01 12:00:00', $stored[0]->due_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-01 12:00:00', $stored[1]->due_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_mounted_draft_edit_rejects_another_edit_after_its_review_without_overwriting_it(): void
    {
        $actor = $this->staff(User::StaffRoleAccounting);
        $plan = $this->draftPlan($actor);
        $component = Livewire::actingAs($actor)->test(ViewFeePlan::class, ['record' => $plan->id])->mountAction('editDraft');
        $reviewed = $component->get('mountedActions.0.data.reviewed_content_fingerprint');
        $plan->charges()->sole()->update(['label' => 'Saved by another Accounting user']);

        $component->callMountedAction()->assertHasErrors(['fee_plan'])->assertNotified('Draft was not saved');

        $this->assertNotSame($reviewed, $plan->contentFingerprint());
        $this->assertSame('Saved by another Accounting user', $plan->charges()->sole()->label);
        $this->assertSame(FeePlan::StateDraft, $plan->fresh()->state);
    }

    public function test_publication_requires_the_reviewed_content_and_current_authority_before_writing(): void
    {
        $actor = $this->staff(User::StaffRoleAccounting);
        $plan = $this->draftPlan($actor);
        $fingerprint = $plan->contentFingerprint();
        $plan->charges()->sole()->update(['label' => 'Changed after review']);
        try {
            app(PublishFeePlan::class)->execute($plan, $actor, 'APPROVAL-STALE', CarbonImmutable::parse('2026-10-01'), expectedContentFingerprint: $fingerprint);
            $this->fail('Publication accepted a changed reviewed charge.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('before publishing', $exception->errors()['fee_plan'][0]);
        }
        $this->assertSame(FeePlan::StateDraft, $plan->fresh()->state);
        $this->assertNull($plan->fresh()->authority_reference);
        $published = app(PublishFeePlan::class)->execute($plan, $actor, 'APPROVAL-CURRENT', CarbonImmutable::parse('2026-10-01'), expectedContentFingerprint: $plan->contentFingerprint());
        $this->assertSame(FeePlan::StatePublished, $published->state);
        $this->assertSame('APPROVAL-CURRENT', $published->authority_reference);
        $this->assertSame('Changed after review', $published->charges->sole()->label);
        $this->assertSame(0, Assessment::query()->where('fee_plan_id', $plan->id)->count());
    }

    public function test_mounted_publication_reviews_the_successor_and_rejects_changed_rows_without_changing_existing_assessments(): void
    {
        [$case, $predecessor, $assessment] = $this->fixedAssessment();
        $actor = $this->staff(User::StaffRoleAccounting);
        $source = $assessment->fresh()->getAttributes();
        $oldCharges = $predecessor->charges->map->only(['code', 'label', 'amount'])->all();
        $successor = app(CreateSuccessorFeePlan::class)->execute($predecessor, $actor);
        $component = Livewire::actingAs($actor)->test(ViewFeePlan::class, ['record' => $successor->id])->mountAction('publish')
            ->assertMountedActionModalSee('Synthetic approved fixed total')
            ->assertMountedActionModalSee('Enrollment requirement')->assertMountedActionModalSee('Later balance')
            ->assertMountedActionModalSee('1 existing account will keep their recorded assessments.')
            ->setActionData(['authority_reference' => 'MOUNTED-NEW-APPROVAL', 'authority_date' => '2026-10-18']);
        $reviewed = $component->get('mountedActions.0.data.reviewed_plan');
        $this->assertSame(1, $reviewed['retained_accounts']);
        $this->assertSame($predecessor->version, $reviewed['predecessor_version']);
        $this->assertCount(2, $reviewed['payments']);
        $this->assertNotEmpty($reviewed['payments'][0]['due_at']);
        $successor->charges()->sole()->update(['label' => 'Changed after opening publication']);

        $component->callMountedAction()->assertHasErrors(['fee_plan'])->assertNotified('Plan was not published');

        $this->assertSame(FeePlan::StateDraft, $successor->fresh()->state);
        $this->assertNull($successor->fresh()->authority_reference);
        $this->assertSame(FeePlan::StatePublished, $predecessor->fresh()->state);
        Livewire::actingAs($actor)->test(ViewFeePlan::class, ['record' => $successor->id])->mountAction('publish')
            ->assertMountedActionModalSee('Changed after opening publication')
            ->setActionData(['authority_reference' => 'MOUNTED-CURRENT-APPROVAL', 'authority_date' => '2026-10-18'])
            ->callMountedAction()->assertHasNoActionErrors();
        $this->assertSame('MOUNTED-CURRENT-APPROVAL', $successor->fresh()->authority_reference);
        $this->assertSame(FeePlan::StatePublished, $successor->fresh()->state);
        $this->assertSame(FeePlan::StateSuperseded, $predecessor->fresh()->state);
        $this->assertSame($oldCharges, $predecessor->fresh('charges')->charges->map->only(['code', 'label', 'amount'])->all());
        $this->assertSame($source, $assessment->fresh()->getAttributes());
        $this->assertSame(1, $case->termAccount->assessments()->count());
    }

    public function test_mounted_zero_enrollment_payment_publication_requires_separate_institutional_approval(): void
    {
        $actor = $this->staff(User::StaffRoleAccounting);
        $plan = $this->draftPlan($actor);
        $plan->obligations()->where('sequence', 1)->firstOrFail()->update(['amount' => '0.00']);
        $plan->obligations()->where('sequence', 2)->firstOrFail()->update(['amount' => '100.00']);
        $component = Livewire::actingAs($actor)->test(ViewFeePlan::class, ['record' => $plan->id])->mountAction('publish')
            ->assertMountedActionModalSee('Zero-payment approval reference')
            ->setActionData(['authority_reference' => 'GENERAL-APPROVAL', 'authority_date' => '2026-10-18'])
            ->callMountedAction()->assertHasActionErrors(['no_payment_required_authority_reference' => 'required']);
        $this->assertSame(FeePlan::StateDraft, $plan->fresh()->state);
        $component->setActionData(['no_payment_required_authority_reference' => 'EXPLICIT-ZERO-APPROVAL'])
            ->callMountedAction()->assertHasNoActionErrors();
        $this->assertSame(FeePlan::StatePublished, $plan->fresh()->state);
        $this->assertSame('GENERAL-APPROVAL', $plan->fresh()->authority_reference);
        $this->assertSame('EXPLICIT-ZERO-APPROVAL', $plan->fresh()->no_payment_required_authority_reference);
    }

    public function test_successor_allocates_a_free_scope_version_preserves_codes_and_rejects_a_stale_source(): void
    {
        $actor = $this->staff(User::StaffRoleAccounting);
        $plan = $this->draftPlan($actor);
        $published = app(PublishFeePlan::class)->execute($plan, $actor, 'APPROVAL-V1', CarbonImmutable::parse('2026-10-01'));
        $otherDraft = $this->draftPlan($actor, $plan->program, $plan->term);
        $fingerprint = $published->contentFingerprint();

        $successor = app(CreateSuccessorFeePlan::class)->execute($published, $actor, expectedContentFingerprint: $fingerprint);

        $this->assertSame(2, $otherDraft->version);
        $this->assertSame(3, $successor->version);
        $this->assertSame($published->id, $successor->supersedes_fee_plan_id);
        $this->assertSame(['LEGACY-CHARGE'], $successor->charges->pluck('code')->all());
        $this->assertSame(['ENROLLMENT-OLD', 'LATER-OLD'], $successor->obligations->pluck('code')->all());
        $this->assertSame(['Enrollment', 'Recorded later purpose'], $successor->obligations->pluck('purpose')->all());
        $again = app(CreateSuccessorFeePlan::class)->execute($published, $actor, expectedContentFingerprint: $fingerprint);
        $this->assertSame($successor->id, $again->id);
        app(PublishFeePlan::class)->execute($successor, $actor, 'APPROVAL-V3', CarbonImmutable::parse('2026-10-02'));
        try {
            app(CreateSuccessorFeePlan::class)->execute($published, $actor, expectedContentFingerprint: $fingerprint);
            $this->fail('A superseded source was accepted from a stale confirmation.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('review the current published version', $exception->errors()['fee_plan'][0]);
        }
        $this->assertSame(FeePlan::StateDraft, $otherDraft->fresh()->state);
        $this->assertSame(3, FeePlan::query()->where('program_id', $plan->program_id)->where('term_id', $plan->term_id)->count());
        $this->assertSame(0, Assessment::query()->whereIn('fee_plan_id', [$plan->id, $successor->id])->count());
    }

    public function test_mounted_successor_confirmation_rejects_a_source_superseded_after_review(): void
    {
        $actor = $this->staff(User::StaffRoleAccounting);
        $draft = $this->draftPlan($actor);
        $published = app(PublishFeePlan::class)->execute($draft, $actor, 'REVIEWED-APPROVAL', CarbonImmutable::parse('2026-10-01'));
        $component = Livewire::actingAs($actor)->test(ViewFeePlan::class, ['record' => $published->id])
            ->mountAction('createSuccessor')->assertMountedActionModalSee('REVIEWED-APPROVAL');
        $this->assertSame($published->contentFingerprint(), $component->get('mountedActions.0.data.reviewed_content_fingerprint'));
        $successor = app(CreateSuccessorFeePlan::class)->execute($published, $actor);
        app(PublishFeePlan::class)->execute($successor, $actor, 'NEWER-APPROVAL', CarbonImmutable::parse('2026-10-02'));

        $component->callMountedAction()->assertHasErrors(['fee_plan'])->assertNotified('Draft was not created');

        $this->assertSame(FeePlan::StateSuperseded, $published->fresh()->state);
        $this->assertSame(2, FeePlan::query()->where('program_id', $published->program_id)->where('term_id', $published->term_id)->count());
        $this->assertSame('NEWER-APPROVAL', $successor->fresh()->authority_reference);
        Livewire::actingAs($actor)->test(ViewFeePlan::class, ['record' => $published->id])->assertActionHidden('createSuccessor');
    }

    public function test_duplicate_charge_and_payment_codes_return_validation_without_changing_a_draft(): void
    {
        $actor = $this->staff(User::StaffRoleAccounting);
        $plan = $this->draftPlan($actor);
        $fingerprint = $plan->contentFingerprint();
        $originalCharges = $plan->charges->map->only(['code', 'label', 'amount'])->all();
        $originalObligations = $plan->obligations->map->only(['code', 'label', 'purpose', 'amount', 'due_at', 'required_for_enrollment'])->all();
        foreach (['charges', 'obligations'] as $key) {
            $charges = $originalCharges;
            $obligations = $originalObligations;
            if ($key === 'charges') {
                $charges = [array_replace($charges[0], ['amount' => '50.00']), array_replace($charges[0], ['code' => ' legacy-charge ', 'amount' => '50.00'])];
            } else {
                $obligations[1]['code'] = ' enrollment-old ';
            }
            foreach ([
                fn () => app(CreateFeePlanDraft::class)->execute($plan->program, $plan->term, $charges, $actor, $obligations),
                fn () => app(UpdateFeePlanDraft::class)->execute($plan, $charges, $obligations, $actor),
            ] as $operation) {
                try {
                    $operation();
                    $this->fail('A duplicate code reached persistence.');
                } catch (ValidationException $exception) {
                    $this->assertStringContainsString('different code', $exception->errors()[$key][0]);
                }
            }
        }
        $this->assertSame($fingerprint, $plan->contentFingerprint());
        $this->assertSame(1, FeePlan::query()->where('program_id', $plan->program_id)->where('term_id', $plan->term_id)->count());
    }

    public function test_payment_dates_must_remain_chronological_for_draft_save_and_publication(): void
    {
        $actor = $this->staff(User::StaffRoleAccounting);
        $plan = $this->draftPlan($actor);
        $charges = $plan->charges->map->only(['code', 'label', 'amount'])->all();
        $obligations = $plan->obligations->map->only(['code', 'label', 'purpose', 'amount', 'due_at', 'required_for_enrollment'])->all();
        $obligations[1]['due_at'] = '2026-09-01 12:00:00';
        $fingerprint = $plan->contentFingerprint();
        foreach ([
            fn () => app(CreateFeePlanDraft::class)->execute($plan->program, $plan->term, $charges, $actor, $obligations),
            fn () => app(UpdateFeePlanDraft::class)->execute($plan, $charges, $obligations, $actor),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Reversed payment dates were accepted.');
            } catch (ValidationException $exception) {
                $this->assertStringContainsString('chronological order', $exception->errors()['obligations'][0]);
            }
        }
        $this->assertSame($fingerprint, $plan->contentFingerprint());
        $plan->obligations()->where('sequence', 2)->firstOrFail()->update(['due_at' => '2026-09-01 12:00:00']);
        try {
            app(PublishFeePlan::class)->execute($plan, $actor, 'APPROVAL-DATE', CarbonImmutable::parse('2026-10-01'));
            $this->fail('An existing draft with reversed dates was published.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('chronological order', $exception->errors()['obligations'][0]);
        }
        $this->assertSame(FeePlan::StateDraft, $plan->fresh()->state);
        $this->assertNull($plan->fresh()->published_at);
        $plan->obligations()->where('sequence', 2)->firstOrFail()->update(['due_at' => '2026-11-01 12:00:00', 'amount' => '59.00']);
        $publisher = app(PublishFeePlan::class);
        $this->assertSame('Payment amounts must add up exactly to the total charges.', $publisher->readiness($plan->fresh()));
        try {
            $publisher->execute($plan, $actor, 'APPROVAL-UNBALANCED', CarbonImmutable::parse('2026-10-01'));
            $this->fail('An unbalanced source was published despite its readiness blocker.');
        } catch (ValidationException $exception) {
            $this->assertSame($publisher->readiness($plan->fresh()), $exception->errors()['fee_plan'][0]);
        }
        $plan->obligations()->where('sequence', 2)->firstOrFail()->update(['amount' => '60.00']);
        $this->assertNull($publisher->readiness($plan->fresh()));
    }

    public function test_legacy_draft_rows_with_missing_recorded_details_are_not_ready_and_cannot_be_published(): void
    {
        $actor = $this->staff(User::StaffRoleAccounting);
        $publisher = app(PublishFeePlan::class);
        foreach (['charges' => ['code', 'label'], 'obligations' => ['code', 'label', 'purpose']] as $relation => $fields) {
            foreach ($fields as $field) {
                $plan = $this->draftPlan($actor);
                $plan->{$relation}()->firstOrFail()->update([$field => '   ']);
                $message = $relation === 'charges' ? 'Each charge needs a recorded code and label.' : 'Each payment needs a recorded code, label and purpose.';
                $this->assertSame($message, $publisher->readiness($plan->fresh()));

                try {
                    $publisher->execute($plan, $actor, 'LEGACY-APPROVAL', CarbonImmutable::parse('2026-10-01'));
                    $this->fail('Publication accepted a missing recorded '.$relation.' '.$field.'.');
                } catch (ValidationException $exception) {
                    $this->assertSame($message, $exception->errors()['fee_plan'][0]);
                }

                $this->assertSame(FeePlan::StateDraft, $plan->fresh()->state);
                $this->assertNull($plan->fresh()->authority_reference);
                $this->assertNull($plan->fresh()->published_at);
            }
        }
    }

    private function draftPlan(User $actor, ?Program $program = null, ?Term $term = null): FeePlan
    {
        return app(CreateFeePlanDraft::class)->execute($program ?? Program::factory()->create(), $term ?? Term::factory()->create(),
            [['code' => 'LEGACY-CHARGE', 'label' => 'Recorded fixed charges', 'category' => 'Recorded category', 'amount' => '100.00']], $actor,
            [['code' => 'ENROLLMENT-OLD', 'label' => 'Enrollment payment', 'purpose' => 'Enrollment', 'amount' => '40.00', 'due_at' => '2026-10-01 12:00:00', 'required_for_enrollment' => true],
                ['code' => 'LATER-OLD', 'label' => 'Later payment', 'purpose' => 'Recorded later purpose', 'amount' => '60.00', 'due_at' => '2026-11-01 12:00:00', 'required_for_enrollment' => false]]);
    }

    /** @return array{Enrollment, FeePlan, Assessment} */
    private function fixedAssessment(): array
    {
        $accounting = $this->staff(User::StaffRoleAccounting);
        $profile = StudentProfile::factory()->create();
        $profile->user->update(['status' => User::StatusActive]);
        $profile->user->assignRole('student');
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $package = TermCalendarPackage::factory()->for($term)->create(['state' => TermCalendarPackage::StateActive, 'activated_at' => now()]);
        TermCalendarWindow::factory()->for($package, 'package')->create([
            'window_type' => TermCalendarWindow::TypeEnrollment,
            'opens_on' => now()->subDay()->toDateString(), 'closes_on' => now()->addWeek()->toDateString(), 'cutoff_at' => '23:59:59',
        ]);
        $entry = CurriculumEntry::factory()->for($profile->curriculumVersion)->create();
        $offering = TermOffering::factory()->for($term)->for($entry)->create(['state' => TermOffering::StateScheduled]);
        $section = Section::factory()->for($offering, 'termOffering')->create(['state' => Section::StateOpen]);
        $timetable = PublishedTimetableVersion::factory()->for($term)->create();
        PublishedTimetableMeeting::factory()->for($timetable, 'timetableVersion')->create(['section_id' => $section->id]);
        $plan = app(CreateFeePlanDraft::class)->execute($profile->program, $term,
            [['code' => 'FIXED', 'label' => 'Synthetic approved fixed total', 'amount' => '5800.00']], $accounting,
            [['code' => 'ENROLLMENT', 'label' => 'Enrollment requirement', 'purpose' => 'Enrollment', 'amount' => '1500.00', 'due_at' => now()->subMinute(), 'required_for_enrollment' => true],
                ['code' => 'LATER', 'label' => 'Later balance', 'purpose' => 'Later', 'amount' => '4300.00', 'due_at' => now()->addMonth(), 'required_for_enrollment' => false]]);
        $plan = app(PublishFeePlan::class)->execute($plan, $accounting, 'SYN-FIXED-INSTITUTIONAL-AUTHORITY', CarbonImmutable::now());
        $case = app(StartRegistrationCase::class)->forContinuingStudent($profile, $term, $profile->user);
        $assessment = $case->termAccount->assessments()->where('state', Assessment::StateActive)->sole();

        return [$case, $plan, $assessment];
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
