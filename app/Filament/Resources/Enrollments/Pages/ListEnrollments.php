<?php

namespace App\Filament\Resources\Enrollments\Pages;

use App\Actions\Enrollment\StartEnrollment;
use App\Filament\Resources\Enrollments\EnrollmentResource;
use App\Models\AdmissionApplication;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use App\Queries\Admissions\ReadyApplicantProjectionQuery;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Collection;
use Throwable;

class ListEnrollments extends ListRecords
{
    protected static string $resource = EnrollmentResource::class;

    /**
     * @return list<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('readyApplicants')
                ->label('Ready applicants')
                ->icon('heroicon-o-user-plus')
                ->color('info')
                ->modalHeading('Ready applicants from Admissions')
                ->modalDescription('Read-only Clinic 4 visibility. No Registration Case, Student, enrollment, placement, or assessment has been created.')
                ->modalContent(fn () => view('filament.admin.enrollments.ready-applicants', [
                    'applications' => $this->readyApplicants(),
                ]))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->visible(fn (): bool => auth()->user()?->hasRole(User::StaffRoleRegistrar) ?? false),
            Action::make('startContinuingEnrollment')
                ->label('Start Continuing Enrollment')
                ->icon('heroicon-o-plus-circle')
                ->labeledFrom('md')
                ->tooltip('Start continuing enrollment')
                ->visible(fn (): bool => auth()->user()?->hasAnyRole([
                    User::StaffRoleRegistrar,
                    User::StaffRoleSystemSuperAdmin,
                ]) ?? false)
                ->schema([
                    Select::make('student_profile_id')
                        ->label('Student')
                        ->options(fn (): array => StudentProfile::query()
                            ->with('program')
                            ->orderBy('student_number')
                            ->get()
                            ->mapWithKeys(fn (StudentProfile $profile): array => [
                                $profile->id => collect([
                                    $profile->student_number,
                                    $profile->last_name,
                                    $profile->first_name,
                                    $profile->program?->code,
                                ])->filter()->implode(' - '),
                            ])
                            ->all())
                        ->searchable()
                        ->required(),
                    Select::make('term_id')
                        ->label('Enrollment term')
                        ->options(fn (): array => Term::query()
                            ->where('state', Term::StateActive)
                            ->orderByDesc('starts_on')
                            ->get()
                            ->mapWithKeys(fn (Term $term): array => [$term->id => $term->label])
                            ->all())
                        ->required(),
                    Select::make('student_type')
                        ->label('Enrollment type')
                        ->options([
                            'regular' => 'Regular',
                            'irregular' => 'Irregular',
                            'returnee' => 'Returnee',
                            'transferee' => 'Transferee',
                        ])
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $actor = auth()->user();
                    $profile = StudentProfile::query()->find($data['student_profile_id']);
                    $term = Term::query()->find($data['term_id']);

                    if (! $actor instanceof User
                        || ! $profile instanceof StudentProfile
                        || ! $term instanceof Term) {
                        return;
                    }

                    try {
                        $enrollment = app(StartEnrollment::class)->executeContinuing(
                            $profile,
                            $term,
                            (string) $data['student_type'],
                            $actor,
                        );

                        if (! $enrollment->wasRecentlyCreated) {
                            Notification::make()
                                ->title('Enrollment already exists')
                                ->body('No duplicate was created. Open the existing record from the enrollment list to continue its current workflow.')
                                ->info()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('Enrollment started')
                            ->body('The source record is ready for proposal and placement review.')
                            ->success()
                            ->send();
                    } catch (Throwable $exception) {
                        Notification::make()
                            ->title('Enrollment not started')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }

    /** @return Collection<int, AdmissionApplication> */
    private function readyApplicants(): Collection
    {
        $query = app(ReadyApplicantProjectionQuery::class);

        return AdmissionApplication::query()
            ->canonical()
            ->where('application_state', AdmissionApplication::StateAdmitted)
            ->with(['user', 'program', 'admissionCycle', 'decisions', 'credentialResults.requirement', 'currentSubmissionVersion.requirementSet.requirements'])
            ->get()
            ->filter(fn (AdmissionApplication $application): bool => $query->forApplication($application)['ready'])
            ->values();
    }
}
