<?php

namespace App\Actions\StudentProfiles;

use App\Actions\AcademicSetup\CurriculumSpreadsheetExportService;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\Program;
use App\Models\StudentProfile;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class OngoingStudentSpreadsheetExportService
{
    /**
     * Lean cohort-scoped roster headers for Sheet 1 (`Students`) and CSV templates.
     * Core 5 columns first, followed by optional columns.
     *
     * @return list<string>
     */
    public static function studentsHeaders(): array
    {
        return [
            'Student Number*',
            'First Name*',
            'Middle Name',
            'Last Name*',
            'Email*',
            'Academic Standing',
            'Lifecycle Status',
            'Financial Hold Note',
            'LRN',
            'Birth Date',
            'Phone',
        ];
    }

    /**
     * Generate a lean 2-sheet `.xlsx` (`Students`, `Reference Codes`) or 1-sheet `.csv` cohort roster template.
     */
    public function exportTemplate(string $format = 'xlsx'): string
    {
        $ext = $this->normalizeFormat($format);
        $path = $this->prepareExportPath('cohort-roster-import-template.'.$ext);

        $studentRows = [
            self::studentsHeaders(),
            [
                self::neutralize('SIA-2023-0045'),
                self::neutralize('Juan'),
                self::neutralize('Santos'),
                self::neutralize('Dela Cruz'),
                self::neutralize('juan.delacruz@servitech.edu.ph'),
                self::neutralize('Regular'),
                self::neutralize('ACTIVE'),
                self::neutralize(''),
                self::neutralize('109283746501'),
                self::neutralize('2005-04-12'),
                self::neutralize('09171234567'),
            ],
            [
                self::neutralize('SIA-2024-0102'),
                self::neutralize('Maria'),
                self::neutralize('Reyes'),
                self::neutralize('Bautista'),
                self::neutralize('maria.bautista@servitech.edu.ph'),
                self::neutralize('Irregular'),
                self::neutralize('ACTIVE'),
                self::neutralize('Prior balance from AY 2024-2025'),
                self::neutralize('109283746502'),
                self::neutralize('2006-08-21'),
                self::neutralize('09189876543'),
            ],
        ];

        if ($ext === 'csv') {
            $writer = new CsvWriter;
            $writer->openToFile($path);

            try {
                foreach ($studentRows as $rowValues) {
                    $writer->addRow(Row::fromValues($rowValues));
                }
            } finally {
                $writer->close();
            }

            return $path;
        }

        $referenceRows = $this->buildReferenceCodesRows();

        $writer = new XlsxWriter;
        $writer->openToFile($path);

        try {
            $writer->getCurrentSheet()->setName('Students');
            foreach ($studentRows as $rowValues) {
                $writer->addRow(Row::fromValues($rowValues));
            }

            $refSheet = $writer->addNewSheetAndMakeItCurrent();
            $refSheet->setName('Reference Codes');
            foreach ($referenceRows as $rowValues) {
                $writer->addRow(Row::fromValues($rowValues));
            }
        } finally {
            $writer->close();
        }

        return $path;
    }

    /**
     * Stream a formula-safe CSV of filtered or selected student records (Program, Batch, Section, Standing, Activation).
     *
     * @param  Collection<int, StudentProfile>  $profiles
     */
    public function exportStudentDirectory(
        Collection $profiles,
        ?int $enrolledTermId = null,
        string $filenamePrefix = 'student-records',
    ): string {
        $safePrefix = preg_replace('/[^A-Za-z0-9_-]+/', '-', trim($filenamePrefix)) ?: 'student-records';
        $path = $this->prepareExportPath($safePrefix.'-'.now()->format('Y-m-d').'.csv');

        if ($profiles instanceof EloquentCollection) {
            $profiles->loadMissing(['user', 'program', 'curriculumVersion', 'entryAcademicYear', 'entryTerm.academicYear']);
            $profiles->loadCount([
                'priorAcademicCredits as active_prior_credits_count' => fn ($query) => $query->active(),
            ]);
            if ($enrolledTermId !== null) {
                $profiles->load([
                    'enrollments' => fn ($query) => $query
                        ->where('canonical_outcome', Enrollment::OutcomeOfficiallyEnrolled)
                        ->where('term_id', $enrolledTermId)
                        ->with('studentSection'),
                ]);
            }
        }

        $writer = new CsvWriter;
        $writer->openToFile($path);

        try {
            $writer->addRow(Row::fromValues([
                'Student Number',
                'Full Name',
                'Email',
                'Program',
                'Curriculum',
                'Batch / Entry School Year',
                'Section',
                'Lifecycle',
                'Standing',
                'Prior Subjects Count',
                'Account Status',
                'Last Sign-In',
            ]));

            foreach ($profiles as $profile) {
                if (! $profile instanceof StudentProfile) {
                    continue;
                }

                $fullName = collect([
                    $profile->first_name,
                    $profile->middle_name,
                    $profile->last_name,
                ])->filter()->implode(' ');

                if ($fullName === '' && $profile->user !== null) {
                    $fullName = (string) $profile->user->name;
                }

                $email = (string) ($profile->user?->email ?? $profile->email ?? '');
                $curriculumLabel = (string) ($profile->curriculumVersion?->version_code ?: ($profile->curriculumVersion?->name ?? ''));
                $batchLabel = (string) ($profile->entryAcademicYear?->label ?? $profile->entryTerm?->academicYear?->label ?? '');
                $sectionLabel = $enrolledTermId !== null
                    ? (string) ($profile->enrollments->first()?->studentSection?->reference ?? 'Not assigned')
                    : '';
                $priorCount = $profile->hasAttribute('active_prior_credits_count')
                    ? (int) $profile->getAttribute('active_prior_credits_count')
                    : $profile->priorAcademicCredits()->active()->count();
                $lastSignIn = $profile->user?->last_successful_sign_in_at?->timezone(config('app.display_timezone', 'Asia/Manila'))?->format('Y-m-d H:i') ?? 'Never';

                $writer->addRow(Row::fromValues([
                    self::neutralize((string) $profile->student_number),
                    self::neutralize($fullName),
                    self::neutralize($email),
                    self::neutralize((string) ($profile->program?->code ?? '')),
                    self::neutralize($curriculumLabel),
                    self::neutralize($batchLabel),
                    self::neutralize($sectionLabel),
                    self::neutralize(StudentProfile::lifecycleStatusLabel((string) $profile->lifecycle_status)),
                    self::neutralize((string) ($profile->academic_standing ?? StudentProfile::StandingRegular)),
                    $priorCount,
                    self::neutralize(StudentAccountActivation::statusLabel($profile)),
                    self::neutralize($lastSignIn),
                ]));
            }
        } finally {
            $writer->close();
        }

        return $path;
    }

    /**
     * Stream a formula-safe CSV summarizing student onboarding & account activation status.
     *
     * @param  Collection<int, StudentProfile>  $profiles
     */
    public function exportActivationStatus(Collection $profiles): string
    {
        $path = $this->prepareExportPath('student-activation-status-'.now()->format('Y-m-d').'.csv');

        if ($profiles instanceof EloquentCollection) {
            $profiles->loadMissing(['user', 'program', 'curriculumVersion']);
            $profiles->loadCount([
                'priorAcademicCredits as active_prior_credits_count' => fn ($query) => $query->active(),
            ]);
        }

        $writer = new CsvWriter;
        $writer->openToFile($path);

        try {
            $writer->addRow(Row::fromValues([
                'Student Number',
                'Student Name',
                'Email',
                'Program',
                'Curriculum',
                'Standing',
                'Prior Subjects Count',
                'Activation Status',
                'Last Sign-In',
            ]));

            foreach ($profiles as $profile) {
                if (! $profile instanceof StudentProfile) {
                    continue;
                }

                $fullName = collect([
                    $profile->first_name,
                    $profile->middle_name,
                    $profile->last_name,
                ])->filter()->implode(' ');

                if ($fullName === '' && $profile->user !== null) {
                    $fullName = (string) $profile->user->name;
                }

                $email = (string) ($profile->user?->email ?? $profile->email ?? '');
                $curriculumLabel = (string) ($profile->curriculumVersion?->version_code ?: ($profile->curriculumVersion?->name ?? ''));
                $priorCount = $profile->hasAttribute('active_prior_credits_count')
                    ? (int) $profile->getAttribute('active_prior_credits_count')
                    : $profile->priorAcademicCredits()->active()->count();
                $lastSignIn = $profile->user?->last_successful_sign_in_at?->format('Y-m-d H:i') ?? 'Never';

                $writer->addRow(Row::fromValues([
                    self::neutralize((string) $profile->student_number),
                    self::neutralize($fullName),
                    self::neutralize($email),
                    self::neutralize((string) ($profile->program?->code ?? '')),
                    self::neutralize($curriculumLabel),
                    self::neutralize((string) ($profile->academic_standing ?? '')),
                    $priorCount,
                    self::neutralize(StudentAccountActivation::statusLabel($profile)),
                    self::neutralize($lastSignIn),
                ]));
            }
        } finally {
            $writer->close();
        }

        return $path;
    }

    /**
     * Stream a formula-safe CSV of skipped or blocked onboarding rows.
     *
     * @param  array<int, array<string, mixed>>  $skippedRows
     */
    public function exportSkippedRowsCsv(array $skippedRows): string
    {
        $path = $this->prepareExportPath('skipped-student-import-rows-'.now()->format('Y-m-d').'.csv');

        $writer = new CsvWriter;
        $writer->openToFile($path);

        try {
            $writer->addRow(Row::fromValues([
                'Row',
                'Student Number',
                'Student Name',
                'Email',
                'Reason',
            ]));

            foreach ($skippedRows as $row) {
                $studentName = trim((string) ($row['student_name'] ?? collect([
                    $row['first_name'] ?? null,
                    $row['middle_name'] ?? null,
                    $row['last_name'] ?? null,
                ])->filter()->implode(' ')));

                $reason = trim((string) ($row['skip_reason'] ?? $row['already_onboarded_reason'] ?? implode(' ', (array) ($row['errors'] ?? []))));
                if ($reason === '') {
                    $reason = 'Skipped';
                }

                $writer->addRow(Row::fromValues([
                    (int) ($row['source_row'] ?? 0),
                    self::neutralize((string) ($row['student_number'] ?? '')),
                    self::neutralize($studentName),
                    self::neutralize((string) ($row['email'] ?? '')),
                    self::neutralize($reason),
                ]));
            }
        } finally {
            $writer->close();
        }

        return $path;
    }

    /**
     * Return a BinaryFileResponse configured to delete the temporary file after download.
     */
    public function downloadAndDelete(string $path, ?string $downloadName = null, ?string $format = null): BinaryFileResponse
    {
        return app(CurriculumSpreadsheetExportService::class)->downloadAndDelete($path, $downloadName, $format);
    }

    /**
     * Build Sheet 2 (`Reference Codes`) rows live from the database.
     *
     * @return list<list<float|int|string>>
     */
    private function buildReferenceCodesRows(): array
    {
        $rows = [[
            'Category',
            'Accepted Value / Code',
            'Description / Title',
            'Notes / Program',
            'Status',
        ]];

        foreach ([
            StudentProfile::StandingRegular => 'Standard cohort progression (eligible for Cohort Quick-Credit)',
            StudentProfile::StandingIrregular => 'Custom subject load (routed to individual advising)',
            StudentProfile::StandingProbationary => 'Academic probation (routed to individual advising)',
            StudentProfile::StandingDeficient => 'Academic deficiency (routed to individual advising)',
            StudentProfile::StandingCompletionCandidate => 'Completion candidate',
            StudentProfile::StandingGraduationCandidate => 'Graduation candidate',
        ] as $standing => $desc) {
            $rows[] = ['Academic Standing', $standing, $desc, 'Blank defaults to Regular', 'Accepted'];
        }

        foreach ([
            StudentProfile::LifecycleActive => 'Active student',
            StudentProfile::LifecycleLeaveOfAbsence => 'Leave of Absence (alias: LOA)',
            StudentProfile::LifecycleInactive => 'Inactive student',
            StudentProfile::LifecycleWithdrawn => 'Withdrawn',
            StudentProfile::LifecycleTransferredOut => 'Transferred out',
            StudentProfile::LifecycleCompleted => 'Completed program',
        ] as $lifecycle => $desc) {
            $rows[] = ['Lifecycle Status', $lifecycle, $desc, 'Blank defaults to ACTIVE', 'Accepted'];
        }

        $programs = Program::query()
            ->orderByDesc('is_active')
            ->orderBy('code')
            ->get();

        foreach ($programs as $program) {
            $rows[] = [
                'Program',
                self::neutralize((string) $program->code),
                self::neutralize((string) $program->name),
                'Selected in Step 1 Scope Bar',
                $program->is_active ? 'Active' : 'Teach-out / Inactive',
            ];
        }

        $curricula = CurriculumVersion::query()
            ->with(['program', 'academicYears'])
            ->whereIn('state', [CurriculumVersion::StateActive, CurriculumVersion::StateSuperseded])
            ->orderBy('program_id')
            ->orderBy('version_code')
            ->get();

        foreach ($curricula as $curriculum) {
            $rows[] = [
                'Curriculum Version',
                self::neutralize((string) $curriculum->version_code),
                self::neutralize($curriculum->batchLabel()),
                self::neutralize((string) ($curriculum->program?->code ?? '')),
                self::neutralize(CurriculumVersion::stateOptions()[$curriculum->state] ?? (string) $curriculum->state),
            ];
        }

        return $rows;
    }

    private static function neutralize(mixed $value): float|int|string
    {
        return CurriculumSpreadsheetExportService::neutralizeFormulaInjection($value);
    }

    private function normalizeFormat(string $format): string
    {
        $clean = strtolower(ltrim(trim($format), '.'));

        return $clean === 'csv' ? 'csv' : 'xlsx';
    }

    private function prepareExportPath(string $filename): string
    {
        $exportDir = storage_path('app/private/exports/'.Str::ulid());
        File::ensureDirectoryExists($exportDir);

        return $exportDir.DIRECTORY_SEPARATOR.$filename;
    }
}
