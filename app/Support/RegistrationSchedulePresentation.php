<?php

namespace App\Support;

use App\Actions\Enrollment\CurrentOfficialEnrollmentResolver;
use App\Models\CourseEnrollment;
use App\Models\Enrollment;
use App\Models\PublishedTimetableMeeting;
use App\Models\PublishedTimetableVersion;
use App\Models\RegistrationProposalVersion;
use App\Models\User;

class RegistrationSchedulePresentation
{
    /** @return array<string, mixed>|null */
    public function currentFor(User $student): ?array
    {
        $case = app(CurrentOfficialEnrollmentResolver::class)->forStudent($student);
        if ($case === null) {
            return null;
        }

        return $this->currentForEnrollment($case);
    }

    /** @return array<string, mixed>|null */
    public function currentForEnrollment(Enrollment $case): ?array
    {
        if ($case->canonical_outcome !== Enrollment::OutcomeOfficiallyEnrolled) {
            return null;
        }

        $version = PublishedTimetableVersion::query()->where('term_id', $case->term_id)
            ->where('state', PublishedTimetableVersion::StatePublished)->latest('version')->first();
        if ($version === null) {
            return null;
        }
        $courses = $case->courseEnrollments()->with(['termOffering.curriculumEntry.courseSpecification.course', 'section'])
            ->where('is_current', true)->where('status', CourseEnrollment::StatusActive)->get()->keyBy('section_id');
        $meetings = PublishedTimetableMeeting::query()->with(['classOffering.courseRevision.course', 'faculty', 'room'])
            ->where('published_timetable_version_id', $version->id)->whereIn('section_id', $courses->keys())->get();
        $coursesByMeeting = $meetings->mapWithKeys(fn (PublishedTimetableMeeting $meeting): array => [$meeting->id => $courses->get($meeting->section_id)]);
        $presentation = TimetablePresentation::forPublished($version, $meetings);
        $presentation['meetings'] = collect($presentation['meetings'])->map(function (array $meeting) use ($coursesByMeeting): array {
            $course = $coursesByMeeting->get($meeting['id']);
            $specification = $course?->termOffering?->curriculumEntry?->courseSpecification;
            $meeting['courseCode'] = $specification?->course?->code ?? $meeting['courseCode'];
            $meeting['courseTitle'] = $specification?->title ?? $meeting['courseTitle'];
            $meeting['class'] = $course?->section?->class_reference ?? $course?->section?->code ?? $meeting['class'];

            return $meeting;
        })->all();

        return $presentation;
    }

    /** @return array<string, mixed>|null */
    public function for(?RegistrationProposalVersion $proposal, ?Enrollment $case): ?array
    {
        $official = $case?->canonical_outcome === Enrollment::OutcomeOfficiallyEnrolled;
        $version = $official ? $case->currentCorVersion?->timetableVersion : $proposal?->timetableVersion;
        if ($version === null) {
            return null;
        }
        $meetings = PublishedTimetableMeeting::query()->where('published_timetable_version_id', $version->id);
        if ($official) {
            $sections = $case->courseEnrollments()->where('is_current', true)->where('status', CourseEnrollment::StatusActive)->pluck('section_id');
            $meetings->whereIn('section_id', $sections);
        } else {
            $ids = $proposal->items->flatMap(fn ($item) => collect($item->meeting_snapshot)->pluck('id'))->filter()->unique();
            $meetings->whereIn('id', $ids);
        }
        $presentation = TimetablePresentation::forPublished($version, $meetings->with(['classOffering.courseRevision.course', 'faculty', 'room'])->get());
        $courses = $official ? collect($case->currentCorVersion->snapshot['courses'] ?? [])
            : $proposal->items->map(fn ($item): array => ['course_code' => $item->course_code_snapshot, 'course_title' => $item->course_title_snapshot, 'section_code' => $item->section?->code, 'meetings' => $item->meeting_snapshot]);
        $captured = $courses->flatMap(fn (array $course) => collect($course['meetings'] ?? [])->map(fn (array $meeting): array => ['id' => $meeting['id'] ?? null, 'course' => $course, 'meeting' => $meeting]))->keyBy('id');
        $presentation['meetings'] = collect($presentation['meetings'])->map(function (array $meeting) use ($captured): array {
            $source = $captured->get($meeting['id']);
            if ($source !== null) {
                $meeting['courseCode'] = $source['course']['course_code'];
                $meeting['courseTitle'] = $source['course']['course_title'];
                $meeting['class'] = $source['course']['section_code'] ?? $meeting['class'];
                $meeting['faculty'] = $source['meeting']['faculty_name'] ?? $meeting['faculty'];
                $meeting['room'] = $source['meeting']['room_label'] ?? $meeting['room'];
            }

            return $meeting;
        })->all();
        $presentation['source'] = ($official ? 'Official enrollment' : 'Proposal v'.$proposal->version).' · Published timetable v'.$version->version;

        return $presentation;
    }
}
