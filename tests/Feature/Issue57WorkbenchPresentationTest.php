<?php

namespace Tests\Feature;

use App\Actions\Admissions\ReviewPreliminaryEvidence;
use App\Filament\Applicant\Pages\Requirements;
use App\Filament\Resources\AdmissionApplications\Pages\ListAdmissionApplications;
use App\Filament\Resources\AdmissionApplications\Pages\ViewAdmissionApplication;
use App\Filament\Resources\AdmissionCycles\AdmissionCycleResource;
use App\Http\Middleware\EnsureStaffMfaIsEnabled;
use App\Models\AdmissionApplication;
use App\Models\AdmissionApplicationEvent;
use App\Models\AdmissionCycle;
use App\Models\AdmissionRequirement;
use App\Models\AdmissionRequirementSet;
use App\Models\ApplicationSubmissionVersion;
use App\Models\DocumentEvidence;
use App\Models\PreliminaryEvidenceReview;
use App\Models\Term;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class Issue57WorkbenchPresentationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_evidence_action_accessible_names_identify_the_copy_and_escape_requirement_text(): void
    {
        $registrar = $this->registrar();
        Role::findOrCreate('applicant', 'web');
        $applicant = User::factory()->create(['status' => User::StatusActive]);
        $applicant->assignRole('applicant');
        $application = AdmissionApplication::factory()->submitted()->create(['user_id' => $applicant->id]);
        $set = AdmissionRequirementSet::factory()->create([
            'admission_cycle_id' => $application->admission_cycle_id, 'application_path' => $application->application_path,
        ]);
        $requirement = AdmissionRequirement::factory()->create([
            'admission_requirement_set_id' => $set->id,
            'label' => 'PSA "Applicant" & photo <copy>', 'requires_preliminary_evidence' => true,
        ]);
        $set->update(['state' => AdmissionRequirementSet::StatePublished, 'published_at' => now(), 'effective_at' => now()]);
        $version = ApplicationSubmissionVersion::factory()->create([
            'admission_application_id' => $application->id, 'admission_requirement_set_id' => $set->id, 'submitted_by' => $applicant->id,
        ]);
        $application->update(['current_submission_version_id' => $version->id]);
        $evidence = DocumentEvidence::factory()->canonical($application, $requirement, $version)->create(['mime_type' => 'application/pdf']);

        $registrarHtml = Livewire::actingAs($registrar)->test(ViewAdmissionApplication::class, ['record' => $application->id])->html();
        Filament::setCurrentPanel(Filament::getPanel('applicant'));
        $applicantHtml = Livewire::actingAs($applicant)->test(Requirements::class, ['sourceApplicationId' => $application->id])->html();

        foreach ([
            [$registrarHtml, ['View '.$requirement->label.' copy '.$evidence->id, 'Review '.$requirement->label.' copy '.$evidence->id, 'Download '.$requirement->label.' copy '.$evidence->id]],
            [$applicantHtml, ['View '.$requirement->label.' private copy', 'Download '.$requirement->label.' private copy']],
        ] as [$html, $expectedNames]) {
            $document = new \DOMDocument;
            @$document->loadHTML($html);
            $xpath = new \DOMXPath($document);
            $names = [];
            foreach ($xpath->query('//*[@aria-label]') as $element) {
                $names[] = $element->getAttribute('aria-label');
            }
            foreach ($expectedNames as $expectedName) {
                $this->assertContains($expectedName, $names);
            }
        }
    }

    public function test_current_and_historical_acknowledgment_links_preserve_only_a_known_queue(): void
    {
        $registrar = $this->registrar();
        $application = AdmissionApplication::factory()->submitted()->create();
        $set = AdmissionRequirementSet::factory()->create([
            'admission_cycle_id' => $application->admission_cycle_id,
            'application_path' => $application->application_path,
        ]);
        $historical = ApplicationSubmissionVersion::factory()->create([
            'admission_application_id' => $application->id, 'admission_requirement_set_id' => $set->id, 'version' => 1,
        ]);
        $current = ApplicationSubmissionVersion::factory()->create([
            'admission_application_id' => $application->id, 'admission_requirement_set_id' => $set->id, 'version' => 2,
        ]);
        $application->update(['current_submission_version_id' => $current->id]);

        Livewire::withQueryParams(['queue' => 'history'])->actingAs($registrar)->test(ViewAdmissionApplication::class, ['record' => $application->id])
            ->assertActionHasUrl('acknowledgment', route('admissions.application.acknowledgment', [
                'application' => $application, 'version' => $current, 'queue' => 'history',
            ]))
            ->assertSee(e(route('admissions.application.acknowledgment', [
                'application' => $application, 'version' => $historical, 'queue' => 'history',
            ])), false);

        Livewire::withQueryParams(['queue' => 'unrecognized'])->actingAs($registrar)->test(ViewAdmissionApplication::class, ['record' => $application->id])
            ->assertActionHasUrl('acknowledgment', route('admissions.application.acknowledgment', [
                'application' => $application, 'version' => $current,
            ]))
            ->assertDontSee('queue=unrecognized', false);
    }

    public function test_setup_only_registrar_can_find_admission_cycles_without_an_unauthorized_queue_link(): void
    {
        $role = Role::findOrCreate(User::StaffRoleRegistrar, 'web');
        $role->syncPermissions([Permission::findOrCreate('manage-admission-setup', 'web')]);
        $registrar = User::factory()->create(['status' => User::StatusActive]);
        $registrar->assignRole($role);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withoutMiddleware(EnsureStaffMfaIsEnabled::class);

        $this->actingAs($registrar)->get(AdmissionCycleResource::getUrl())
            ->assertOk()->assertSee('Admission cycles')->assertDontSee('Application queue');
    }

    public function test_initial_empty_workbench_has_recovery_and_hides_zero_queue_badges(): void
    {
        $registrar = $this->registrar();
        $scopes = AdmissionApplication::getAllGlobalScopes();
        AdmissionApplication::addGlobalScope('issue57_empty_dataset', fn (Builder $query): Builder => $query->whereRaw('1 = 0'));

        try {
            $component = Livewire::actingAs($registrar)->test(ListAdmissionApplications::class)
                ->assertSee('No applications in this queue')
                ->assertSee('Choose another queue, clear search and filters, or wait for an Applicant submission.');
            $this->assertSame(0, $component->instance()->getTableRecords()->count());
            foreach ($component->instance()->getTabs() as $tab) {
                $this->assertNull($tab->getBadge());
            }
        } finally {
            AdmissionApplication::setAllGlobalScopes($scopes);
        }
    }

    public function test_history_queue_distinguishes_custodianship_from_an_active_task(): void
    {
        $registrar = $this->registrar();
        $notAdmitted = AdmissionApplication::factory()->state([
            'admission_cycle_id' => AdmissionCycle::factory()->state([
                'term_id' => Term::query()->value('id') ?? Term::factory()->create()->id,
            ]),
        ])->create(['application_state' => AdmissionApplication::StateNotAdmitted]);
        $withdrawn = AdmissionApplication::factory()->state([
            'admission_cycle_id' => AdmissionCycle::factory()->state([
                'term_id' => Term::query()->value('id') ?? Term::factory()->create()->id,
            ]),
        ])->create(['application_state' => AdmissionApplication::StateWithdrawn]);

        Livewire::actingAs($registrar)->test(ListAdmissionApplications::class)
            ->set('activeTab', 'history')
            ->assertCanSeeTableRecords([$notAdmitted, $withdrawn])
            ->assertTableColumnStateSet('owner_next_action', 'No active task — Registrar retains history', $notAdmitted)
            ->assertTableColumnStateSet('owner_next_action', 'No active task — Registrar retains history', $withdrawn);
    }

    public function test_admission_events_drive_display_order_sort_and_activity_filter(): void
    {
        $registrar = $this->registrar();
        $older = AdmissionApplication::factory()->state([
            'admission_cycle_id' => AdmissionCycle::factory()->state([
                'term_id' => Term::query()->value('id') ?? Term::factory()->create()->id,
            ]),
        ])->submitted()->create(['last_name' => 'Issue57ActivityProbe', 'updated_at' => now()->subDays(5)]);
        $newer = AdmissionApplication::factory()->state([
            'admission_cycle_id' => AdmissionCycle::factory()->state([
                'term_id' => Term::query()->value('id') ?? Term::factory()->create()->id,
            ]),
        ])->submitted()->create(['last_name' => 'Issue57ActivityProbe', 'updated_at' => now()->subDays(10)]);
        AdmissionApplicationEvent::factory()->create([
            'admission_application_id' => $older->id,
            'event_type' => AdmissionApplicationEvent::TypeSubmitted,
            'occurred_at' => now()->subDays(3),
        ]);
        $activity = now()->subHour()->startOfSecond();
        AdmissionApplicationEvent::factory()->create([
            'admission_application_id' => $newer->id,
            'event_type' => AdmissionApplicationEvent::TypeCredentialResultRecorded,
            'occurred_at' => $activity,
        ]);
        AdmissionApplicationEvent::factory()->create([
            'admission_application_id' => $newer->id,
            'event_type' => AdmissionApplicationEvent::TypeReadinessBecameTrue,
            'occurred_at' => now()->subDays(2),
        ]);
        $component = Livewire::actingAs($registrar)->test(ListAdmissionApplications::class)
            ->searchTable($older->admissionCycle->code);
        $component->searchTable('');
        $records = $component->instance()->getTableRecords();
        $this->assertSame($activity->toDateTimeString(), $records->firstWhere('id', $newer->id)->last_activity_at);
        $component->assertCanSeeTableRecords([$older, $newer], inOrder: true)
            ->sortTable('last_activity_at', 'desc')
            ->assertCanSeeTableRecords([$newer, $older], inOrder: true)
            ->filterTable('activity_at', ['from' => now()->subDay()->toDateTimeString(), 'until' => now()->toDateTimeString()])
            ->assertCanSeeTableRecords([$newer])
            ->assertCanNotSeeTableRecords([$older]);
    }

    public function test_native_content_hook_exposes_school_workspace_identity_outside_the_drawer(): void
    {
        $this->withoutMiddleware(EnsureStaffMfaIsEnabled::class);
        $registrar = $this->registrar();
        $response = $this->actingAs($registrar)->get(ListAdmissionApplications::getUrl());
        $response->assertOk()->assertSee('tala-mobile-workspace-identity', false)
            ->assertSee('Servitech Institute Asia — Staff Workspace')
            ->assertSee('The latest request could not be confirmed')
            ->assertSee('Reload workbench');
        $html = $response->getContent();
        $document = new \DOMDocument;
        @$document->loadHTML($html);
        $xpath = new \DOMXPath($document);
        $identities = $xpath->query('//main//*[contains(concat(" ", normalize-space(@class), " "), " tala-mobile-workspace-identity ")]');

        $this->assertCount(1, $identities);
        $this->assertSame('Servitech Institute Asia', trim(preg_replace('/\s+/u', ' ', $identities->item(0)->textContent)));
    }

    public function test_opening_a_record_retains_the_queue_and_returning_restores_search_filter_and_sort(): void
    {
        $registrar = $this->registrar();
        $application = AdmissionApplication::factory()->state([
            'admission_cycle_id' => AdmissionCycle::factory()->state([
                'term_id' => Term::query()->value('id') ?? Term::factory()->create()->id,
            ]),
        ])->submitted()->create(['last_name' => 'Issue57ReturnContext']);

        $queue = Livewire::actingAs($registrar)->test(ListAdmissionApplications::class)
            ->searchTable('Issue57ReturnContext')
            ->filterTable('application_path', $application->application_path)
            ->sortTable('last_activity_at', 'desc')
            ->assertCanSeeTableRecords([$application]);
        $url = $queue->instance()->getTable()->getRecordUrl($application);
        $this->assertStringContainsString('queue=needs_review', $url);
        $this->withoutMiddleware(EnsureStaffMfaIsEnabled::class);
        $this->actingAs($registrar)->get($url)->assertOk()
            ->assertSeeHtml(ListAdmissionApplications::getUrl(['tab' => 'needs_review']));

        Livewire::actingAs($registrar)->test(ListAdmissionApplications::class)
            ->assertSet('tableSearch', 'Issue57ReturnContext')
            ->assertSet('tableSort', 'last_activity_at:desc')
            ->assertSet('tableFilters.application_path.value', $application->application_path)
            ->assertCanSeeTableRecords([$application]);
    }

    public function test_real_preliminary_review_advances_activity_without_touching_application_or_creating_an_event(): void
    {
        $registrar = $this->registrar();
        $application = AdmissionApplication::factory()->state([
            'admission_cycle_id' => AdmissionCycle::factory()->state([
                'term_id' => Term::query()->value('id') ?? Term::factory()->create()->id,
            ]),
        ])->submitted()->create([
            'last_name' => 'Issue57RealReviewProbe', 'updated_at' => now()->subDays(4),
        ]);
        $set = AdmissionRequirementSet::factory()->for($application->admissionCycle)->create();
        $requirement = AdmissionRequirement::factory()->for($set, 'requirementSet')->create();
        $evidence = DocumentEvidence::factory()->canonical($application, $requirement)->create([
            'uploaded_at' => now()->subDays(5),
        ]);
        $updatedAt = $application->fresh()->updated_at->toDateTimeString();
        $review = app(ReviewPreliminaryEvidence::class)->execute($evidence, $registrar,
            PreliminaryEvidenceReview::ResultAccepted, 'Current copy reviewed.');
        $this->assertSame($updatedAt, $application->fresh()->updated_at->toDateTimeString());
        $this->assertSame(0, $application->events()->count());
        $component = Livewire::actingAs($registrar)->test(ListAdmissionApplications::class)
            ->searchTable('Issue57RealReviewProbe');
        $record = $component->instance()->getTableRecords()->firstWhere('id', $application->id);
        $this->assertSame($review->reviewed_at->toDateTimeString(), $record->last_activity_at);
        $component->filterTable('activity_at', ['from' => now()->subHour()->toDateTimeString()])
            ->assertCanSeeTableRecords([$application]);
        $component->resetTableFilters()->filterTable('activity_at', ['until' => now()->subHour()->toDateTimeString()])
            ->assertCanNotSeeTableRecords([$application]);
    }

    private function registrar(): User
    {
        Role::findOrCreate(User::StaffRoleRegistrar, 'web');
        $registrar = User::factory()->create(['status' => User::StatusActive]);
        $registrar->assignRole(User::StaffRoleRegistrar);
        $registrar->givePermissionTo(Permission::findOrCreate('approve-documents', 'web'));
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $registrar;
    }
}
