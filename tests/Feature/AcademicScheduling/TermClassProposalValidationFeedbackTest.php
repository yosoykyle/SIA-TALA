<?php

namespace Tests\Feature\AcademicScheduling;

use App\Filament\Pages\TermClasses;
use App\Models\CourseComponent;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\SchedulingDemand;
use App\Models\Section;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCohort;
use App\Models\TermOffering;
use App\Models\TermTeachingGridRow;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TermClassProposalValidationFeedbackTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_loading_from_curriculum_with_missing_required_fields_explains_the_failure_without_creating_classes(): void
    {
        [$term, $entry, $cohort, $actor] = $this->source();

        Livewire::actingAs($actor)
            ->withQueryParams(['termId' => $term->id])
            ->test(TermClasses::class)
            ->callAction('loadCurriculumSubjects', [
                'term_cohort_id' => $cohort->id,
                'year_level' => '1',
                'default_capacity' => null,
                'default_modality' => TermOffering::ModalityOnline,
                'entry_ids' => [(string) $entry->id],
            ])
            ->assertHasActionErrors(['default_capacity']);

        $this->assertSame(0, Section::query()->whereHas('termOffering', fn ($query) => $query->where('term_id', $term->id))->count());
    }

    public function test_loading_from_curriculum_with_invalid_capacity_reports_service_validation_feedback(): void
    {
        [$term, $entry, $cohort, $actor] = $this->source();
        $cohort->update(['confirmed_count' => 30]);

        Livewire::actingAs($actor)
            ->withQueryParams(['termId' => $term->id])
            ->test(TermClasses::class)
            ->callAction('loadCurriculumSubjects', [
                'term_cohort_id' => $cohort->id,
                'year_level' => '1',
                'default_capacity' => 5,
                'default_modality' => TermOffering::ModalityOnline,
                'entry_ids' => [(string) $entry->id],
            ])
            ->assertHasActionErrors(['entry_ids']);

        Notification::assertNotified('Cannot load classes from curriculum');
        $this->assertSame(0, Section::query()->whereHas('termOffering', fn ($query) => $query->where('term_id', $term->id))->count());
    }

    public function test_loading_complete_curriculum_subjects_confirms_classes_and_syncs_demands(): void
    {
        [$term, $entry, $cohort, $actor] = $this->source();

        Livewire::actingAs($actor)
            ->withQueryParams(['termId' => $term->id])
            ->test(TermClasses::class)
            ->callAction('loadCurriculumSubjects', [
                'term_cohort_id' => $cohort->id,
                'year_level' => '1',
                'default_capacity' => 30,
                'default_modality' => TermOffering::ModalityOnline,
                'entry_ids' => [(string) $entry->id],
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('1 class added and confirmed');

        $section = Section::query()
            ->whereHas('termOffering', fn ($query) => $query->where('term_id', $term->id))
            ->sole();
        $this->assertNotNull($section->confirmed_at);
        $this->assertSame(30, $section->capacity);
        $this->assertSame(1, SchedulingDemand::query()->whereHas('termOffering', fn ($query) => $query->where('term_id', $term->id))->count());
    }

    /** @return array{Term,CurriculumEntry,TermCohort,User} */
    private function source(): array
    {
        $role = Role::query()->firstOrCreate(['name' => User::StaffRoleRegistrar, 'guard_name' => 'web']);
        $role->givePermissionTo(Permission::findOrCreate('manage-schedules', 'web'));
        $actor = User::factory()->create();
        $actor->assignRole($role);

        $package = TermCalendarPackage::factory()->create(['state' => TermCalendarPackage::StateActive, 'activated_at' => now()]);
        $term = $package->term;
        $term->update(['state' => Term::StateActive]);
        TermTeachingGridRow::factory()->create(['term_calendar_package_id' => $package->id]);
        $curriculum = CurriculumVersion::factory()->create(['state' => CurriculumVersion::StateActive]);
        $revision = CourseSpecification::factory()->create(['state' => CourseSpecification::StateActive, 'allowed_modalities' => [TermOffering::ModalityOnline]]);
        CourseComponent::factory()->for($revision, 'courseSpecification')->create();
        $entry = CurriculumEntry::factory()->for($curriculum, 'curriculumVersion')->create(['course_specification_id' => $revision->id, 'year_level' => '1', 'term_type' => $term->type]);
        $cohort = TermCohort::factory()->create([
            'term_id' => $term->id,
            'program_id' => $curriculum->program_id,
            'curriculum_version_id' => $curriculum->id,
            'confirmed_count' => 10,
            'confirmed_at' => now(),
            'confirmed_by' => $actor->id,
        ]);

        return [$term, $entry, $cohort, $actor];
    }
}
