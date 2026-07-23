<?php

namespace App\Filament\Resources\Enrollments\Pages;

use App\Actions\Enrollment\StartEnrollment;
use App\Filament\Resources\Enrollments\EnrollmentResource;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
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
            Action::make('startContinuingEnrollment')
                ->label('Start Continuing Enrollment')
                ->icon('heroicon-o-plus-circle')
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
                        app(StartEnrollment::class)->executeContinuing(
                            $profile,
                            $term,
                            (string) $data['student_type'],
                            $actor,
                        );
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
}
