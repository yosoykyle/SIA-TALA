<?php

namespace Tests\Feature;

use App\Actions\SystemAdministration\CanonicalTalaSchedulingDataset;
use App\Actions\SystemAdministration\TAL96D5E1ExplorationPersonaCatalog;
use App\Models\CurriculumEntry;
use App\Models\Room;
use App\Models\SchedulingDemand;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[Group('acceptance-fixture')]
final class TAL96D5E1D6D1PresentationFixtureTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
        $this->artisan('acceptance:seed-client-baseline')->assertSuccessful();
        $this->artisan('acceptance:seed-tal96d5e1-exploration')->assertSuccessful();
    }

    #[Test]
    public function presentation_fixture_preserves_the_client_min_population_and_resources(): void
    {
        $expectedCohorts = [
            'DBM-1A' => 10,
            'DBM-2A' => 2,
            'DIT-1A' => 10,
            'DIT-2A' => 3,
            'DTHM-1A' => 15,
            'DTHM-2A' => 7,
        ];

        foreach ($expectedCohorts as $cohortCode => $students) {
            $this->assertSame($students, StudentProfile::query()
                ->where('student_number', 'like', $cohortCode.'-%')
                ->count());
        }

        $this->assertSame(47, array_sum($expectedCohorts));
        $this->assertSame(49, StudentProfile::query()->count());
        $this->assertSame(2, StudentProfile::query()
            ->whereIn('student_number', ['DTHM-3A-001', 'DIT-3A-001'])
            ->where('lifecycle_status', StudentProfile::LifecycleInactive)
            ->count());
        $this->assertSame(9, User::role(User::StaffRoleFaculty)->count());
        $this->assertSame(10, Room::query()->count());

        $term = $this->presentationTerm();

        $this->assertSame(54, TermOffering::query()->whereBelongsTo($term)->count());
        $this->assertSame(54, SchedulingDemand::query()
            ->whereHas('termOffering', fn ($query) => $query->whereBelongsTo($term))
            ->count());
        $this->assertFalse(TermOffering::query()
            ->whereBelongsTo($term)
            ->whereHas('curriculumEntry', fn ($query) => $query->where('year_level', 'Third Year'))
            ->exists());
        $this->assertEqualsCanonicalizing(
            ['First Year', 'Second Year', 'Third Year'],
            CurriculumEntry::query()->distinct()->pluck('year_level')->all(),
        );
        $this->assertSame(158, CurriculumEntry::query()->count());

        $manifest = app(CanonicalTalaSchedulingDataset::class)->manifest();

        $this->assertSame([
            'students' => 47,
            'cohorts' => 6,
            'faculty' => 9,
            'offerings' => 54,
            'sections' => 54,
            'scheduling_demands' => 54,
        ], $manifest['counts']);
    }

    #[Test]
    public function presentation_accounts_use_fictional_filipino_sounding_names_and_expected_roles(): void
    {
        $staff = [
            'applicant.demo@example.test' => ['Andrea', 'Marquez', 'applicant'],
            'registrar.demo@example.test' => ['Maribel', 'Dizon', User::StaffRoleRegistrar],
            'accounting.demo@example.test' => ['Renato', 'Salcedo', User::StaffRoleAccounting],
            'faculty.demo@example.test' => ['Teresa', 'Villanueva', User::StaffRoleFaculty],
            'academic-head.demo@example.test' => ['Lourdes', 'Alvarado', User::StaffRoleAcademicHead],
            'system-admin.demo@example.test' => ['Miguel', 'Serrano', User::StaffRoleSystemSuperAdmin],
        ];

        foreach ($staff as $email => [$firstName, $lastName, $role]) {
            $user = User::query()->where('email', $email)->sole();

            $this->assertSame($firstName, $user->first_name);
            $this->assertSame($lastName, $user->last_name);
            $this->assertTrue($user->hasRole($role));
        }

        $currentStudents = StudentProfile::query()
            ->whereNotIn('student_number', ['DTHM-3A-001', 'DIT-3A-001'])
            ->with('user')
            ->orderBy('student_number')
            ->get();

        $this->assertCount(47, $currentStudents);
        $this->assertSame(47, $currentStudents
            ->map(fn (StudentProfile $profile): string => $profile->first_name.' '.$profile->last_name)
            ->unique()
            ->count());

        foreach ($currentStudents as $profile) {
            $this->assertDoesNotMatchRegularExpression(
                '/\b(?:Tala Student|Student \d+|Faculty \d+|Demo|Synthetic)\b/i',
                $profile->first_name.' '.$profile->last_name,
            );
            $this->assertSame($profile->first_name, $profile->user->first_name);
            $this->assertSame($profile->last_name, $profile->user->last_name);
        }
    }

    #[Test]
    public function presentation_case_catalog_is_complete_and_uses_plain_fixture_copy(): void
    {
        $report = app(TAL96D5E1ExplorationPersonaCatalog::class)->report();

        $this->assertSame('PASS', $report['coverage_state']);
        $this->assertSame(28, $report['personas']);
        $this->assertSame(49, $report['student_profiles']);
        $this->assertSame(47, $report['current_students']);
        $this->assertSame(2, $report['historical_case_profiles']);
        $this->assertTrue($report['presentation_fixture_ready']);

        $visibleFixtureText = collect([
            ...DB::table('fee_rules')->pluck('name'),
            ...DB::table('fee_rules')->pluck('authority'),
            ...DB::table('rooms')->pluck('building'),
            ...DB::table('rooms')->pluck('notes'),
            ...DB::table('enrollments')->pluck('status_reason')->filter(),
            ...DB::table('holds')->pluck('reason')->filter(),
            ...DB::table('accounting_adjustments')->pluck('reason')->filter(),
            ...DB::table('curriculum_versions')->pluck('approval_reference')->filter(),
        ])->implode("\n");

        $this->assertDoesNotMatchRegularExpression(
            '/(?:TAL-?96|MIDDLE|test_tala_db|synthetic|acceptance fixture)/i',
            $visibleFixtureText,
        );
    }

    private function presentationTerm(): Term
    {
        return Term::query()
            ->where('label', 'Second Semester')
            ->whereHas('academicYear', fn ($query) => $query->where('label', 'AY 2025-2026'))
            ->sole();
    }
}
