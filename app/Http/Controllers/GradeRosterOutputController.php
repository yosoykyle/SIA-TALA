<?php

namespace App\Http\Controllers;

use App\Models\ClassOfferingTeachingAssignment;
use App\Models\CourseEnrollment;
use App\Models\GradeRoster;
use App\Models\OutputAccessLog;
use App\Models\User;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GradeRosterOutputController extends Controller
{
    public function print(GradeRoster $roster): Response
    {
        $this->authorizeRoster($roster);
        $this->assertCurrentNonemptyMembership($roster);
        $roster->load(['rows' => fn ($query) => $query->where('is_current_membership', true)
            ->with('courseEnrollment.enrollment.studentProfile'), 'section', 'termOffering.term', 'termOffering.curriculumEntry.courseSpecification.course']);
        $roster->setRelation('rows', $roster->rows->sortBy([
            fn ($left, $right): int => strcasecmp((string) $left->courseEnrollment?->enrollment?->studentProfile?->last_name, (string) $right->courseEnrollment?->enrollment?->studentProfile?->last_name),
            fn ($left, $right): int => strcasecmp((string) $left->courseEnrollment?->enrollment?->studentProfile?->first_name, (string) $right->courseEnrollment?->enrollment?->studentProfile?->first_name),
        ])->values());
        $this->recordAccess($roster, 'print');

        return response()->view('outputs.class-roster', ['roster' => $roster, 'generatedAt' => now('Asia/Manila')]);
    }

    public function csv(GradeRoster $roster): StreamedResponse
    {
        $this->authorizeRoster($roster);
        $this->assertCurrentNonemptyMembership($roster);
        $roster->load(['rows' => fn ($query) => $query->where('is_current_membership', true)
            ->with('courseEnrollment.enrollment.studentProfile')]);
        $roster->setRelation('rows', $roster->rows->sortBy([
            fn ($left, $right): int => strcasecmp((string) $left->courseEnrollment?->enrollment?->studentProfile?->last_name, (string) $right->courseEnrollment?->enrollment?->studentProfile?->last_name),
            fn ($left, $right): int => strcasecmp((string) $left->courseEnrollment?->enrollment?->studentProfile?->first_name, (string) $right->courseEnrollment?->enrollment?->studentProfile?->first_name),
        ])->values());
        $this->recordAccess($roster, 'download');

        return response()->streamDownload(function () use ($roster): void {
            $stream = fopen('php://output', 'w');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, ['Student number', 'Last name', 'First name', 'Middle name'], ',', '"', '', "\r\n");

            foreach ($roster->rows as $row) {
                $student = $row->courseEnrollment?->enrollment?->studentProfile;
                fputcsv($stream, array_map($this->safeCsvCell(...), [
                    $student?->student_number,
                    $student?->last_name,
                    $student?->first_name,
                    $student?->middle_name,
                ]), ',', '"', '', "\r\n");
            }

            fclose($stream);
        }, "class-roster-{$roster->id}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function authorizeRoster(GradeRoster $roster): void
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        if ($user->hasAnyRole([User::StaffRoleRegistrar, User::StaffRoleAcademicHead])) {
            return;
        }

        $hasAssignment = ClassOfferingTeachingAssignment::query()
            ->where('section_id', $roster->section_id)
            ->where('faculty_user_id', $user->id)
            ->where('state', ClassOfferingTeachingAssignment::StateActive)
            ->exists();

        abort_unless($user->hasRole(User::StaffRoleFaculty) && $hasAssignment, 403);
    }

    private function recordAccess(GradeRoster $roster, string $action): void
    {
        $user = auth()->user();
        OutputAccessLog::query()->create([
            'output_type' => 'Class Roster',
            'source_record_type' => GradeRoster::class,
            'source_record_id' => $roster->id,
            'actor_user_id' => $user?->id,
            'actor_role' => $user?->getRoleNames()->first(),
            'action' => $action,
            'copy_context' => 'current official membership',
            'row_count' => $roster->rows()->where('is_current_membership', true)->count(),
            'purpose' => 'Authorized teaching and Registrar roster operations.',
            'sensitivity' => 'restricted_academic_roster',
            'status' => 'generated',
            'occurred_at' => now(),
        ]);
    }

    private function assertCurrentNonemptyMembership(GradeRoster $roster): void
    {
        $officialIds = CourseEnrollment::query()
            ->where('term_offering_id', $roster->term_offering_id)
            ->where('status', CourseEnrollment::StatusActive)
            ->where('is_current', true)
            ->where(function ($query) use ($roster): void {
                $query->where('section_id', $roster->section_id)
                    ->orWhereHas('seatReservations', fn ($reservationQuery) => $reservationQuery
                        ->where('section_id', $roster->section_id)
                        ->where('status', 'ACTIVE'));
            })
            ->orderBy('id')
            ->pluck('id');
        $rosterIds = $roster->rows()->where('is_current_membership', true)->orderBy('course_enrollment_id')->pluck('course_enrollment_id');

        abort_if($officialIds->isEmpty(), 409, 'No officially enrolled students are currently assigned to this class.');
        abort_unless($officialIds->all() === $rosterIds->all(), 409, 'The class roster changed. Refresh the roster before generating an output.');
    }

    private function safeCsvCell(mixed $value): string
    {
        $value = (string) ($value ?? '');

        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'{$value}" : $value;
    }
}
