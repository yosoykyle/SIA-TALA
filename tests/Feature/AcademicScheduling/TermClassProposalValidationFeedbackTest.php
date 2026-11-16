<?php

namespace Tests\Feature\AcademicScheduling;

use App\Filament\Pages\TermClassProposal;
use App\Models\CourseComponent;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
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
        Filament::setCurrentPanel(Filament::getPanel('staff'));
    }

    public function test_confirming_a_proposal_with_an_unfilled_required_field_reveals_the_item_and_explains_the_failure(): void
    {
        [$term, $entry, , $actor] = $this->source();

        $page = Livewire::actingAs($actor)->test(TermClassProposal::class, ['termId' => $term->id]);
        $page->callAction('addCourses', ['program' => $entry->curriculumVersion->program->name, 'entries' => [$entry->id]])
            ->assertHasNoActionErrors();
        $rows = $page->get('proposal.classes');
        $key = array_key_first($rows);
        $page->set("proposal.classes.{$key}.capacity", null);

        $this->assertFalse($page->get('revealProposalErrors'), 'The proposal should start with its class items collapsed.');

        $page->callAction('confirmProposal');

        $page->assertHasErrors(["proposal.classes.{$key}.capacity"]);
        $this->assertTrue($page->get('revealProposalErrors'), 'A failed confirmation must reveal every class item so the invalid field is visible.');
        Notification::assertNotified();
        $this->assertSame(0, Section::query()->where('code', 'SYNTH-CP-FEEDBACK')->count());
    }

    public function test_confirming_a_complete_proposal_still_succeeds(): void
    {
        [$term, $entry, $cohort, $actor] = $this->source();

        $page = Livewire::actingAs($actor)->test(TermClassProposal::class, ['termId' => $term->id]);
        $page->callAction('addCourses', ['program' => $entry->curriculumVersion->program->name, 'entries' => [$entry->id]])
            ->assertHasNoActionErrors();
        $rows = $page->get('proposal.classes');
        $key = array_key_first($rows);
        $page->set("proposal.classes.{$key}.code", 'SYNTH-CP-FEEDBACK');
        $page->set("proposal.classes.{$key}.capacity", 30);
        $page->set("proposal.classes.{$key}.modality", TermOffering::ModalityOnline);
        $page->set("proposal.classes.{$key}.cohorts", [['id' => $cohort->id, 'count' => 10]]);

        $page->callAction('confirmProposal')->assertHasNoFormErrors();

        $this->assertSame(1, Section::query()->where('code', 'SYNTH-CP-FEEDBACK')->count());
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
        $cohort = TermCohort::factory()->create(['term_id' => $term->id, 'program_id' => $curriculum->program_id, 'curriculum_version_id' => $curriculum->id]);

        return [$term, $entry, $cohort, $actor];
    }
}
