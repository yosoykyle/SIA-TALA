<?php

namespace Tests\Feature;

use App\Actions\Authentication\WorkspaceContextResolver;
use App\Actions\Scheduling\ConfirmClassOffering;
use App\Actions\Scheduling\GenerateSchedulingDemand;
use App\Actions\Scheduling\PrepareClassOffering;
use App\Actions\Scheduling\RecordTermCohort;
use App\Actions\Scheduling\SectionDeliveryGroupService;
use App\Actions\Scheduling\SectionPlanningService;
use App\Filament\Pages\TermClasses;
use App\Filament\Pages\TermPlanningWorkbench;
use App\Filament\Resources\Sections\Pages\EditSection;
use App\Filament\Resources\Sections\RelationManagers\DeliveryGroupsRelationManager;
use App\Filament\Resources\Sections\SectionResource;
use App\Models\CourseComponent;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\PublishedTimetableMeeting;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCohort;
use App\Models\TermOffering;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class Issue58ClassPreparationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_source_section_can_be_prepared_confirmed_and_generate_existing_delivery_group_demands(): void
    {
        $actor = $this->registrar();
        [$term, $package, $curriculum, $specification, $section] = $this->sources();
        $this->freezeTime();
        $cohort = app(RecordTermCohort::class)->execute($term, $actor, [
            'curriculum_version_id' => $curriculum->id, 'reference' => 'CONFIRMED-A', 'confirmed_count' => 20,
            'program_id' => 999999, 'term_id' => 999999, 'forecast_count' => 999, 'confirmed_by' => 999999,
        ]);
        $this->assertSame((int) $curriculum->program_id, $cohort->program_id);
        $this->assertSame($term->id, $cohort->term_id);
        $this->assertSame($actor->id, $cohort->confirmed_by);
        $this->assertSame(now()->toDateTimeString(), $cohort->confirmed_at->toDateTimeString());
        $this->assertNull($cohort->forecast_count);

        $prepared = app(PrepareClassOffering::class)->execute($term, $section, $actor, $this->classFacts());
        $this->assertSame($package->id, $prepared->term_calendar_package_id);
        $this->assertSame($specification->id, $prepared->course_specification_id);
        $this->assertNull($prepared->confirmed_at);
        $confirmed = app(ConfirmClassOffering::class)->execute($prepared, $actor, [$cohort->id => 20]);
        $this->assertSame($actor->id, $confirmed->confirmed_by);
        $this->assertSame(20, $confirmed->cohorts->sole()->pivot->expected_count);
        $this->assertSame(0, $confirmed->deliveryGroups()->count());

        app(SectionDeliveryGroupService::class)->save($confirmed, [
            'name' => 'Confirmed delivery group', 'expected_count' => 20,
            'modality' => TermOffering::ModalityFaceToFace, 'state' => SectionDeliveryGroup::StateReady,
        ], actor: $actor);
        $summary = app(GenerateSchedulingDemand::class)->forTerm($actor, $term);
        $this->assertSame(1, $summary['created']);
        $this->assertDatabaseHas('scheduling_demands', ['section_delivery_group_id' => $confirmed->deliveryGroups()->sole()->id, 'term_offering_id' => $section->term_offering_id]);
    }

    public function test_native_actions_prepare_the_selected_term_and_record_a_cohort(): void
    {
        $actor = $this->registrar();
        [$term, $package, $curriculum, $specification, $section] = $this->sources();
        Livewire::withQueryParams(['termId' => $term->id, 'viewTab' => 'classes'])->test(TermClasses::class)
            ->callAction('recordCohort', ['curriculum_version_id' => $curriculum->id, 'reference' => 'MODAL-COHORT', 'confirmed_count' => 20])
            ->assertHasNoActionErrors()
            ->callTableAction('prepareClassOffering', $section, ['section_id' => $section->id, ...$this->classFacts()])
            ->assertHasNoActionErrors();
        $this->assertDatabaseHas('term_cohorts', ['term_id' => $term->id, 'reference' => 'MODAL-COHORT', 'confirmed_by' => $actor->id]);
        $this->assertDatabaseHas('sections', ['id' => $section->id, 'term_calendar_package_id' => $package->id, 'course_specification_id' => $specification->id]);
    }

    #[DataProvider('invalidPreparation')]
    public function test_preparation_rejects_invalid_source_facts_and_preserves_source(string $case, string $field): void
    {
        $actor = $this->registrar();
        [$term, $package, $curriculum, $specification, $section] = $this->sources();
        $data = $this->classFacts();
        match ($case) {
            'foreign-term' => $term = Term::factory()->create(),
            'no-active-calendar' => $package->update(['state' => TermCalendarPackage::StateDraft]),
            'inactive-course' => $specification->update(['state' => CourseSpecification::StateDraft]),
            'additional-authority' => $data['source'] = Section::SourceAdditional,
            'unsupported-mode' => $data['delivery_mode'] = 'UNSUPPORTED',
            'duplicate-reference' => Section::factory()->create(['term_offering_id' => $section->term_offering_id, 'class_reference' => $data['class_reference']]),
            'confirmed' => $section->update(['confirmed_at' => now(), 'confirmed_by' => $actor->id]),
            'published' => PublishedTimetableMeeting::factory()->create(['section_id' => $section->id]),
            'closed-term' => $term->update(['state' => Term::StateClosed]),
            'cancelled-section' => $section->update(['state' => Section::StateCancelled]),
        };
        try {
            app(PrepareClassOffering::class)->execute($term, $section, $actor, $data);
            $this->fail('Invalid preparation was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
        $this->assertNull($section->refresh()->term_calendar_package_id);
        $this->assertNull($section->class_reference);
    }

    public function test_additional_authority_is_recorded_and_class_reference_is_unique_only_within_term(): void
    {
        $actor = $this->registrar();
        [$term, , , , $section] = $this->sources();
        Section::factory()->create(['class_reference' => 'PREPARED-A']);
        $prepared = app(PrepareClassOffering::class)->execute($term, $section, $actor, [
            ...$this->classFacts(), 'source' => Section::SourceAdditional, 'authority_reference' => 'Approved memo 42',
        ]);
        $this->assertSame('Approved memo 42', $prepared->authority_reference);
        $this->assertSame(Section::SourceAdditional, $prepared->source);
    }

    #[DataProvider('externalSourceErrors')]
    public function test_source_errors_without_form_fields_remain_visible_in_the_modal(string $case, string $error, string $notification): void
    {
        $this->registrar();
        [$term, $package, $curriculum, $specification, $section] = $this->sources();
        match ($case) {
            'calendar' => $package->update(['state' => TermCalendarPackage::StateDraft]),
            'course' => $specification->update(['state' => CourseSpecification::StateDraft]),
            'term' => $term->update(['state' => Term::StateClosed]),
        };
        $action = $case === 'term' ? 'recordCohort' : 'prepareClassOffering';
        $data = $case === 'term' ? ['curriculum_version_id' => $curriculum->id, 'reference' => 'CLOSED-COHORT', 'confirmed_count' => 20]
            : ['section_id' => $section->id, ...$this->classFacts()];
        $page = Livewire::withQueryParams(['termId' => $term->id, 'viewTab' => 'classes'])->test(TermClasses::class);
        if ($action === 'prepareClassOffering') {
            $page->callTableAction('prepareClassOffering', $section, $data);
        } else {
            $page->callAction($action, $data);
        }
        $page->assertHasActionErrors([$error])->assertNotified($notification);
    }

    /** @return array<string, array{string, string, string}> */
    public static function externalSourceErrors(): array
    {
        return [['calendar', 'calendar_package', 'Cannot prepare class'], ['course', 'course_revision', 'Cannot prepare class'], ['term', 'term_id', 'Cannot record cohort']];
    }

    #[DataProvider('invalidCohorts')]
    public function test_cohort_recording_rejects_invalid_count_curriculum_and_duplicate_reference(string $case, string $field): void
    {
        $actor = $this->registrar();
        [$term, , $curriculum] = $this->sources();
        $data = ['curriculum_version_id' => $curriculum->id, 'reference' => 'COHORT-A', 'confirmed_count' => 20];
        match ($case) {
            'zero-count' => $data['confirmed_count'] = 0,
            'fraction-count' => $data['confirmed_count'] = 2.5,
            'draft-curriculum' => $curriculum->update(['state' => CurriculumVersion::StateDraft]),
            'closed-term' => $term->update(['state' => Term::StateClosed]),
            'duplicate-reference' => TermCohort::factory()->create(['term_id' => $term->id, 'reference' => 'COHORT-A']),
        };
        $count = TermCohort::query()->count();
        try {
            app(RecordTermCohort::class)->execute($term, $actor, $data);
            $this->fail('Invalid cohort was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
        $this->assertSame($count, TermCohort::query()->count());
    }

    public function test_registrar_without_schedule_permission_cannot_prepare_or_record(): void
    {
        $actor = $this->registrar();
        $actor->roles->sole()->revokePermissionTo('manage-schedules');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $actor = $actor->fresh();
        [$term, , , , $section] = $this->sources();
        foreach ([fn () => app(PrepareClassOffering::class)->execute($term, $section, $actor, $this->classFacts()),
            fn () => app(RecordTermCohort::class)->execute($term, $actor, [])] as $operation) {
            try {
                $operation();
                $this->fail('Unauthorized write was accepted.');
            } catch (AuthorizationException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
        }
    }

    public function test_academic_head_with_schedule_permission_still_cannot_prepare_or_record(): void
    {
        $actor = User::factory()->create();
        $actor->assignRole(Role::findOrCreate(User::StaffRoleAcademicHead, 'web'));
        $actor->givePermissionTo(Permission::findOrCreate('manage-schedules', 'web'));
        [$term, , , , $section] = $this->sources();
        foreach ([fn () => app(PrepareClassOffering::class)->execute($term, $section, $actor, $this->classFacts()),
            fn () => app(RecordTermCohort::class)->execute($term, $actor, [])] as $operation) {
            try {
                $operation();
                $this->fail('Read-only role wrote scheduling sources.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
    }

    #[DataProvider('protectedSectionFacts')]
    public function test_alternate_section_service_cannot_change_confirmed_source_facts(string $field, mixed $value): void
    {
        $actor = $this->registrar();
        [, , , , $section] = $this->sources();
        $stale = $section->fresh();
        $section->update(['confirmed_at' => now(), 'confirmed_by' => $actor->id]);
        if ($field === 'term_offering_id') {
            $value = TermOffering::factory()->create()->id;
        }
        try {
            app(SectionPlanningService::class)->save($stale, [$field => $value], $actor);
            $this->fail('Confirmed source fact was changed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
        $this->assertSame($section->getAttribute($field), $section->fresh()->getAttribute($field));
    }

    public function test_section_edit_explains_and_blocks_confirmed_capacity_changes(): void
    {
        $actor = $this->registrar();
        [$term, , , , $section] = $this->sources();
        $section->update(['confirmed_at' => now(), 'confirmed_by' => $actor->id]);
        Livewire::withQueryParams(['termId' => $term->id])->test(EditSection::class, ['record' => $section->id])
            ->assertSee('This confirmed class identity, capacity and state are protected.')
            ->assertFormFieldIsDisabled('term_offering_id')
            ->assertFormFieldIsDisabled('code')
            ->assertFormFieldIsDisabled('capacity')
            ->assertFormFieldIsDisabled('state')
            ->assertActionDoesNotExist('save');
        $this->assertSame(30, $section->refresh()->capacity);
    }

    public function test_section_details_link_from_edit_keeps_the_exact_term_context(): void
    {
        $this->registrar();
        [$term, , , , $section] = $this->sources();

        Livewire::withQueryParams(['termId' => $term->id])->test(EditSection::class, ['record' => $section->id])
            ->assertSee(SectionResource::getUrl('view', ['record' => $section, 'termId' => $term->id]), false);
    }

    public function test_published_class_settings_are_read_only_and_delivery_groups_keep_view_only(): void
    {
        $this->registrar();
        [$term, , , , $section] = $this->sources();
        $group = SectionDeliveryGroup::factory()->for($section)->create(['name' => 'Published group']);
        PublishedTimetableMeeting::factory()->create(['section_id' => $section->id]);

        Livewire::withQueryParams(['termId' => $term->id])->test(EditSection::class, ['record' => $section->id])
            ->assertSee('Class settings')
            ->assertSee('This class is in the official timetable')
            ->assertSee('Open timetable')
            ->assertSee(TermPlanningWorkbench::getUrl(['termId' => $term->id, 'viewTab' => 'published']))
            ->assertSee('View class details')
            ->assertFormFieldIsDisabled('term_offering_id')
            ->assertFormFieldIsDisabled('code')
            ->assertFormFieldIsDisabled('capacity')
            ->assertFormFieldIsDisabled('state')
            ->assertActionDoesNotExist('save');

        Livewire::withQueryParams(['termId' => $term->id])->test(DeliveryGroupsRelationManager::class, ['ownerRecord' => $section, 'pageClass' => EditSection::class])
            ->assertSee('Groups used by the official timetable are read-only here.')
            ->assertDontSee('Add delivery group')
            ->assertTableActionHidden('edit', $group)
            ->assertTableActionVisible('view', $group);
    }

    public function test_confirmed_unpublished_class_groups_remain_editable(): void
    {
        $this->registrar();
        [, , , , $section] = $this->sources();
        $group = SectionDeliveryGroup::factory()->for($section)->create(['name' => 'Confirmed group']);
        $section->update(['confirmed_at' => now(), 'confirmed_by' => auth()->id()]);

        Livewire::test(DeliveryGroupsRelationManager::class, ['ownerRecord' => $section, 'pageClass' => EditSection::class])
            ->assertSee('Groups of learners that meet together.')
            ->assertSee('Add delivery group')
            ->assertTableActionVisible('edit', $group);
    }

    public function test_unconfirmed_source_edits_remain_available_and_no_op_confirmed_save_is_safe(): void
    {
        $actor = $this->registrar();
        [, , , , $section] = $this->sources();
        $saved = app(SectionPlanningService::class)->save($section, ['capacity' => 25], $actor);
        $this->assertSame(25, $saved->capacity);
        $saved->update(['confirmed_at' => now(), 'confirmed_by' => $actor->id]);
        $this->assertSame(25, app(SectionPlanningService::class)->save($saved, ['capacity' => 25], $actor)->capacity);
    }

    public function test_published_delivery_sources_cannot_be_changed_or_created(): void
    {
        $actor = $this->registrar();
        [, , , , $section] = $this->sources();
        $group = SectionDeliveryGroup::factory()->for($section)->create(['expected_count' => 20]);
        PublishedTimetableMeeting::factory()->create(['section_id' => $section->id]);
        foreach ([$group, null] as $target) {
            try {
                app(SectionDeliveryGroupService::class)->save($section, [
                    'name' => 'Changed source', 'expected_count' => 20, 'modality' => TermOffering::ModalityFaceToFace,
                    'state' => SectionDeliveryGroup::StateReady,
                ], $target, $actor);
                $this->fail('Published delivery source was changed.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('name', $exception->errors());
            }
        }
        $this->assertSame(1, $section->deliveryGroups()->count());
        $this->assertNotSame('Changed source', $group->refresh()->name);
        try {
            app(SectionPlanningService::class)->save($section, ['capacity' => 40], $actor);
            $this->fail('Published section capacity was changed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('capacity', $exception->errors());
        }
    }

    public function test_native_published_delivery_group_edit_is_hidden(): void
    {
        $this->registrar();
        [, , , , $section] = $this->sources();
        $group = SectionDeliveryGroup::factory()->for($section)->create(['name' => 'Published group', 'expected_count' => 20]);
        PublishedTimetableMeeting::factory()->create(['section_id' => $section->id]);
        Livewire::test(DeliveryGroupsRelationManager::class, ['ownerRecord' => $section, 'pageClass' => EditSection::class])
            ->assertTableActionHidden('edit', $group)
            ->assertTableActionVisible('view', $group);
        $this->assertSame('Published group', $group->refresh()->name);
    }

    /** @return array<string, array{string, mixed}> */
    public static function protectedSectionFacts(): array
    {
        return [['capacity', 40], ['code', 'OTHER-CLASS'], ['state', Section::StateCancelled], ['term_offering_id', null]];
    }

    /** @return array<string, array{string, string}> */
    public static function invalidPreparation(): array
    {
        return [
            ['foreign-term', 'section_id'], ['no-active-calendar', 'calendar_package'], ['inactive-course', 'course_revision'],
            ['additional-authority', 'authority_reference'], ['unsupported-mode', 'delivery_mode'], ['duplicate-reference', 'class_reference'],
            ['confirmed', 'section_id'], ['published', 'section_id'], ['closed-term', 'section_id'], ['cancelled-section', 'section_id'],
        ];
    }

    /** @return array<string, array{string, string}> */
    public static function invalidCohorts(): array
    {
        return [['zero-count', 'confirmed_count'], ['fraction-count', 'confirmed_count'], ['draft-curriculum', 'curriculum_version_id'], ['closed-term', 'term_id'], ['duplicate-reference', 'reference']];
    }

    /** @return array{Term, TermCalendarPackage, CurriculumVersion, CourseSpecification, Section} */
    private function sources(): array
    {
        $term = Term::factory()->create();
        $package = TermCalendarPackage::factory()->for($term)->create(['state' => TermCalendarPackage::StateActive, 'activated_at' => now()]);
        $specification = CourseSpecification::factory()->create(['state' => CourseSpecification::StateActive, 'allowed_modalities' => [TermOffering::ModalityFaceToFace]]);
        CourseComponent::factory()->for($specification)->create();
        $curriculum = CurriculumVersion::factory()->create(['state' => CurriculumVersion::StateActive]);
        $entry = CurriculumEntry::factory()->for($curriculum)->for($specification, 'courseSpecification')->create();
        $offering = TermOffering::factory()->for($term)->for($entry, 'curriculumEntry')->create(['state' => TermOffering::StatePendingScheduling]);
        $section = Section::factory()->for($offering, 'termOffering')->create(['capacity' => 30]);

        return [$term, $package, $curriculum, $specification, $section];
    }

    /** @return array<string, string> */
    private function classFacts(): array
    {
        return ['class_reference' => 'PREPARED-A', 'source' => Section::SourceRegular, 'delivery_mode' => TermOffering::ModalityFaceToFace];
    }

    private function registrar(): User
    {
        $role = Role::findOrCreate(User::StaffRoleRegistrar, 'web');
        $role->givePermissionTo(Permission::findOrCreate('manage-schedules', 'web'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $actor = User::factory()->create(['status' => User::StatusActive]);
        $actor->assignRole($role);
        $this->actingAs($actor)->withSession([WorkspaceContextResolver::SessionKey => User::StaffRoleRegistrar]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $actor;
    }

    public function test_bulk_confirmation_succeeds_for_valid_classes_and_is_idempotent_on_retry(): void
    {
        $actor = $this->registrar();
        [$term, $package, $curriculum, $specification, $section1] = $this->sources();
        $specification2 = CourseSpecification::factory()->create(['state' => CourseSpecification::StateActive, 'allowed_modalities' => [TermOffering::ModalityFaceToFace]]);
        CourseComponent::factory()->for($specification2)->create();
        $entry2 = CurriculumEntry::factory()->for($curriculum)->for($specification2, 'courseSpecification')->create();
        $offering2 = TermOffering::factory()->for($term)->for($entry2, 'curriculumEntry')->create(['state' => TermOffering::StatePendingScheduling]);
        $section2 = Section::factory()->for($offering2, 'termOffering')->create(['capacity' => 30]);

        $cohort = app(RecordTermCohort::class)->execute($term, $actor, [
            'curriculum_version_id' => $curriculum->id,
            'reference' => 'BATCH-COHORT-A',
            'confirmed_count' => 30,
        ]);

        $prep1 = app(PrepareClassOffering::class)->execute($term, $section1, $actor, [
            'class_reference' => 'BATCH-CLASS-1',
            'source' => Section::SourceRegular,
            'delivery_mode' => TermOffering::ModalityFaceToFace,
        ]);
        $prep2 = app(PrepareClassOffering::class)->execute($term, $section2, $actor, [
            'class_reference' => 'BATCH-CLASS-2',
            'source' => Section::SourceRegular,
            'delivery_mode' => TermOffering::ModalityFaceToFace,
        ]);

        Livewire::withQueryParams(['termId' => $term->id])->test(TermClasses::class)
            ->callTableBulkAction('confirmSelectedClasses', [$prep1, $prep2], ['classes' => [
                ['section_id' => $prep1->id, 'cohorts' => [['term_cohort_id' => $cohort->id, 'expected_count' => 15]]],
                ['section_id' => $prep2->id, 'cohorts' => [['term_cohort_id' => $cohort->id, 'expected_count' => 15]]],
            ]])->assertHasNoTableBulkActionErrors()->assertNotified('Selected classes confirmed');

        $confirmed = app(ConfirmClassOffering::class)->batch($actor, [
            ['section' => $prep1, 'cohort_counts' => [$cohort->id => 15]],
            ['section' => $prep2, 'cohort_counts' => [$cohort->id => 15]],
        ]);

        $this->assertCount(2, $confirmed);
        $this->assertNotNull($section1->fresh()->confirmed_at);
        $this->assertNotNull($section2->fresh()->confirmed_at);
        $this->assertSame(15, $section1->fresh()->cohorts->first()->pivot->expected_count);
        $this->assertSame(15, $section2->fresh()->cohorts->first()->pivot->expected_count);

        // Idempotent retry: succeeds without error or duplicate demands
        $retried = app(ConfirmClassOffering::class)->batch($actor, [
            ['section' => $section1->fresh(), 'cohort_counts' => [$cohort->id => 15]],
            ['section' => $section2->fresh(), 'cohort_counts' => [$cohort->id => 15]],
        ]);
        $this->assertCount(2, $retried);
        $confirmedAt = $section1->fresh()->confirmed_at->toIso8601String();
        $this->travel(1)->hours();
        app(ConfirmClassOffering::class)->batch($actor, [['section' => $prep1, 'cohort_counts' => [$cohort->id => 15]]]);
        $this->assertSame($confirmedAt, $section1->fresh()->confirmed_at->toIso8601String());
        try {
            app(ConfirmClassOffering::class)->batch(User::factory()->create(), [['section' => $section1->fresh(), 'cohort_counts' => [$cohort->id => 15]]]);
            $this->fail('Unauthorized confirmed retry was accepted.');
        } catch (AuthorizationException $exception) {
            $this->assertNotNull($exception);
        }
        foreach ([
            fn () => app(ConfirmClassOffering::class)->batch($actor, [['section' => $prep1, 'cohort_counts' => [$cohort->id => 16]]]),
            fn () => app(ConfirmClassOffering::class)->batch($actor, [['section' => $prep1, 'cohort_counts' => [$cohort->id => 15]]], Term::factory()->create()->id),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Changed confirmed facts or a foreign selected Term was accepted.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
        $this->assertSame(15, $section1->fresh()->cohorts->first()->pivot->expected_count);
        $stale = $section1->fresh();
        $package->update(['state' => TermCalendarPackage::StateClosed]);
        try {
            app(ConfirmClassOffering::class)->batch($actor, [['section' => $stale, 'cohort_counts' => [$cohort->id => 15]]]);
            $this->fail('Closed calendar was bypassed by a confirmed retry.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('calendar_package', $exception->errors());
        }

    }

    #[DataProvider('nativeBulkReviewCounts')]
    public function test_native_bulk_review_prepares_regular_classes_atomically(int $secondCount, bool $valid): void
    {
        [$term, $package, $cohort, $sections] = $this->unpreparedBulkClasses();
        foreach ($sections as $section) {
            $this->assertNull($section->term_calendar_package_id);
            $this->assertNull($section->confirmed_at);
        }
        $component = Livewire::withQueryParams(['termId' => $term->id])->test(TermClasses::class)
            ->callTableBulkAction('confirmSelectedClasses', $sections, ['classes' => [
                ['section_id' => $sections[0]->id, 'cohorts' => [['term_cohort_id' => $cohort->id, 'expected_count' => 10]]],
                ['section_id' => $sections[1]->id, 'cohorts' => [['term_cohort_id' => $cohort->id, 'expected_count' => $secondCount]]],
            ]]);

        if ($valid) {
            $component->assertHasNoTableBulkActionErrors()->assertNotified('Selected classes confirmed');
            foreach ($sections as $section) {
                $this->assertSame($package->id, $section->fresh()->term_calendar_package_id);
                $this->assertNotNull($section->fresh()->confirmed_at);
                $this->assertSame(10, $section->fresh()->cohorts->sole()->pivot->expected_count);
            }
        } else {
            $component->assertHasTableBulkActionErrors(['classes'])->assertNotified('Cannot confirm selected classes');
            foreach ($sections as $section) {
                $this->assertNull($section->fresh()->term_calendar_package_id);
                $this->assertNull($section->fresh()->course_specification_id);
                $this->assertNull($section->fresh()->confirmed_at);
                $this->assertSame(0, $section->fresh()->cohorts()->count());
            }
        }
    }

    public static function nativeBulkReviewCounts(): array
    {
        return ['valid counts' => [10, true], 'one count exceeds capacity' => [50, false]];
    }

    #[DataProvider('changedBulkSources')]
    public function test_native_bulk_review_rejects_source_changes_after_opening(string $source): void
    {
        [$term, , $cohort, $sections] = $this->unpreparedBulkClasses();
        $component = Livewire::withQueryParams(['termId' => $term->id])->test(TermClasses::class)
            ->mountTableBulkAction('confirmSelectedClasses', $sections)
            ->setTableBulkActionData(['classes' => array_map(fn (Section $section): array => [
                'section_id' => $section->id,
                'cohorts' => [['term_cohort_id' => $cohort->id, 'expected_count' => 10]],
            ], $sections)]);
        if ($source === 'class') {
            $sections[1]->update(['capacity' => 25]);
        } elseif ($source === 'offering') {
            $sections[1]->termOffering->update(['expected_count' => 25]);
        } elseif ($source === 'cohort') {
            $cohort->update(['confirmed_count' => 25]);
        } else {
            $term->calendarPackages()->where('state', TermCalendarPackage::StateActive)->update(['authority_reference' => 'Changed approved authority']);
        }
        $component->callMountedTableBulkAction()->assertHasTableBulkActionErrors(['classes'])->assertNotified('Cannot confirm selected classes');
        foreach ($sections as $section) {
            $this->assertNull($section->fresh()->term_calendar_package_id);
            $this->assertNull($section->fresh()->confirmed_at);
        }
        $this->assertSame($source === 'class' ? 25 : 30, $sections[1]->fresh()->capacity);
        $this->assertSame($source === 'cohort' ? 25 : 20, $cohort->fresh()->confirmed_count);
    }

    public static function changedBulkSources(): array
    {
        return ['class facts' => ['class'], 'offering facts' => ['offering'], 'cohort facts' => ['cohort'], 'calendar authority' => ['calendar']];
    }

    /** @return array{Term, TermCalendarPackage, TermCohort, list<Section>} */
    private function unpreparedBulkClasses(): array
    {
        $actor = $this->registrar();
        [$term, $package, $curriculum, , $first] = $this->sources();
        $specification = CourseSpecification::factory()->create(['state' => CourseSpecification::StateActive, 'allowed_modalities' => [TermOffering::ModalityFaceToFace]]);
        CourseComponent::factory()->for($specification)->create();
        $entry = CurriculumEntry::factory()->for($curriculum)->for($specification, 'courseSpecification')->create();
        $offering = TermOffering::factory()->for($term)->for($entry, 'curriculumEntry')->create();
        $second = Section::factory()->for($offering, 'termOffering')->create(['capacity' => 30]);
        $cohort = app(RecordTermCohort::class)->execute($term, $actor, [
            'curriculum_version_id' => $curriculum->id, 'reference' => 'BULK-REVIEW', 'confirmed_count' => 20,
        ]);

        return [$term, $package, $cohort, [$first, $second]];
    }

    public function test_bulk_confirmation_rolls_back_entire_batch_on_invalid_class_leaving_no_partial_confirmation(): void
    {
        $actor = $this->registrar();
        [$term, $package, $curriculum, $specification, $section1] = $this->sources();
        $specification2 = CourseSpecification::factory()->create(['state' => CourseSpecification::StateActive, 'allowed_modalities' => [TermOffering::ModalityFaceToFace]]);
        CourseComponent::factory()->for($specification2)->create();
        $entry2 = CurriculumEntry::factory()->for($curriculum)->for($specification2, 'courseSpecification')->create();
        $offering2 = TermOffering::factory()->for($term)->for($entry2, 'curriculumEntry')->create(['state' => TermOffering::StatePendingScheduling]);
        $section2 = Section::factory()->for($offering2, 'termOffering')->create(['capacity' => 30]);

        $cohort = app(RecordTermCohort::class)->execute($term, $actor, [
            'curriculum_version_id' => $curriculum->id,
            'reference' => 'BATCH-COHORT-B',
            'confirmed_count' => 20,
        ]);

        $prep1 = app(PrepareClassOffering::class)->execute($term, $section1, $actor, [
            'class_reference' => 'BATCH-ROLLBACK-1',
            'source' => Section::SourceRegular,
            'delivery_mode' => TermOffering::ModalityFaceToFace,
        ]);
        $prep2 = app(PrepareClassOffering::class)->execute($term, $section2, $actor, [
            'class_reference' => 'BATCH-ROLLBACK-2',
            'source' => Section::SourceRegular,
            'delivery_mode' => TermOffering::ModalityFaceToFace,
        ]);

        try {
            // Second item has invalid cohort demand exceeding capacity (50 > 30)
            app(ConfirmClassOffering::class)->batch($actor, [
                ['section' => $prep1, 'cohort_counts' => [$cohort->id => 10]],
                ['section' => $prep2, 'cohort_counts' => [$cohort->id => 50]],
            ]);
            $this->fail('Invalid batch was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('cohorts', $exception->errors());
        }

        // Neither section must remain confirmed (atomic rollback)
        $this->assertNull($section1->fresh()->confirmed_at);
        $this->assertNull($section2->fresh()->confirmed_at);
    }

    public function test_bulk_confirmation_rejects_duplicate_class_references_within_batch(): void
    {
        $actor = $this->registrar();
        [$term, $package, $curriculum, $specification, $section1] = $this->sources();
        $specification2 = CourseSpecification::factory()->create(['state' => CourseSpecification::StateActive, 'allowed_modalities' => [TermOffering::ModalityFaceToFace]]);
        CourseComponent::factory()->for($specification2)->create();
        $entry2 = CurriculumEntry::factory()->for($curriculum)->for($specification2, 'courseSpecification')->create();
        $offering2 = TermOffering::factory()->for($term)->for($entry2, 'curriculumEntry')->create(['state' => TermOffering::StatePendingScheduling]);
        $section2 = Section::factory()->for($offering2, 'termOffering')->create(['capacity' => 30]);

        $cohort = app(RecordTermCohort::class)->execute($term, $actor, [
            'curriculum_version_id' => $curriculum->id,
            'reference' => 'BATCH-COHORT-C',
            'confirmed_count' => 20,
        ]);

        $section1->update(['class_reference' => 'DUPLICATE-REF', 'term_calendar_package_id' => $package->id, 'course_specification_id' => $specification->id]);
        $section2->update(['class_reference' => 'DUPLICATE-REF', 'term_calendar_package_id' => $package->id, 'course_specification_id' => $specification2->id]);

        try {
            app(ConfirmClassOffering::class)->batch($actor, [
                ['section' => $section1, 'cohort_counts' => [$cohort->id => 10]],
                ['section' => $section2, 'cohort_counts' => [$cohort->id => 10]],
            ]);
            $this->fail('Duplicate class references in batch were accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items.1.class_reference', $exception->errors());
        }

        $this->assertNull($section1->fresh()->confirmed_at);
        $this->assertNull($section2->fresh()->confirmed_at);
    }
}
