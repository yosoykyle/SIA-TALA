<?php

namespace Tests\Feature;

use App\Actions\Calendar\CalendarPhaseGateService;
use App\Actions\Calendar\Exceptions\CalendarGateViolation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Tests the Calendar Phase Gate Service, which manages phase gates
 * (like enrollment and scheduling windows) to allow or block access
 * based on active periods and cutover dates.
 *
 * Steps / Test Cases:
 * 1. test_enrollment_gate_allows_when_cutover_is_active_and_window_is_open
 * 2. test_enrollment_gate_blocks_when_outside_window
 * 3. test_scheduling_gate_blocks_until_scheduling_starts_at_is_reached
 * 4. test_cutover_is_level_scoped_and_non_active_level_keeps_legacy_behavior
 * 5. test_enrollment_edit_middleware_blocks_after_enrollment_end_and_logs_audit_trail
 */
class CalendarPhaseGateServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->prepareTestSchema();
        $this->seedSystemSettings();

        if (! Route::has('testing.enrollment-edit')) {
            Route::post('/_testing/enrollment/edit', fn () => response()->json(['ok' => true]))
                ->middleware('enrollment.edit.window')
                ->name('testing.enrollment-edit');
        }
    }

    public function test_enrollment_gate_allows_when_cutover_is_active_and_window_is_open(): void
    {
        $evaluatedAt = CarbonImmutable::parse('2026-07-15 09:00:00', 'Asia/Manila');
        $termId = $this->createTerm('shs', [
            'term_name' => 'Q1 AY 2026-2027',
            'term_start_date' => '2026-07-01',
            'enrollment_starts_at' => '2026-07-10 00:00:00',
            'enrollment_ends_at' => '2026-07-20 23:59:59',
            'scheduling_starts_at' => '2026-07-05 00:00:00',
        ]);

        $this->setCutover('shs', 'Q1 AY 2026-2027', CarbonImmutable::parse('2026-07-01T00:00:00+08:00'));

        app(CalendarPhaseGateService::class)->assertEnrollmentWindowOpen($termId, 'shs', $evaluatedAt);

        $this->assertTrue(true);
    }

    public function test_enrollment_gate_blocks_when_outside_window(): void
    {
        $evaluatedAt = CarbonImmutable::parse('2026-07-21 09:00:00', 'Asia/Manila');
        $termId = $this->createTerm('shs', [
            'term_name' => 'Q1 AY 2026-2027',
            'term_start_date' => '2026-07-01',
            'enrollment_starts_at' => '2026-07-10 00:00:00',
            'enrollment_ends_at' => '2026-07-20 23:59:59',
        ]);

        $this->setCutover('shs', 'Q1 AY 2026-2027', CarbonImmutable::parse('2026-07-01T00:00:00+08:00'));

        $this->expectException(CalendarGateViolation::class);
        $this->expectExceptionMessage('Enrollment is outside the configured window.');

        app(CalendarPhaseGateService::class)->assertEnrollmentWindowOpen($termId, 'shs', $evaluatedAt);
    }

    public function test_scheduling_gate_blocks_until_scheduling_starts_at_is_reached(): void
    {
        $termId = $this->createTerm('college', [
            'term_name' => '1st Sem AY 2026-2027',
            'term_type' => 'semester',
            'term_start_date' => '2026-08-01',
            'enrollment_starts_at' => '2026-07-20 00:00:00',
            'enrollment_ends_at' => '2026-08-10 23:59:59',
            'scheduling_starts_at' => '2026-07-15 00:00:00',
        ]);

        $this->setCutover('college', '1st Sem AY 2026-2027', CarbonImmutable::parse('2026-07-01T00:00:00+08:00'));

        $this->expectException(CalendarGateViolation::class);
        $this->expectExceptionMessage('Scheduling is not open yet for this term.');

        app(CalendarPhaseGateService::class)->assertSchedulingWindowOpen(
            $termId,
            'college',
            CarbonImmutable::parse('2026-07-14 23:59:59', 'Asia/Manila'),
        );
    }

    public function test_cutover_is_level_scoped_and_non_active_level_keeps_legacy_behavior(): void
    {
        $termId = $this->createTerm('college', [
            'term_name' => '1st Sem AY 2026-2027',
            'term_type' => 'semester',
            'term_start_date' => '2026-08-01',
            'enrollment_starts_at' => null,
            'enrollment_ends_at' => null,
        ]);

        $this->setCutover('shs', 'Q1 AY 2026-2027', CarbonImmutable::parse('2026-07-01T00:00:00+08:00'));

        app(CalendarPhaseGateService::class)->assertEnrollmentWindowOpen(
            $termId,
            'college',
            CarbonImmutable::parse('2026-08-05 10:00:00', 'Asia/Manila'),
        );

        $this->assertFalse(app(CalendarPhaseGateService::class)->isCutoverActive($termId, 'college'));
    }

    public function test_enrollment_edit_middleware_blocks_after_enrollment_end_and_logs_audit_trail(): void
    {
        $termId = $this->createTerm('shs', [
            'term_name' => 'Q1 AY 2026-2027',
            'term_start_date' => '2026-07-01',
            'enrollment_starts_at' => '2026-07-10 00:00:00',
            'enrollment_ends_at' => '2026-07-20 23:59:59',
        ]);

        $this->setCutover('shs', 'Q1 AY 2026-2027', CarbonImmutable::parse('2026-07-01T00:00:00+08:00'));
        $this->travelTo(CarbonImmutable::parse('2026-07-25 08:00:00', 'Asia/Manila'));

        $response = $this->postJson('/_testing/enrollment/edit', [
            'term_id' => $termId,
            'education_level' => 'shs',
        ]);

        $response->assertStatus(423);
        $response->assertSeeText('Enrollment edits are locked outside the enrollment window.');

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'calendar_gate',
            'event' => 'enrollment_edit_blocked',
            'subject_type' => 'term',
            'subject_id' => $termId,
        ]);
    }

    private function setCutover(string $educationLevel, string $effectiveTerm, CarbonImmutable $effectiveDatetime): void
    {
        $prefix = strtolower($educationLevel) === 'shs' ? 'shs' : 'college';

        DB::table('system_settings')
            ->where('key', "{$prefix}_cutover_effective_term")
            ->update([
                'value' => $effectiveTerm,
                'updated_at' => now(),
            ]);

        DB::table('system_settings')
            ->where('key', "{$prefix}_cutover_effective_datetime")
            ->update([
                'value' => $effectiveDatetime->toIso8601String(),
                'updated_at' => now(),
            ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createTerm(string $educationLevel, array $overrides = []): int
    {
        $academicYearId = DB::table('academic_years')->insertGetId([
            'academic_year' => 'AY '.Str::of(Str::uuid())->substr(0, 8),
            'education_level' => strtolower($educationLevel),
            'school_year_start_date' => '2026-06-01',
            'school_year_end_date' => '2027-05-31',
            'status' => 'active',
            'reference_note' => 'test fixture',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $defaultTermType = strtolower($educationLevel) === 'shs' ? 'quarter' : 'semester';
        $defaults = [
            'term_name' => 'Term '.Str::of(Str::uuid())->substr(0, 8),
            'term_type' => $defaultTermType,
            'is_active' => true,
            'term_start_date' => '2026-07-01',
            'term_end_date' => '2026-09-30',
            'class_start_date' => '2026-07-15',
            'class_end_date' => '2026-09-15',
            'scheduling_starts_at' => '2026-07-01 00:00:00',
            'enrollment_starts_at' => '2026-07-10 00:00:00',
            'enrollment_ends_at' => '2026-07-20 23:59:59',
            'late_enrollment_ends_at' => '2026-07-22 23:59:59',
            'payment_deadline' => '2026-07-25 23:59:59',
            'adjustment_ends_at' => '2026-07-20 23:59:59',
            'academic_year_id' => $academicYearId,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        return DB::table('terms')->insertGetId(array_merge($defaults, $overrides));
    }

    private function prepareTestSchema(): void
    {
        Schema::dropIfExists('activity_log');
        Schema::dropIfExists('terms');
        Schema::dropIfExists('academic_years');
        Schema::dropIfExists('system_settings');

        Schema::create('academic_years', function (Blueprint $table) {
            $table->id();
            $table->string('academic_year');
            $table->string('education_level');
            $table->date('school_year_start_date');
            $table->date('school_year_end_date');
            $table->string('status')->default('draft');
            $table->string('reference_note')->nullable();
            $table->timestamps();
        });

        Schema::create('terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_year_id')->nullable();
            $table->string('term_name');
            $table->string('term_type')->nullable();
            $table->boolean('is_active')->default(false);
            $table->date('term_start_date');
            $table->date('term_end_date');
            $table->date('class_start_date')->nullable();
            $table->date('class_end_date')->nullable();
            $table->timestamp('scheduling_starts_at')->nullable();
            $table->timestamp('enrollment_starts_at')->nullable();
            $table->timestamp('enrollment_ends_at')->nullable();
            $table->timestamp('late_enrollment_ends_at')->nullable();
            $table->timestamp('payment_deadline')->nullable();
            $table->timestamp('adjustment_ends_at')->nullable();
            $table->timestamps();
        });

        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::create('activity_log', function (Blueprint $table) {
            $table->id();
            $table->string('log_name')->nullable();
            $table->text('description');
            $table->string('subject_type')->nullable();
            $table->string('event')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('causer_type')->nullable();
            $table->unsignedBigInteger('causer_id')->nullable();
            $table->json('properties')->nullable();
            $table->char('batch_uuid', 36)->nullable();
            $table->timestamps();
        });
    }

    private function seedSystemSettings(): void
    {
        DB::table('system_settings')->insert([
            [
                'key' => 'maintenance_mode',
                'value' => 'false',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'maintenance_message',
                'value' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'maintenance_eta',
                'value' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'installment_policy_defaults',
                'value' => '{"version":"1.0"}',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'admission_requirements',
                'value' => '{"version":"1.0","items":[]}',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'shs_cutover_effective_term',
                'value' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'shs_cutover_effective_datetime',
                'value' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'college_cutover_effective_term',
                'value' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'college_cutover_effective_datetime',
                'value' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
