<?php

namespace Tests\Feature\Enrollment;

use App\Actions\Enrollment\RegistrationClassChoices;
use App\Actions\Enrollment\ReturnIrregularEnrollmentRequest;
use App\Actions\Enrollment\SubmitIrregularEnrollmentRequest;
use App\Models\CourseRequirement;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\GradeRosterRow;
use App\Models\IrregularEnrollmentRequest;
use App\Models\Program;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\BuildsIrregularStudents;
use Tests\TestCase;

class IrregularEnrollmentRequestTest extends TestCase
{
    use BuildsIrregularStudents;
    use DatabaseTransactions;

    private User $registrar;

    private User $faculty;

    private CurriculumVersion $curriculum;

    private StudentProfile $student;

    private Enrollment $case;

    /** @var array<string, Section> */
    private array $sections = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['student', User::StaffRoleRegistrar, User::StaffRoleFaculty] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
        $this->registrar = $this->staff(User::StaffRoleRegistrar);
        $this->faculty = $this->staff(User::StaffRoleFaculty);
        $this->curriculum = CurriculumVersion::factory()->create(['program_id' => Program::factory()->create()->id]);

        $this->student = $this->student();
        $failedTerm = $this->term(now()->subMonths(5), now()->subMonths(1));
        $dataStructures = $this->entry('IT-101A', 'Data Structures');
        $advanced = $this->entry('IT-101B', 'Advanced Data Structures');
        $ethics = $this->entry('GE-105', 'Ethics');
        CourseRequirement::factory()->create([
            'course_specification_id' => $advanced->course_specification_id,
            'related_course_id' => $dataStructures->courseSpecification->course_id,
            'rule_type' => CourseRequirement::TypePrerequisite,
            'group_key' => 'g1',
            'state' => CourseRequirement::StateActive,
        ]);
        $this->attempt($this->student, $failedTerm, $dataStructures, '5.00', GradeRosterRow::CategoryFailed);

