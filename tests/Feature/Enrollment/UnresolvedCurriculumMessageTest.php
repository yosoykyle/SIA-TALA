<?php

namespace Tests\Feature\Enrollment;

use App\Actions\Enrollment\PrepareRegistrationProposal;
use App\Models\AcademicYear;
use App\Models\AdmissionApplication;
use App\Models\CurriculumVersion;
use App\Models\CurriculumVersionBatch;
use App\Models\Enrollment;
use App\Models\Program;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class UnresolvedCurriculumMessageTest extends TestCase
{
    use DatabaseTransactions;

    public function test_a_new_student_whose_entry_year_has_no_curriculum_batch_is_told_how_to_fix_it(): void
    {
        $program = Program::factory()->create(['name' => 'Diploma in Testing']);
        $earlier = AcademicYear::factory()->create(['label' => 'AY 2090-2091']);
        $entry = AcademicYear::factory()->create(['label' => 'AY 2091-2092']);
        $curriculum = CurriculumVersion::factory()->create(['program_id' => $program->id, 'state' => CurriculumVersion::StateActive]);
        CurriculumVersionBatch::query()->create([
            'program_id' => $program->id,
            'curriculum_version_id' => $curriculum->id,
            'academic_year_id' => $earlier->id,
            'added_by' => User::factory()->create()->id,
        ]);
        $term = Term::factory()->create(['academic_year_id' => $entry->id]);
        $application = AdmissionApplication::factory()->create(['program_id' => $program->id, 'term_id' => $term->id]);
        $enrollment = Enrollment::factory()->create(['student_profile_id' => null, 'admission_application_id' => $application->id, 'term_id' => $term->id, 'credential_user_id' => $application->user_id]);

        $message = (new \ReflectionMethod(PrepareRegistrationProposal::class, 'unresolvedCurriculumMessage'))
            ->invoke(app(PrepareRegistrationProposal::class), $enrollment->fresh(), 1);

        $this->assertSame('Diploma in Testing has no curriculum set for AY 2091-2092 entrants. Add a curriculum batch for that year in Programs & Curricula, then save the subjects again.', $message);
    }
}
