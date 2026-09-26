<?php

if (getenv('DB_DATABASE') && getenv('DB_DATABASE') !== 'test_tala_db') {
    throw new RuntimeException("Refusing write: DB_DATABASE environment variable must be 'test_tala_db', got '".getenv('DB_DATABASE')."'.");
}

$targetDb = 'test_tala_db';
putenv("DB_DATABASE={$targetDb}");
$_ENV['DB_DATABASE'] = $targetDb;
$_SERVER['DB_DATABASE'] = $targetDb;

use Illuminate\Contracts\Console\Kernel;

if (! defined('LARAVEL_START')) {
    define('LARAVEL_START', microtime(true));
    require_once __DIR__.'/../../vendor/autoload.php';
    $app = require_once __DIR__.'/../../bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
}

use Illuminate\Support\Facades\DB;
use Tests\Browser\BrowserQualificationEnvironment;

BrowserQualificationEnvironment::assertValidDatabase();
if (DB::connection()->getDatabaseName() !== 'test_tala_db') {
    throw new RuntimeException("Refusing write: active database connection is '".DB::connection()->getDatabaseName()."', expected 'test_tala_db'.");
}

use App\Models\AcademicYear;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCalendarWindow;
use App\Models\User;

$registrar = User::where('email', 'registrar.test@example.test')->firstOrFail();
$ay = AcademicYear::where('label', 'Academic Year 2026-2027')->firstOrFail();

// Clean up any previous test terms for Issue 50
BrowserQualificationEnvironment::assertValidDatabase();
if (DB::connection()->getDatabaseName() !== 'test_tala_db') {
    throw new RuntimeException("Refusing write: active database connection is '".DB::connection()->getDatabaseName()."', expected 'test_tala_db'.");
}

foreach (Term::whereIn('label', ['Issue 50 Qualification Term', 'Single Draft Preselection Term'])->get() as $existingTerm) {
    foreach ($existingTerm->calendarPackages as $pkg) {
        $pkg->windows()->delete();
        $pkg->teachingGridRows()->delete();
        $pkg->datedExceptions()->delete();
        $pkg->delete();
    }
    $existingTerm->delete();
}

BrowserQualificationEnvironment::assertValidDatabase();
if (DB::connection()->getDatabaseName() !== 'test_tala_db') {
    throw new RuntimeException("Refusing write: active database connection is '".DB::connection()->getDatabaseName()."', expected 'test_tala_db'.");
}
$remainingPrevious = Term::whereIn('label', ['Issue 50 Qualification Term', 'Single Draft Preselection Term'])->count();
if ($remainingPrevious > 0) {
    throw new RuntimeException("Initial cleanup failed: {$remainingPrevious} previous terms could not be deleted.");
}

$term = Term::create([
    'academic_year_id' => $ay->id,
    'type' => 'SECOND_SEMESTER',
    'label' => 'Issue 50 Qualification Term',
    'starts_on' => '2026-11-01',
    'ends_on' => '2027-04-30',
    'state' => Term::StateDraft,
    'scheduling_slot_minutes' => 30,
    'scheduling_days' => ['MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT'],
    'scheduling_day_starts_at' => '07:00:00',
    'scheduling_day_ends_at' => '21:00:00',
    'default_max_units' => 24,
]);

// Draft 1: Unready package (missing enrollment window and teaching grid)
$draftUnready = TermCalendarPackage::create([
    'term_id' => $term->id,
    'version' => 1,
    'state' => TermCalendarPackage::StateDraft,
    'authority_reference' => 'BOR-UNREADY-001',
    'authority_date' => '2026-10-01',
    'administrative_starts_on' => '2026-10-15',
    'administrative_ends_on' => '2027-05-15',
    'classes_start_on' => '2026-11-03',
    'classes_end_on' => '2027-04-10',
    'faculty_availability_due_at' => '2026-10-25 17:00:00',
    'recorded_by' => $registrar->id,
]);

// Draft 2: Fully ready package
$draftReady = TermCalendarPackage::create([
    'term_id' => $term->id,
    'version' => 2,
    'state' => TermCalendarPackage::StateDraft,
    'authority_reference' => 'BOR-APPROVED-002',
    'authority_date' => '2026-10-10',
    'administrative_starts_on' => '2026-10-15',
    'administrative_ends_on' => '2027-05-15',
    'classes_start_on' => '2026-11-03',
    'classes_end_on' => '2027-04-10',
    'faculty_availability_due_at' => '2026-10-25 17:00:00',
    'recorded_by' => $registrar->id,
]);

foreach ([
    TermCalendarWindow::TypeEnrollment => ['2026-10-16', '2026-10-31'],
    TermCalendarWindow::TypeLateEnrollment => ['2026-11-01', '2026-11-07'],
    TermCalendarWindow::TypeExaminationPeriod => ['2027-03-25', '2027-04-05'],
    TermCalendarWindow::TypeGradeEntry => ['2027-04-06', '2027-04-20'],
] as $type => [$open, $close]) {
    $draftReady->windows()->create([
        'window_type' => $type,
        'opens_on' => $open,
        'closes_on' => $close,
        'cutoff_at' => '17:00:00',
    ]);
}

$draftReady->teachingGridRows()->create([
    'day_of_week' => 1,
    'starts_at' => '08:00:00',
    'ends_at' => '17:00:00',
    'breaks' => [
        ['starts_at' => '12:00:00', 'ends_at' => '13:00:00'],
    ],
]);

echo json_encode([
    'term_id' => $term->id,
    'term_label' => $term->label,
    'unready_package_id' => $draftUnready->id,
    'ready_package_id' => $draftReady->id,
], JSON_PRETTY_PRINT).PHP_EOL;
