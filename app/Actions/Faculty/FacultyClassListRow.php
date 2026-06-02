<?php

namespace App\Actions\Faculty;

final readonly class FacultyClassListRow
{
    public function __construct(
        public int $enrollmentId,
        public int $studentProfileId,
        public string $studentId,
        public string $studentName,
        public int $sectionId,
        public int $subjectId,
        public int $termId,
        public ?string $yearLevel,
        public ?string $modality,
        public string $enrollmentStatus,
        public string $financeStatus,
    ) {}

    /**
     * @return array{
     *     enrollment_id:int,
     *     student_profile_id:int,
     *     student_id:string,
     *     student_name:string,
     *     section_id:int,
     *     subject_id:int,
     *     term_id:int,
     *     year_level:string|null,
     *     modality:string|null,
     *     enrollment_status:string,
     *     finance_status:string
     * }
     */
    public function toArray(): array
    {
        return [
            'enrollment_id' => $this->enrollmentId,
            'student_profile_id' => $this->studentProfileId,
            'student_id' => $this->studentId,
            'student_name' => $this->studentName,
            'section_id' => $this->sectionId,
            'subject_id' => $this->subjectId,
            'term_id' => $this->termId,
            'year_level' => $this->yearLevel,
            'modality' => $this->modality,
            'enrollment_status' => $this->enrollmentStatus,
            'finance_status' => $this->financeStatus,
        ];
    }
}
