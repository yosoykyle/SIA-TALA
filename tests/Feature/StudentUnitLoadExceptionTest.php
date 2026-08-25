<?php

namespace Tests\Feature;

use App\Actions\Enrollment\RecordGraduatingOverloadAuthority;
use App\Actions\Enrollment\StudentUnitLoadService;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\Enrollment;
use App\Models\EnrollmentException;
use App\Models\RegistrationProposalItem;
use App\Models\RegistrationProposalVersion;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class StudentUnitLoadExceptionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
        foreach ([User::StaffRoleRegistrar, User::StaffRoleAcademicHead] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    #[Test]
    public function exact_curriculum_position_is_snapshotted_without_a_global_or_term_fallback(): void
    {
        $profile = StudentProfile::factory()->create([
            'academic_standing' => StudentProfile::StandingGraduationCandidate,
        ]);
        $term = Term::factory()->create([
            'type' => Term::TypeSpecialTerm,
            'default_max_units' => 99,
        ]);
        $enrollment = Enrollment::factory()->create([
            'student_profile_id' => $profile->id,
            'term_id' => $term->id,
            'selection_basis' => Enrollment::SelectionIndividuallyAdvised,
        ]);
        $plannedEntry = $this->curriculumEntry($profile, 'Third Year', 'Special Term', Term::TypeSpecialTerm, 3);
        $otherPlannedEntry = $this->curriculumEntry($profile, 'Third Year', 'Special Term', Term::TypeSpecialTerm, 3);
        $retakeEntry = $this->curriculumEntry($profile, 'Second Year', 'First Semester', Term::TypeFirstSemester, 3);
        $planned = $this->section($term, $plannedEntry, TermOffering::CategoryRegular);
        $retake = $this->section($term, $retakeEntry, TermOffering::CategorySpecial);

        $snapshot = app(StudentUnitLoadService::class)->snapshotForSections(
            $enrollment,
            collect([$planned, $retake]),
        );

        $this->assertSame('Third Year', $snapshot['year_level']);
        $this->assertSame('Special Term', $snapshot['term_label']);
        $this->assertSame(Term::TypeSpecialTerm, $snapshot['term_type']);
        $this->assertSame([$plannedEntry->id, $otherPlannedEntry->id], $snapshot['curriculum_entry_ids']);
        $this->assertSame('6.00', $snapshot['normal_total']);
        $this->assertSame('6.00', $snapshot['requested_total']);
        $this->assertFalse($snapshot['requires_graduating_overload']);
        $this->assertNotSame('99.00', $snapshot['normal_total']);
    }

    #[Test]
    public function missing_or_ambiguous_curriculum_position_fails_without_any_fallback_quantity(): void
    {
        $profile = StudentProfile::factory()->create();
        $term = Term::factory()->create([
            'type' => Term::TypeSpecialTerm,
            'default_max_units' => 99,
        ]);
        $enrollment = Enrollment::factory()->create([
            'student_profile_id' => $profile->id,
            'term_id' => $term->id,
            'selection_basis' => Enrollment::SelectionIndividuallyAdvised,
        ]);
        $first = $this->section(
            $term,
            $this->curriculumEntry($profile, 'First Year', 'First Semester', Term::TypeFirstSemester, 3),
            TermOffering::CategorySpecial,
        );
        $second = $this->section(
            $term,
            $this->curriculumEntry($profile, 'Second Year', 'Second Semester', Term::TypeSecondSemester, 3),
            TermOffering::CategorySpecial,
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Curriculum term load unavailable');

        app(StudentUnitLoadService::class)->snapshotForSections($enrollment, collect([$first, $second]));
    }

    #[Test]
    public function only_a_matching_registrar_recorded_graduating_overload_authority_permits_the_draft(): void
    {
        [$enrollment, $proposal] = $this->overloadProposal();

        $legacy = EnrollmentException::factory()->create([
            'enrollment_id' => $enrollment->id,
            'student_profile_id' => $enrollment->student_profile_id,
            'term_id' => $enrollment->term_id,
            'exception_type' => EnrollmentException::TypeUnitLoad,
            'scope_key' => 'unit_load:'.$enrollment->term_id,
            'approved_values' => ['approved_excess' => 99],
        ]);
        $this->assertSame(EnrollmentException::TypeUnitLoad, $legacy->exception_type);

        try {
            app(StudentUnitLoadService::class)->assertProposalPermitted($enrollment, $proposal);
            $this->fail('Legacy UNIT_LOAD evidence must not authorize a current proposal.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('graduating-overload authority', $exception->getMessage());
        }

        $academicHead = User::factory()->create(['status' => User::StatusActive]);
        $academicHead->assignRole(User::StaffRoleAcademicHead);
        $this->expectException(AuthorizationException::class);

        app(RecordGraduatingOverloadAuthority::class)->execute($proposal, $academicHead, [
            'authority_reference' => 'EXT-GRAD-2026-001',
            'authority_date' => '2026-08-25',
            'evidence_reference' => 'EVID-GRAD-2026-001',
            'reason' => 'Externally approved graduating overload for the exact proposal.',
        ]);
    }

    #[Test]
    public function matching_authority_is_proposal_specific_and_does_not_bypass_other_readiness(): void
    {
        [$enrollment, $proposal] = $this->overloadProposal();
        $registrar = User::factory()->create(['status' => User::StatusActive]);
        $registrar->assignRole(User::StaffRoleRegistrar);

        $authority = app(RecordGraduatingOverloadAuthority::class)->execute($proposal, $registrar, [
            'authority_reference' => 'EXT-GRAD-2026-001',
            'authority_date' => '2026-08-25',
            'evidence_reference' => 'EVID-GRAD-2026-001',
            'reason' => 'Externally approved graduating overload for the exact proposal.',
        ]);

        $this->assertSame(EnrollmentException::TypeGraduatingOverload, $authority->exception_type);
        $this->assertSame($proposal->content_hash, data_get($authority->approved_values, 'proposal_content_hash'));
        $this->assertSame(User::StaffRoleRegistrar, data_get($authority->approved_values, 'recorded_by_role'));
        $this->assertTrue(app(StudentUnitLoadService::class)->assertProposalPermitted($enrollment, $proposal)['unit_load_passes']);

        $successor = $proposal->replicate(['state', 'issued_by', 'issued_at']);
        $successor->version = 2;
        $successor->supersedes_version_id = $proposal->id;
        $successor->state = RegistrationProposalVersion::StateDraft;
        $successor->content_hash = hash('sha256', 'successor-proposal');
        $successor->save();
        $enrollment->update(['current_proposal_version_id' => $successor->id]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('graduating-overload authority');

        app(StudentUnitLoadService::class)->assertProposalPermitted($enrollment->fresh(), $successor);
    }

    #[Test]
    public function incomplete_non_graduating_and_cross_term_authority_attempts_are_rejected(): void
    {
        [$enrollment, $proposal] = $this->overloadProposal();
        $registrar = User::factory()->create(['status' => User::StatusActive]);
        $registrar->assignRole(User::StaffRoleRegistrar);

        try {
            app(RecordGraduatingOverloadAuthority::class)->execute($proposal, $registrar, [
                'authority_reference' => '',
                'authority_date' => '2026-08-25',
                'evidence_reference' => '',
                'reason' => '',
            ]);
            $this->fail('Incomplete external authority evidence must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('authority_reference', $exception->errors());
            $this->assertArrayHasKey('evidence_reference', $exception->errors());
            $this->assertArrayHasKey('reason', $exception->errors());
        }

        $enrollment->studentProfile->update(['academic_standing' => StudentProfile::StandingRegular]);
        try {
            app(RecordGraduatingOverloadAuthority::class)->execute($proposal, $registrar, [
                'authority_reference' => 'EXT-GRAD-2026-001',
                'authority_date' => '2026-08-25',
                'evidence_reference' => 'EVID-GRAD-2026-001',
                'reason' => 'Externally approved graduating overload for the exact proposal.',
            ]);
            $this->fail('A non-graduating Student cannot consume graduating-overload authority.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Graduation Candidate', $exception->getMessage());
        }

        $otherTerm = Term::factory()->create(['type' => Term::TypeSpecialTerm]);
        $crossTermSection = $this->section(
            $otherTerm,
            $proposal->items->first()->termOffering->curriculumEntry,
            TermOffering::CategoryRegular,
        );
        try {
            app(StudentUnitLoadService::class)->snapshotForSections($enrollment, collect([$crossTermSection]));
            $this->fail('A cross-Term class cannot enter this Registration Case load snapshot.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('exact Term', $exception->getMessage());
        }
    }

    /** @return array{Enrollment, RegistrationProposalVersion} */
    private function overloadProposal(): array
    {
        $profile = StudentProfile::factory()->create([
            'academic_standing' => StudentProfile::StandingGraduationCandidate,
        ]);
        $term = Term::factory()->create(['type' => Term::TypeSpecialTerm]);
        $enrollment = Enrollment::factory()->create([
            'student_profile_id' => $profile->id,
            'term_id' => $term->id,
            'selection_basis' => Enrollment::SelectionIndividuallyAdvised,
        ]);
        $plannedEntry = $this->curriculumEntry($profile, 'Third Year', 'Special Term', Term::TypeSpecialTerm, 3);
        $retakeEntry = $this->curriculumEntry($profile, 'Second Year', 'First Semester', Term::TypeFirstSemester, 3);
        $planned = $this->section($term, $plannedEntry, TermOffering::CategoryRegular);
        $retake = $this->section($term, $retakeEntry, TermOffering::CategorySpecial);
        $snapshot = app(StudentUnitLoadService::class)->snapshotForSections($enrollment, collect([$planned, $retake]));

        return [$enrollment, $this->proposal($enrollment, $snapshot, [$planned, $retake])];
    }

    private function curriculumEntry(
        StudentProfile $profile,
        string $yearLevel,
        string $termLabel,
        string $termType,
        float $units,
    ): CurriculumEntry {
        return CurriculumEntry::factory()->create([
            'curriculum_version_id' => $profile->curriculum_version_id,
            'course_specification_id' => CourseSpecification::factory()->create(['credit_units' => $units])->id,
            'year_level' => $yearLevel,
            'term_label' => $termLabel,
            'term_type' => $termType,
        ]);
    }

    private function section(Term $term, CurriculumEntry $entry, string $category): Section
    {
        $offering = TermOffering::factory()->create([
            'term_id' => $term->id,
            'curriculum_entry_id' => $entry->id,
            'category' => $category,
            'state' => TermOffering::StateScheduled,
        ]);

        return Section::factory()->create([
            'term_offering_id' => $offering->id,
            'state' => Section::StateOpen,
        ]);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  list<Section>  $sections
     */
    private function proposal(Enrollment $enrollment, array $snapshot, array $sections): RegistrationProposalVersion
    {
        $proposal = RegistrationProposalVersion::factory()->create([
            'enrollment_id' => $enrollment->id,
            'curriculum_version_id' => $enrollment->studentProfile->curriculum_version_id,
            'selection_basis' => Enrollment::SelectionIndividuallyAdvised,
            'source_snapshot' => ['unit_load' => $snapshot],
            'content_hash' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)),
        ]);

        foreach ($sections as $sequence => $section) {
            RegistrationProposalItem::factory()->create([
                'registration_proposal_version_id' => $proposal->id,
                'sequence' => $sequence + 1,
                'term_offering_id' => $section->term_offering_id,
                'section_id' => $section->id,
                'units_snapshot' => $section->termOffering->courseSpecification()?->credit_units,
            ]);
        }

        $enrollment->update(['current_proposal_version_id' => $proposal->id]);

        return $proposal->load('items');
    }
}
