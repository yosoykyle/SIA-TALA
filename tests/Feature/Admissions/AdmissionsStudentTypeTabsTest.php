<?php

namespace Tests\Feature\Admissions;

use App\Filament\Clusters\Admissions;
use App\Filament\Resources\AdmissionApplications\Pages\ListAdmissionApplications;
use App\Models\AdmissionApplication;
use App\Models\User;
use App\Queries\Admissions\AdmissionQueueCounts;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdmissionsStudentTypeTabsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_student_type_tab_filters_queue_counts_and_carries_into_queue_links(): void
    {
        $queues = app(AdmissionQueueCounts::class);
        $before = [
            'all' => $queues->count(AdmissionQueueCounts::NeedsReview),
            'transferee' => $queues->count(AdmissionQueueCounts::NeedsReview, AdmissionApplication::PathTransferee),
            'transfereeCases' => $queues->activeCases(AdmissionApplication::PathTransferee),
        ];
        AdmissionApplication::factory()->submitted()->count(2)->create();
        AdmissionApplication::factory()->submitted()->transferee()->create();

        $this->assertSame($before['all'] + 3, $queues->count(AdmissionQueueCounts::NeedsReview));
        $this->assertSame($before['transferee'] + 1, $queues->count(AdmissionQueueCounts::NeedsReview, AdmissionApplication::PathTransferee));
        $transfereeCases = $queues->activeCases(AdmissionApplication::PathTransferee);
        $this->assertSame($before['transfereeCases']['admission'] + 1, $transfereeCases['admission']);
        $this->assertSame($transfereeCases['admission'] + $transfereeCases['enrollment'], $transfereeCases['total']);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::actingAs($this->registrar())->test(Admissions::class)
            ->assertSeeInOrder(['Student type', 'All types', 'Freshmen', 'Transferees', 'Returnees', 'Soon', 'Applications to review'])
            ->assertSet('type', null)
            ->call('selectStudentType', AdmissionApplication::PathTransferee)
            ->assertSet('type', AdmissionApplication::PathTransferee)
            ->assertSee('Counts and queue links show transferees only.');
        $this->assertSame($before['transferee'] + 1, $page->viewData('queueCounts')[AdmissionQueueCounts::NeedsReview]);
        $this->assertStringContainsString('filters%5Bapplication_path%5D%5Bvalue%5D=Transferee',
            $page->instance()->queueUrl(AdmissionQueueCounts::NeedsReview));

        $page->call('selectStudentType', 'Returnee')->assertSet('type', null);
        $this->assertStringNotContainsString('filters', $page->instance()->queueUrl(AdmissionQueueCounts::NeedsReview));

        $transfereeCount = $queues->count(AdmissionQueueCounts::NeedsReview, AdmissionApplication::PathTransferee);
        Livewire::actingAs($this->registrar())->test(ListAdmissionApplications::class, ['activeTab' => AdmissionQueueCounts::NeedsReview])
            ->filterTable('application_path', AdmissionApplication::PathTransferee)
            ->assertSee($transfereeCount === 1 ? '1 application' : $transfereeCount.' applications')
            ->assertDontSee(($before['all'] + 3).' applications');
    }

    private function registrar(): User
    {
        Role::findOrCreate(User::StaffRoleRegistrar, 'web');
        $registrar = User::factory()->create(['status' => User::StatusActive]);
        $registrar->assignRole(User::StaffRoleRegistrar);

        return $registrar;
    }
}