        $nextTerm = $this->term(now()->subDays(5), now()->addMonths(4), Term::TypeSecondSemester, Term::StateActive);
        $this->case = Enrollment::factory()->create([
            'credential_user_id' => $this->student->user_id,
            'student_profile_id' => $this->student->id,
            'term_id' => $nextTerm->id,
            'canonical_outcome' => Enrollment::OutcomeInProgress,
            'selection_basis' => Enrollment::SelectionIndividuallyAdvised,
            'officially_enrolled_at' => null,
            'current_proposal_version_id' => null,
        ]);
        $this->sections = [
            'retake' => $this->section($nextTerm, $dataStructures, 'IT-101A-A'),
            'retakeFull' => $this->section($nextTerm, $dataStructures, 'IT-101A-B'),
            'locked' => $this->section($nextTerm, $advanced, 'IT-101B-A'),
            'ethics' => $this->section($nextTerm, $ethics, 'GE-105-A'),
        ];
        $this->app->instance(RegistrationClassChoices::class, new FakeIrregularClassChoices(
            collect($this->sections),
            [$this->sections['locked']->id => 'Advanced Data Structures · IT-101B · IT-101B-A · Prereq unmet'],
            [$this->sections['retakeFull']->id],
        ));
    }

    public function test_student_submits_with_the_mandatory_retake_and_registrar_proposal_accepts_it(): void
    {
        $request = app(SubmitIrregularEnrollmentRequest::class)->execute(
            $this->case,
            $this->student->user,
            [$this->sections['retake']->id, $this->sections['ethics']->id],
            'I will retake Data Structures.',
        );

        $this->assertSame(IrregularEnrollmentRequest::StateSubmitted, $request->state);
        $this->assertSame([$this->sections['retake']->id, $this->sections['ethics']->id], $request->sectionIds());
        $this->assertSame('6.00', (string) $request->selected_units);
        $this->assertSame('failed', $request->reasons[0]['code']);

        $this->expectValidationMessage(fn () => app(SubmitIrregularEnrollmentRequest::class)->execute($this->case, $this->student->user, [$this->sections['ethics']->id], null), 'already with the Registrar');
    }

    public function test_request_rules_reject_missing_retake_full_and_locked_classes(): void
    {
        $submit = fn (array $ids) => app(SubmitIrregularEnrollmentRequest::class)->execute($this->case, $this->student->user, $ids, null);

        $this->expectValidationMessage(fn () => $submit([$this->sections['ethics']->id]), 'You must retake IT-101A');
        $this->expectValidationMessage(fn () => $submit([$this->sections['retakeFull']->id, $this->sections['ethics']->id]), 'is full');
        $this->expectValidationMessage(fn () => $submit([$this->sections['retake']->id, $this->sections['locked']->id]), 'is locked for you: Prereq unmet');
        $this->expectValidationMessage(fn () => $submit([$this->sections['retake']->id, $this->sections['retakeFull']->id]), 'Choose only one class for IT-101A');
        $this->assertSame(0, IrregularEnrollmentRequest::query()->count());
    }

    public function test_registrar_returns_for_correction_and_student_resubmits(): void
    {
        $request = app(SubmitIrregularEnrollmentRequest::class)->execute($this->case, $this->student->user, [$this->sections['retake']->id], null);

        $returned = app(ReturnIrregularEnrollmentRequest::class)->execute($request, $this->registrar, 'Add GE-105 to reach a full load.');
        $this->assertSame(IrregularEnrollmentRequest::StateReturned, $returned->state);
        $this->assertSame('Add GE-105 to reach a full load.', $returned->return_reason);

        $resubmitted = app(SubmitIrregularEnrollmentRequest::class)->execute($this->case, $this->student->user, [$this->sections['retake']->id, $this->sections['ethics']->id], null);
        $this->assertSame($request->id, $resubmitted->id);
        $this->assertSame(IrregularEnrollmentRequest::StateSubmitted, $resubmitted->state);
        $this->assertNull($resubmitted->return_reason);

        $this->expectValidationMessage(fn () => app(ReturnIrregularEnrollmentRequest::class)->execute($resubmitted, $this->student->user, 'Nope'), 'Only the Registrar');
    }

    private function section(Term $term, CurriculumEntry $entry, string $code): Section
    {
        $offering = TermOffering::query()->where('term_id', $term->id)->where('curriculum_entry_id', $entry->id)->first()
            ?? TermOffering::factory()->create([
                'term_id' => $term->id,
                'curriculum_entry_id' => $entry->id,
                'state' => TermOffering::StateScheduled,
            ]);

        return Section::factory()->create([
            'term_offering_id' => $offering->id,
            'code' => $code,
            'state' => Section::StateOpen,
            'capacity' => 30,
        ])->load('termOffering.curriculumEntry.courseSpecification.course');
    }

    private function expectValidationMessage(callable $callback, string $message): void
    {
        try {
            $callback();
            $this->fail('Expected a validation error containing: '.$message);
        } catch (ValidationException $exception) {
            $this->assertStringContainsString($message, collect($exception->errors())->flatten()->implode(' '));
        }
    }
}

/**
 * Stands in for the published-timetable class list so the request rules can be tested directly.
 */
class FakeIrregularClassChoices extends RegistrationClassChoices
{
    /**
     * @param  Collection<string, Section>  $sections
     * @param  array<int, string>  $labels
     * @param  list<int>  $fullSectionIds
     */
    public function __construct(private Collection $sections, private array $labels, private array $fullSectionIds) {}

    public function forCase(Enrollment $case): array
    {
        return ['sections' => $this->sections->values(), 'meetings' => collect(), 'reserved' => collect(), 'enrolled' => collect(), 'satisfiedCourseIds' => collect()];
    }

    public function sectionOptions(Enrollment $case, ?array $selectedSectionIds = null, ?array $choices = null): array
    {
        return $this->sections->mapWithKeys(fn (Section $section): array => [$section->id => $this->labels[$section->id] ?? $section->code])->all();
    }

    public function seats(Enrollment $case, Section $section): array
    {
        $full = in_array($section->id, $this->fullSectionIds, true);

        return ['capacity' => 30, 'used' => $full ? 30 : 2, 'remaining' => $full ? 0 : 28];
    }
}
