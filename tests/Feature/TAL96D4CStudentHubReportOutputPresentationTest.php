<?php

namespace Tests\Feature;

use App\Actions\Reports\ExportOperationalReport;
use App\Actions\Reports\OperationalReportService;
use App\Filament\Pages\ReportsAudit;
use App\Filament\Student\Pages\Profile;
use App\Filament\Student\Widgets\ActiveHoldsWidget;
use App\Filament\Student\Widgets\StudentProfileOverviewWidget;
use App\Mail\PaymentPostedMail;
use App\Mail\ScheduleReleasedMail;
use App\Mail\ScheduleRevisionMail;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

final class TAL96D4CStudentHubReportOutputPresentationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
        $this->assertNotContains(DB::connection()->getDatabaseName(), ['tala_db', 'tala_test_codex']);

        foreach (['student', User::StaffRoleAccounting] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    #[Test]
    public function student_hub_keeps_priority_notices_without_exposing_a_second_notification_center(): void
    {
        $this->assertFalse(Filament::getPanel('student')->hasDatabaseNotifications());
    }

    #[Test]
    public function student_summary_and_holds_present_plain_language_responsive_guidance(): void
    {
        $student = User::factory()->create([
            'status' => User::StatusActive,
            'email_verified_at' => now(),
        ]);
        $student->assignRole('student');
        $program = Program::factory()->create();
        StudentProfile::factory()->for($student)->for($program)->create([
            'lifecycle_status' => StudentProfile::LifecycleActive,
            'academic_standing' => StudentProfile::StandingIrregular,
        ]);

        $this->actingAs($student);
        Filament::setCurrentPanel(Filament::getPanel('student'));

        Livewire::test(StudentProfileOverviewWidget::class)
            ->assertSee('This is your official Student Profile status.')
            ->assertSee('No recorded academic or lifecycle authority blocks ordinary curriculum placement.')
            ->assertSee('No outstanding posted balance.')
            ->assertDontSee('Source #')
            ->assertDontSee('blockers:');

        Livewire::test(Profile::class)
            ->assertSee('Enrollment guidance')
            ->assertDontSee('IRREGULAR');

        $holds = Livewire::test(ActiveHoldsWidget::class)->instance();

        $this->assertInstanceOf(ActiveHoldsWidget::class, $holds);
        $this->assertTrue($holds->getTable()->isStackedOnMobile());
    }

    #[Test]
    public function reports_stack_on_mobile_and_csv_exports_are_readable_safe_and_excel_compatible(): void
    {
        $accounting = User::factory()->create(['status' => User::StatusActive]);
        $accounting->assignRole(User::StaffRoleAccounting);

        $this->actingAs($accounting);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $page = Livewire::test(ReportsAudit::class)->instance();

        $this->assertInstanceOf(ReportsAudit::class, $page);
        $this->assertTrue($page->getTable()->isStackedOnMobile());

        $response = app(ExportOperationalReport::class)->execute(
            $accounting,
            OperationalReportService::DailyCash,
            [],
            'Daily cashier turnover review.',
        );
        $csv = $this->streamedContent($response);

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('daily-cash-collection-daily-turnover-', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('Transaction Date/Time', $csv);
    }

    #[Test]
    public function official_outputs_and_operational_emails_share_clear_identity_and_action_guidance(): void
    {
        $output = Blade::render(
            '<x-official-output-layout title="Test Output" context="Student Copy" generated-at="July 24, 2026 10:00 AM"><p>Verified body</p></x-official-output-layout>',
        );

        $this->assertStringContainsString((string) config('institution.name'), $output);
        $this->assertStringContainsString('Test Output', $output);
        $this->assertStringContainsString('Student Copy', $output);
        $this->assertStringContainsString('Print / Save as PDF', $output);
        $this->assertStringContainsString('Verified body', $output);

        $released = (new ScheduleReleasedMail(
            operationalEventId: 1,
            recipientName: 'Test Student',
            termLabel: 'First Semester 2026-2027',
            scheduleUrl: 'https://example.test/student/schedule-view',
        ))->render();
        $revised = (new ScheduleRevisionMail(
            operationalEventId: 2,
            recipientName: 'Test Student',
            revisionPayload: ['changes' => []],
        ))->render();
        $payment = (new PaymentPostedMail(
            operationalEventId: 3,
            recipientName: 'Test Student',
            amount: 'PHP 1,000.00',
            termLabel: 'First Semester 2026-2027',
            financeUrl: 'https://example.test/student/finance',
        ))->render();

        foreach ([$released, $revised] as $scheduleMail) {
            $this->assertStringContainsString('Registrar Office', $scheduleMail);
            $this->assertStringContainsString('current published schedule', $scheduleMail);
        }

        $this->assertStringContainsString('Accounting Office', $payment);
        $this->assertStringContainsString('official receipt', $payment);
    }

    private function streamedContent(StreamedResponse $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }
}
