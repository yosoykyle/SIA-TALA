<?php

namespace Tests\Feature;

use App\Actions\Enrollment\RecordStudentSection;
use App\Filament\Pages\GradesAndCompletion;
use App\Filament\Resources\StudentProfiles\Pages\ViewStudentProfile;
use App\Filament\Resources\StudentProfiles\StudentProfileResource;
use App\Models\AcademicDecision;
use App\Models\Assessment;
use App\Models\CourseEnrollment;
use App\Models\CourseSpecification;
use App\Models\Enrollment;
use App\Models\ExternalCompetencyRequirement;
use App\Models\GradeOutcomeEvent;
use App\Models\GradeRoster;
use App\Models\GradeRosterRow;
use App\Models\IncCompletionSubmission;
use App\Models\RegistrationProposalVersion;
use App\Models\StudentLifecycleChange;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermCohort;
use App\Models\User;
use Filament\Forms\Components\Select;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StudentSectionMembershipTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(config('database.test_database'), DB::selectOne('SELECT DATABASE() AS name')->name);
    }

    public function test_explicit_membership_is_audited_idempotent_and_does_not_change_subjects(): void
    {
        [$registrar, $enrollment, $section] = $this->fixture();
        $subject = CourseEnrollment::factory()->create(['enrollment_id' => $enrollment->id]);
        $before = $subject->fresh()->getAttributes();
        $proposalId = $enrollment->current_proposal_version_id;
        $this->assertNull($enrollment->student_section_id);
        $action = app(RecordStudentSection::class);
        $assigned = $action->execute($enrollment, $registrar, $section->id, 0);
        $this->assertSame($section->id, $assigned->studentSection->id);
        $this->assertTrue($section->enrollments()->whereKey($enrollment->id)->exists());
        $this->assertSame($before, $subject->fresh()->getAttributes());
        $this->assertSame($proposalId, $assigned->current_proposal_version_id);
        $audit = Activity::query()->where('event', 'student_section_changed')->where('subject_id', $enrollment->id)->sole();
        $this->assertSame($registrar->id, $audit->causer_id);
        $this->assertSame(['id' => null, 'reference' => null], $audit->properties['before']);
        $this->assertSame($section->reference, $audit->properties['after']['reference']);
        $unchanged = $action->execute($assigned, $registrar, $section->id, 1);
        $this->assertSame(1, $unchanged->lock_version);
        $this->assertSame(1, Activity::query()->where('event', 'student_section_changed')->where('subject_id', $enrollment->id)->count());
        $cleared = $action->execute($unchanged, $registrar, null, 1);
        $this->assertNull($cleared->student_section_id);
        $this->assertSame(2, Activity::query()->where('event', 'student_section_changed')->where('subject_id', $enrollment->id)->count());
        $this->assertSame($before, $subject->fresh()->getAttributes());
    }

    #[DataProvider('invalidSources')]
    public function test_invalid_source_or_stale_request_changes_nothing(string $case): void
    {
        [$registrar, $enrollment, $section] = $this->fixture();
        match ($case) {
            'term' => $section->update(['term_id' => Term::factory()->create()->id]),
            'program' => $section->update(['program_id' => TermCohort::factory()->create()->program_id]),
            'state' => $section->update(['state' => 'Draft']),
            'source' => $enrollment->update(['current_proposal_version_id' => null]),
            'unofficial' => $enrollment->update(['canonical_outcome' => Enrollment::OutcomeInProgress]),
            'stale' => $enrollment->update(['lock_version' => 1]),
        };
        try {
            app(RecordStudentSection::class)->execute($enrollment, $registrar, $section->id, 0);
            $this->fail('Invalid membership was accepted.');
        } catch (ValidationException) {
            $this->assertNull($enrollment->fresh()->student_section_id);
            $this->assertSame(0, Activity::query()->where('event', 'student_section_changed')->where('subject_id', $enrollment->id)->count());
        }
    }

    public static function invalidSources(): array
    {
        return array_map(fn (string $case): array => [$case], ['term', 'program', 'state', 'source', 'unofficial', 'stale']);
    }

    #[DataProvider('unauthorizedRoles')]
    public function test_only_active_registrar_can_record_membership(string $role, string $status): void
    {
        [, $enrollment, $section] = $this->fixture();
        $actor = $this->staff($role);
        $actor->update(['status' => $status]);
        $this->expectException(AuthorizationException::class);
        app(RecordStudentSection::class)->execute($enrollment, $actor, $section->id, 0);
    }

    public static function unauthorizedRoles(): array
    {
        return [[User::StaffRoleFaculty, User::StatusActive], [User::StaffRoleAcademicHead, User::StatusActive], [User::StaffRoleAccounting, User::StatusActive], [User::StaffRoleRegistrar, 'disabled'], [User::StaffRoleRegistrar, User::StatusVerificationRequired]];
    }

    public function test_persisted_status_and_role_revocation_override_a_cached_actor(): void
    {
        [$registrar, $enrollment, $section] = $this->fixture();
        $this->assertTrue($registrar->hasRole(User::StaffRoleRegistrar));
        User::query()->whereKey($registrar->id)->update(['status' => 'disabled']);
        try {
            app(RecordStudentSection::class)->execute($enrollment, $registrar, $section->id, 0);
            $this->fail('A disabled cached actor was accepted.');
        } catch (AuthorizationException) {
            $this->assertNull($enrollment->fresh()->student_section_id);
        }
        User::query()->whereKey($registrar->id)->update(['status' => User::StatusActive]);
        User::query()->findOrFail($registrar->id)->removeRole(User::StaffRoleRegistrar);
        $this->expectException(AuthorizationException::class);
        app(RecordStudentSection::class)->execute($enrollment, $registrar, $section->id, 0);
    }

    public function test_enrollment_program_is_historical_and_planning_count_is_not_capacity(): void
    {
        [$registrar, $enrollment, $section] = $this->fixture();
        $section->update(['confirmed_count' => 1]);
        $other = StudentProfile::factory()->create();
        $enrollment->studentProfile->update(['program_id' => $other->program_id, 'curriculum_version_id' => $other->curriculum_version_id]);
        app(RecordStudentSection::class)->execute($enrollment, $registrar, $section->id, 0);
        $second = Enrollment::factory()->create(['term_id' => $enrollment->term_id, 'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled]);
        $proposal = RegistrationProposalVersion::factory()->create(['enrollment_id' => $second->id, 'curriculum_version_id' => $section->curriculum_version_id]);
        $second->update(['current_proposal_version_id' => $proposal->id]);
        app(RecordStudentSection::class)->execute($second, $registrar, $section->id, 0);
        $this->assertSame(2, $section->enrollments()->count());
        $this->assertSame(1, $section->fresh()->confirmed_count);
    }

    public function test_shared_filters_require_the_same_official_enrollment_and_recover_from_empty_results(): void
    {
        [$registrar, $first, $section] = $this->fixture();
        app(RecordStudentSection::class)->execute($first, $registrar, $section->id, 0);
        $otherTerm = Term::factory()->create();
        Enrollment::factory()->create(['student_profile_id' => $first->student_profile_id, 'term_id' => $otherTerm->id, 'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled]);
        $other = StudentProfile::factory()->create();
        $page = Livewire::actingAs($registrar)->withQueryParams(['viewTab' => 'academic-progress'])->test(GradesAndCompletion::class);
        $page->filterTable('enrollment_scope', ['term_id' => $first->term_id, 'student_section_id' => $section->id])
            ->assertCanSeeTableRecords([$first->studentProfile])->assertCanNotSeeTableRecords([$other]);
        $url = $page->instance()->getTable()->getRecordUrl($first->studentProfile);
        parse_str(parse_url($url, PHP_URL_QUERY), $parameters);
        $this->assertSame((string) $section->id, $parameters['queue']['filters']['enrollment_scope']['student_section_id']);
        $parameters['queue']['redirect'] = 'https://example.test/unrelated';
        $record = Livewire::actingAs($registrar)->withQueryParams($parameters)->test(ViewStudentProfile::class, ['record' => $first->student_profile_id]);
        $this->assertArrayNotHasKey('redirect', $record->get('queue'));
        $return = GradesAndCompletion::getUrl(['viewTab' => 'academic-progress', ...StudentProfileResource::academicQueueParameters($parameters['queue'])]);
        $this->assertArrayHasKey($return, $record->instance()->getBreadcrumbs());
        $page->filterTable('enrollment_scope', ['term_id' => $otherTerm->id, 'student_section_id' => $section->id])
            ->assertCanNotSeeTableRecords([$first->studentProfile])->assertSee('No students match');
        $page->removeTableFilters()->assertCanSeeTableRecords([$first->studentProfile, $other]);
    }

    public function test_entry_year_is_derived_and_unassigned_students_remain_visible(): void
    {
        [$registrar, $enrollment] = $this->fixture();
        $profile = $enrollment->studentProfile;
        $profile->update(['entry_term_id' => $enrollment->term_id]);
        $unknown = StudentProfile::factory()->create(['entry_term_id' => null]);
        Livewire::actingAs($registrar)->withQueryParams(['viewTab' => 'academic-progress'])->test(GradesAndCompletion::class)
            ->assertCanSeeTableRecords([$profile, $unknown])->filterTable('entry_year', $enrollment->term->academic_year_id)
            ->assertCanSeeTableRecords([$profile])->assertCanNotSeeTableRecords([$unknown]);
        $this->assertNull($enrollment->fresh()->student_section_id);
    }

    public function test_section_search_can_reach_choices_beyond_the_bounded_initial_list(): void
    {
        [$registrar, $enrollment, $section] = $this->fixture();
        for ($index = 0; $index < 51; $index++) {
            TermCohort::factory()->create(['term_id' => $enrollment->term_id, 'program_id' => $section->program_id, 'curriculum_version_id' => $section->curriculum_version_id, 'reference' => 'SCOPE-'.str_pad((string) $index, 3, '0', STR_PAD_LEFT)]);
        }
        $target = TermCohort::query()->where('reference', 'SCOPE-050')->sole();
        $page = Livewire::actingAs($registrar)->withQueryParams(['viewTab' => 'academic-progress'])->test(GradesAndCompletion::class);
        $field = collect($page->instance()->getTableFiltersForm()->getFlatComponents())
            ->first(fn ($component): bool => $component instanceof Select && $component->getName() === 'student_section_id');
        $this->assertInstanceOf(Select::class, $field);
        $this->assertCount(50, $field->getOptions());
        $this->assertArrayNotHasKey($target->id, $field->getOptions());
        $this->assertArrayHasKey($target->id, $field->getSearchResults('SCOPE-050'));
    }

    public function test_native_action_saves_for_registrar_and_is_hidden_for_head(): void
    {
        [$registrar, $enrollment, $section] = $this->fixture();
        Livewire::actingAs($this->staff(User::StaffRoleAcademicHead))->test(ViewStudentProfile::class, ['record' => $enrollment->student_profile_id])->assertActionHidden('recordStudentSection');
        [, $otherEnrollment] = $this->fixture();
        Livewire::actingAs($registrar)->test(ViewStudentProfile::class, ['record' => $enrollment->student_profile_id])
            ->callAction('recordStudentSection', ['enrollment_id' => $otherEnrollment->id, 'student_section_id' => $section->id, 'expected_lock_version' => 0])
            ->assertHasActionErrors(['enrollment_id']);
        $this->assertNull($otherEnrollment->fresh()->student_section_id);
        Livewire::actingAs($registrar)->test(ViewStudentProfile::class, ['record' => $enrollment->student_profile_id])
            ->callAction('recordStudentSection', ['enrollment_id' => $enrollment->id, 'student_section_id' => $section->id, 'expected_lock_version' => 0])
            ->assertHasNoActionErrors();
        $this->assertSame($section->id, $enrollment->fresh()->student_section_id);
    }

    public function test_academic_layout_uses_persistent_page_context_after_an_update(): void
    {
        [$registrar, $enrollment] = $this->fixture();
        $page = Livewire::actingAs($registrar)->test(ViewStudentProfile::class, ['record' => $enrollment->student_profile_id])
            ->set('viewTab', 'academic-progress')->set('recordTab', 'academic');
        $sections = $page->instance()->getSchema('infolist')->getComponents();
        $this->assertSame('Released grades', array_values($sections)[0]->getHeading());
    }

    public function test_profile_views_keep_context_and_expose_details_without_collapsed_sections(): void
    {
        [$registrar, $enrollment] = $this->fixture();
        $page = Livewire::actingAs($registrar)->withQueryParams(['viewTab' => 'academic-progress', 'queue' => ['page' => 2]])
            ->test(ViewStudentProfile::class, ['record' => $enrollment->student_profile_id])
            ->assertSee('Current enrollment')->assertSee('Academic guidance')->assertSee('Payment clearance is separate.')
            ->assertDontSee('Cumulative GWA')->assertDontSee('No released grades yet')->assertDontSee('Record external assessment result');
        $this->assertEmpty($page->instance()->getCachedHeaderActions());
        $page->call('selectRecordTab', 'academic')->assertSee('No released grades yet');
        $page->call('selectRecordTab', 'enrollment')->assertSee($enrollment->term->label)
            ->assertDontSee('wire:model.live="enrollment"', false)->assertDontSee('Current Enrollment Context');
        foreach ($page->instance()->getSchema('infolist')->getComponents() as $section) {
            $this->assertFalse($section->isCollapsed());
        }
        $page->call('selectRecordTab', 'history')->assertSee('No financial or student status history yet');
        $page->call('selectRecordTab', 'requirements')->assertSee('Requirements & follow-up', false)
            ->assertSee('No document checklist recorded')->assertSee('No restrictions recorded.')->assertDontSee('Record Hold');
        $this->assertEmpty($page->instance()->getSchema('requirements')->getComponents());
        $this->assertCount(2, $page->instance()->getCachedRelationManagers());
        $page->call('selectRecordTab', 'unknown')->assertSet('recordTab', 'overview')
            ->assertSee('Current enrollment')->assertSet('queue.page', 2);
    }

    public function test_enrollment_selector_keeps_each_term_once_and_displays_the_selected_context(): void
    {
        [$registrar, $enrollment] = $this->fixture();
        $enrollment->term->update(['state' => Term::StateActive]);
        $page = Livewire::actingAs($registrar)->withQueryParams(['viewTab' => 'academic-progress', 'recordTab' => 'enrollment'])
            ->test(ViewStudentProfile::class, ['record' => $enrollment->student_profile_id])
            ->assertSee($enrollment->term->label)->assertDontSee('wire:model.live="enrollment"', false)->assertDontSee('Past enrollments');
        $this->assertSame($enrollment->id, $page->instance()->enrollmentProfile['selected']->id);
        $this->assertCount(1, $page->instance()->enrollmentProfile['termOptions']);
        $previous = Enrollment::factory()->create(['student_profile_id' => $enrollment->student_profile_id]);
        $previous->term->update(['state' => Term::StateClosed]);
        $page->call('$refresh')->assertSee('Enrolled term')->assertSee('wire:model.live="enrollment"', false);
        $this->assertCount(2, $page->instance()->enrollmentProfile['termOptions']);
        $this->assertSame($enrollment->id, $page->instance()->enrollmentProfile['selected']->id);
        $page->set('enrollment', $previous->id)->assertSee($previous->term->label);
        $this->assertSame($previous->id, $page->instance()->enrollmentProfile['context']['enrollment_id']);
    }

    public function test_assessment_action_requires_an_applicable_active_curriculum_and_registrar(): void
    {
        [$registrar, $enrollment] = $this->fixture();
        $student = $enrollment->studentProfile;
        $student->curriculumVersion->update(['state' => 'ACTIVE']);
        ExternalCompetencyRequirement::factory()->create(['state' => 'ACTIVE']);
        $page = Livewire::actingAs($registrar)->withQueryParams(['viewTab' => 'academic-progress', 'recordTab' => 'academic'])
            ->test(ViewStudentProfile::class, ['record' => $student->id])->assertDontSee('Record external assessment result');
        ExternalCompetencyRequirement::factory()->create(['state' => 'ACTIVE', 'curriculum_version_id' => $student->curriculum_version_id]);
        $page->call('$refresh')->assertSee('Record external assessment result');
        Livewire::actingAs($this->staff(User::StaffRoleAcademicHead))->withQueryParams(['viewTab' => 'academic-progress', 'recordTab' => 'academic'])
            ->test(ViewStudentProfile::class, ['record' => $student->id])->assertDontSee('Record external assessment result')
            ->assertActionHidden('recordExternalCompetency')->assertActionHidden('recordAcademicDecision');
    }

    public function test_official_enrollment_does_not_invent_a_payment_handoff_from_legacy_status(): void
    {
        [$registrar, $enrollment] = $this->fixture();
        $enrollment->term->update(['state' => Term::StateActive]);
        $enrollment->update(['status' => 'PendingPayment']);
        $page = Livewire::actingAs($registrar)->withQueryParams(['viewTab' => 'academic-progress'])
            ->test(ViewStudentProfile::class, ['record' => $enrollment->student_profile_id])
            ->assertSee('Officially Enrolled')->assertDontSee('Confirm payment for the current enrollment.');
        $this->assertNull($page->instance()->academicProfile['handoff']);
        $enrollment->update(['canonical_outcome' => Enrollment::OutcomeInProgress]);
        $page->call('$refresh');
        $profile = $page->instance()->academicProfile;
        $this->assertSame($profile['context']['responsible_office'], $profile['handoff']['currentOwner']);
        $this->assertSame($profile['context']['next_action'], $profile['handoff']['currentTask']);
    }

    public function test_directory_sections_belong_to_the_selected_term_and_actions_belong_to_a_student(): void
    {
        [$registrar, $enrollment, $section] = $this->fixture();
        app(RecordStudentSection::class)->execute($enrollment, $registrar, $section->id, 0);
        $page = Livewire::actingAs($registrar)->withQueryParams(['viewTab' => 'academic-progress'])->test(GradesAndCompletion::class)
            ->assertActionDoesNotExist('recordAcademicDecision');
        $this->assertFalse($page->instance()->getTable()->getColumn('student_section')->isVisible());
        $page->filterTable('enrollment_scope', ['term_id' => $enrollment->term_id])
            ->assertSee($section->reference)->assertSee('Sections shown for');
        $record = $page->instance()->getTableRecords()->firstWhere('id', $enrollment->student_profile_id);
        $this->assertSame($section->id, $record->enrollments->sole()->student_section_id);
        $page->removeTableFilters()->assertDontSee('Sections shown for');
    }

    public function test_contextual_decision_cannot_be_redirected_to_another_student(): void
    {
        [$registrar, $enrollment] = $this->fixture();
        $other = StudentProfile::factory()->create();
        Livewire::actingAs($registrar)->test(ViewStudentProfile::class, ['record' => $enrollment->student_profile_id])
            ->callAction('recordAcademicDecision', [
                'student_profile_id' => $other->id,
                'effect' => AcademicDecision::EffectAdvisingRequired,
                'authority_reference' => 'REG-CONTEXT', 'authority_date' => today()->toDateString(),
                'effective_from' => today()->toDateString(), 'reason' => 'Individual advising required.',
            ])->assertHasNoActionErrors();
        $this->assertSame($enrollment->student_profile_id, AcademicDecision::query()->sole()->student_profile_id);
    }

    public function test_academic_decision_dialog_persists_null_authority_metadata_and_respects_explicit_scope(): void
    {
        [$registrar, $enrollment] = $this->fixture();
        Livewire::actingAs($registrar)->test(ViewStudentProfile::class, ['record' => $enrollment->student_profile_id])
            ->callAction('recordAcademicDecision', [
                'term_id' => 'all',
                'effect' => AcademicDecision::EffectAllowed,
                'effective_from' => today()->toDateString(),
                'reason' => 'Standard enrollment allowed across all terms.',
                'authority_reference' => null,
                'authority_date' => null,
            ])->assertHasNoActionErrors();

        $decision = AcademicDecision::query()->where('student_profile_id', $enrollment->student_profile_id)->latest('id')->firstOrFail();
        $this->assertNull($decision->authority_reference);
        $this->assertNull($decision->authority_date);
        $this->assertNull($decision->term_id);
        $this->assertSame(AcademicDecision::EffectAllowed, $decision->effect);
        $this->assertSame('Standard enrollment allowed across all terms.', $decision->reason);
        $this->assertSame($registrar->id, $decision->recorded_by);
    }

    public function test_academic_decision_dialog_retains_supplied_authority_metadata(): void
    {
        [$registrar, $enrollment] = $this->fixture();
        Livewire::actingAs($registrar)->test(ViewStudentProfile::class, ['record' => $enrollment->student_profile_id])
            ->callAction('recordAcademicDecision', [
                'term_id' => (string) $enrollment->term_id,
                'effect' => AcademicDecision::EffectBlocked,
                'effective_from' => today()->toDateString(),
                'effective_until' => today()->addMonths(3)->toDateString(),
                'reason' => 'Academic standing holds enrollment for the specified term.',
                'authority_reference' => 'REG-REF-78',
                'authority_date' => today()->toDateString(),
            ])->assertHasNoActionErrors();

        $decision = AcademicDecision::query()->where('student_profile_id', $enrollment->student_profile_id)->latest('id')->firstOrFail();
        $this->assertSame('REG-REF-78', $decision->authority_reference);
        $this->assertSame(today()->toDateString(), $decision->authority_date?->toDateString());
        $this->assertSame($enrollment->term_id, $decision->term_id);
        $this->assertSame(AcademicDecision::EffectBlocked, $decision->effect);
    }

    public function test_academic_decision_dialog_requires_scope_and_reason(): void
    {
        [$registrar, $enrollment] = $this->fixture();
        Livewire::actingAs($registrar)->test(ViewStudentProfile::class, ['record' => $enrollment->student_profile_id])
            ->callAction('recordAcademicDecision', [
                'term_id' => '',
                'effect' => AcademicDecision::EffectAdvisingRequired,
                'reason' => '',
            ])->assertHasActionErrors(['term_id' => ['required'], 'reason' => ['required']]);
    }

    public function test_programs_with_the_same_name_keep_distinct_code_labels_and_filters(): void
    {
        [$registrar, $enrollment] = $this->fixture();
        $first = $enrollment->studentProfile;
        $other = StudentProfile::factory()->create();
        $other->program->update(['name' => $first->program->name]);
        $page = Livewire::actingAs($registrar)->withQueryParams(['viewTab' => 'academic-progress'])->test(GradesAndCompletion::class)
            ->assertSee($first->program->code)->assertSee($other->program->code);
        $this->assertNull($page->instance()->getTable()->getGrouping());
        $this->assertSame('All programs', $page->instance()->studentDirectoryHeading());
        $this->assertTrue($page->instance()->getTable()->getColumn('program.code')->isVisible());
        $page->filterTable('program', $first->program_id)
            ->assertCanSeeTableRecords([$first])->assertCanNotSeeTableRecords([$other]);
        $this->assertSame(StudentProfileResource::academicProgramLabel($first->program), $page->instance()->studentDirectoryHeading());
        $this->assertFalse($page->instance()->getTable()->getColumn('program.code')->isVisible());
    }

    public function test_all_programs_is_bounded_and_keeps_the_record_return_page(): void
    {
        [$registrar] = $this->fixture();
        StudentProfile::factory()->count(12)->create();
        $page = Livewire::actingAs($registrar)->withQueryParams(['viewTab' => 'academic-progress'])->test(GradesAndCompletion::class);
        $this->assertSame(13, $page->instance()->getTableRecords()->total());
        $this->assertCount(10, $page->instance()->getTableRecords());
        $page->call('gotoPage', 2);
        $this->assertCount(3, $page->instance()->getTableRecords());
        $url = $page->instance()->getTable()->getRecordUrl($page->instance()->getTableRecords()->first());
        parse_str(parse_url($url, PHP_URL_QUERY), $parameters);
        $this->assertSame('2', $parameters['queue']['page']);
    }

    public function test_profile_subjects_are_term_scoped_searchable_paginated_and_keep_cumulative_results(): void
    {
        [$registrar, $enrollment] = $this->fixture();
        $enrollment->update(['officially_enrolled_at' => now()]);
        $enrollment->term->update(['starts_on' => '2026-06-01']);
        $courses = collect();
        for ($index = 0; $index < 11; $index++) {
            $courses->push($this->releasedCourse($enrollment, 'SUBJECT-'.$index, '1.50'));
        }
        $past = Enrollment::factory()->create(['student_profile_id' => $enrollment->student_profile_id,
            'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled, 'officially_enrolled_at' => now()]);
        $past->term->update(['starts_on' => '2025-06-01']);
        $pastCourse = $this->releasedCourse($past, 'EARLIER-SUBJECT', '2.50');
        $empty = Enrollment::factory()->create(['student_profile_id' => $enrollment->student_profile_id]);

        $page = Livewire::actingAs($registrar)->withQueryParams(['viewTab' => 'academic-progress',
            'recordTab' => 'academic', 'academicTerm' => $enrollment->term_id])
            ->test(ViewStudentProfile::class, ['record' => $enrollment->student_profile_id]);
        $profile = $page->instance()->academicProfile;
        $this->assertSame(11, $profile['results']->total());
        $this->assertCount(10, $profile['results']);
        $this->assertSame('1.50', $profile['termAverage']['value']);
        $this->assertSame(12, $profile['cumulative']['included_attempts']);
        $this->assertArrayHasKey($empty->term_id, $profile['termOptions']);
        $page->call('setAcademicPage', 2);
        $this->assertCount(1, $page->instance()->academicProfile['results']);
        $this->assertSame($courses->last()->id, $page->instance()->academicProfile['selectedResult']['course_enrollment']->id);
        $page->set('academicSearch', 'SUBJECT-10')->assertSet('academicPage', 1);
        $this->assertSame(1, $page->instance()->academicProfile['results']->total());
        $page->set('academicSearch', '')->set('academicTerm', $past->term_id);
        $this->assertSame($pastCourse->id, $page->instance()->academicProfile['selectedResult']['course_enrollment']->id);
        $this->assertSame('2.50', $page->instance()->academicProfile['termAverage']['value']);
        $this->assertSame(12, $page->instance()->academicProfile['cumulative']['included_attempts']);
        $page->set('academicTerm', $empty->term_id);
        $this->assertSame(0, $page->instance()->academicProfile['results']->total());
        $this->assertNull($page->instance()->academicProfile['selectedResult']);
    }

    public function test_profile_selection_never_resolves_another_students_or_another_terms_result(): void
    {
        [$registrar, $enrollment] = $this->fixture();
        $enrollment->update(['officially_enrolled_at' => now()]);
        $visible = $this->releasedCourse($enrollment, 'VISIBLE');
        $past = Enrollment::factory()->create(['student_profile_id' => $enrollment->student_profile_id, 'officially_enrolled_at' => now()]);
        $wrongTerm = $this->releasedCourse($past, 'WRONG-TERM');
        $other = Enrollment::factory()->create(['officially_enrolled_at' => now()]);
        $wrongStudent = $this->releasedCourse($other, 'WRONG-STUDENT');

        $page = Livewire::actingAs($registrar)->withQueryParams(['viewTab' => 'academic-progress',
            'recordTab' => 'academic', 'academicTerm' => $enrollment->term_id, 'academicResult' => $wrongStudent->id])
            ->test(ViewStudentProfile::class, ['record' => $enrollment->student_profile_id]);
        $this->assertNull($page->instance()->academicProfile['selectedResult']);
        $page->call('selectAcademicResult', $wrongTerm->id);
        $this->assertNull($page->instance()->academicProfile['selectedResult']);
        $page->call('selectAcademicResult', $visible->id);
        $this->assertSame($visible->id, $page->instance()->academicProfile['selectedResult']['course_enrollment']->id);
        $page->set('academicSearch', 'no matching subject')->call('selectAcademicResult', $visible->id);
        $this->assertNull($page->instance()->academicProfile['selectedResult']);
        $page->set('academicSearch', '');
        $page->set('academicTerm', $other->term_id);
        $this->assertNotSame($other->term_id, $page->instance()->academicProfile['selectedTerm']?->id);
        $this->assertArrayNotHasKey($other->term_id, $page->instance()->academicProfile['termOptions']);
    }

    public function test_missing_curriculum_is_unavailable_instead_of_zero_requirements(): void
    {
        [$registrar, $enrollment] = $this->fixture();
        $page = Livewire::actingAs($registrar)->withQueryParams(['viewTab' => 'academic-progress', 'recordTab' => 'academic'])
            ->test(ViewStudentProfile::class, ['record' => $enrollment->student_profile_id])
            ->assertSee('Curriculum progress unavailable')->assertDontSee('0.00 units completed or credited across terms.');
        $page->instance()->getRecord()->setRelation('curriculumVersion', null);
        unset($page->instance()->academicProfile);

        $this->assertNull($page->instance()->academicProfile['curriculum']);
        $this->assertFalse($page->instance()->requirementsProfile['hasChecklist']);
        $this->assertFalse($page->instance()->requirementsProfile['hasHolds']);
        $this->assertEmpty($page->instance()->getSchema('requirements')->getComponents());
    }

    public function test_profile_student_section_is_bound_to_the_displayed_enrollment(): void
    {
        [$registrar, $enrollment, $section] = $this->fixture();
        $past = Enrollment::factory()->create(['student_profile_id' => $enrollment->student_profile_id,
            'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled]);
        $past->term->update(['state' => Term::StateClosed, 'starts_on' => '2024-06-01']);
        $enrollment->term->update(['state' => Term::StateActive, 'starts_on' => '2026-06-01']);
        $other = Enrollment::factory()->create();
        $page = Livewire::actingAs($registrar)->withQueryParams(['viewTab' => 'academic-progress', 'recordTab' => 'enrollment', 'enrollment' => $enrollment->id])
            ->test(ViewStudentProfile::class, ['record' => $enrollment->student_profile_id]);

        $page->callAction('recordStudentSection', ['enrollment_id' => $past->id, 'student_section_id' => null, 'expected_lock_version' => 0])
            ->assertHasErrors(['enrollment_id']);
        $this->assertNull($past->fresh()->student_section_id);
        $page->call('unmountAction');
        $page->callAction('recordStudentSection', ['enrollment_id' => $enrollment->id, 'student_section_id' => $section->id, 'expected_lock_version' => 0])
            ->assertHasNoActionErrors();
        $this->assertSame($section->id, $enrollment->fresh()->student_section_id);
        $page->set('enrollment', $past->id);
        $this->assertSame($past->id, $page->instance()->enrollmentProfile['selected']->id);
        $page->set('enrollment', $other->id);
        $this->assertSame($enrollment->id, $page->instance()->enrollmentProfile['selected']->id);
        $this->assertArrayNotHasKey($other->id, $page->instance()->enrollmentProfile['termOptions']);
    }

    public function test_profile_history_merges_sources_in_date_order_with_exact_filtered_pagination(): void
    {
        [$registrar, $enrollment] = $this->fixture();
        $past = Enrollment::factory()->create(['student_profile_id' => $enrollment->student_profile_id]);
        $assessment = Assessment::factory()->create(['enrollment_id' => $enrollment->id, 'created_at' => '2026-07-15 10:00:00']);
        StudentLifecycleChange::factory()->count(10)->create(['student_profile_id' => $enrollment->student_profile_id,
            'term_id' => $enrollment->term_id, 'enrollment_id' => $enrollment->id, 'effective_on' => '2026-07-01']);
        $latest = StudentLifecycleChange::factory()->create(['student_profile_id' => $enrollment->student_profile_id,
            'term_id' => $past->term_id, 'enrollment_id' => $past->id, 'effective_on' => '2026-08-01']);
        StudentLifecycleChange::factory()->create(['effective_on' => '2026-09-01']);
        $counts = [Assessment::count(), StudentLifecycleChange::count()];

        $page = Livewire::actingAs($registrar)->withQueryParams(['viewTab' => 'academic-progress', 'recordTab' => 'history'])
            ->test(ViewStudentProfile::class, ['record' => $enrollment->student_profile_id]);
        $history = $page->instance()->historyProfile['entries'];
        $this->assertSame(12, $history->total());
        $this->assertCount(10, $history);
        $this->assertSame($latest->id, $history->first()['id']);
        $this->assertSame('Effective date', $history->first()['dateLabel']);
        $this->assertSame($assessment->id, $history->getCollection()->get(1)['id']);
        $this->assertSame('Assessment created', $history->getCollection()->get(1)['dateLabel']);
        $page->call('setHistoryPage', 2);
        $this->assertCount(2, $page->instance()->historyProfile['entries']);
        $page->set('historyType', 'assessment')->assertSet('historyPage', 1);
        $this->assertSame(1, $page->instance()->historyProfile['entries']->total());
        $page->set('historyType', '')->set('historyTerm', $past->term_id);
        $this->assertSame(1, $page->instance()->historyProfile['entries']->total());
        $page->set('historyType', 'unrecognized');
        $this->assertSame(0, $page->instance()->historyProfile['entries']->total());
        $this->assertSame($counts, [Assessment::count(), StudentLifecycleChange::count()]);
        $this->assertEmpty($page->instance()->getCachedHeaderActions());
    }

    #[DataProvider('profileIncFollowUpStates')]
    public function test_profile_inc_follow_up_uses_the_current_completion_source(string $state, string $owner, string $status, bool $canRelease): void
    {
        [$registrar, $enrollment] = $this->fixture();
        $enrollment->update(['officially_enrolled_at' => now()]);
        $course = $this->releasedCourse($enrollment, 'INC-SUBJECT');
        $row = $course->gradeRosterRow;
        $row->update(['current_outcome_code' => 'INC']);
        $event = $row->outcomeEvents()->sole();
        $deadline = $state === 'overdue' ? today('Asia/Manila')->subDay() : today('Asia/Manila')->addDays(5);
        $event->update(['result_code' => 'INC', 'new_value' => null, 'new_category' => 'Incomplete',
            'deadline' => $deadline, 'inc_completion_note' => 'Complete the remaining laboratory work.']);
        if (in_array($state, ['submitted', 'stale'], true)) {
            IncCompletionSubmission::query()->create(['grade_outcome_event_id' => $event->id,
                'proposed_result' => '2.00', 'completion_note' => 'Laboratory work completed.',
                'state' => IncCompletionSubmission::StateSubmitted, 'controlling_result_event_id' => $event->id,
                'controlling_deadline' => $state === 'stale' ? $deadline->copy()->subDay() : $deadline,
                'submitted_by' => $registrar->id, 'submitted_at' => now()]);
        }

        $page = Livewire::actingAs($registrar)->withQueryParams(['viewTab' => 'academic-progress', 'recordTab' => 'academic',
            'academicTerm' => $enrollment->term_id])->test(ViewStudentProfile::class, ['record' => $enrollment->student_profile_id]);
        $followUp = $page->instance()->academicProfile['selectedResult']['followUp'];

        $this->assertSame($owner, $followUp['owner']);
        $this->assertSame($status, $followUp['completion_status']);
        $this->assertSame($canRelease, $followUp['can_release']);
        $page->assertSee($state === 'overdue' ? 'Completion deadline passed' : $status);
    }

    public static function profileIncFollowUpStates(): array
    {
        return [
            'pending Faculty work' => ['pending', 'Faculty', 'Awaiting Faculty completion', false],
            'submitted for Registrar review' => ['submitted', 'Registrar', 'Ready for Registrar review', true],
            'stale submitted source' => ['stale', 'Registrar', 'Submitted completion is stale', false],
            'deadline elapsed' => ['overdue', 'Registrar', 'Awaiting Faculty completion', false],
        ];
    }

    private function releasedCourse(Enrollment $enrollment, string $code, string $result = '1.50'): CourseEnrollment
    {
        $course = CourseEnrollment::factory()->recycle([$enrollment->studentProfile->curriculumVersion, $enrollment->term])
            ->create(['enrollment_id' => $enrollment->id, 'published_timetable_version_id' => null]);
        $specification = $course->termOffering->curriculumEntry->courseSpecification;
        $specification->update(['academic_classification' => CourseSpecification::AcademicClassificationOrdinary]);
        $specification->course->update(['code' => $code]);
        $roster = GradeRoster::factory()->create(['state' => GradeRoster::StateReleased,
            'term_offering_id' => $course->term_offering_id, 'section_id' => $course->section_id]);
        $row = GradeRosterRow::factory()->create(['course_enrollment_id' => $course->id, 'grade_roster_id' => $roster->id,
            'current_outcome_code' => $result, 'released_at' => now()]);
        GradeOutcomeEvent::factory()->create(['grade_roster_row_id' => $row->id, 'result_code' => $result,
            'new_value' => $result, 'released_at' => now(), 'source_term_ends_on' => $enrollment->term->ends_on]);

        return $course;
    }

    /** @return array{User, Enrollment, TermCohort} */
    private function fixture(): array
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $enrollment = Enrollment::factory()->create(['canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled]);
        $profile = $enrollment->studentProfile;
        $proposal = RegistrationProposalVersion::factory()->create(['enrollment_id' => $enrollment->id, 'curriculum_version_id' => $profile->curriculum_version_id]);
        $enrollment->update(['current_proposal_version_id' => $proposal->id]);
        $section = TermCohort::factory()->create(['term_id' => $enrollment->term_id, 'program_id' => $profile->program_id, 'curriculum_version_id' => $profile->curriculum_version_id]);

        return [$registrar, $enrollment, $section];
    }

    private function staff(string $role): User
    {
        Role::findOrCreate($role, 'web');
        $actor = User::factory()->create(['status' => User::StatusActive]);
        $actor->assignRole($role);

        return $actor;
    }
}
