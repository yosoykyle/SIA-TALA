<?php

namespace App\Filament\Pages;

use App\Actions\Completion\CompletionReadinessProjection;
use App\Actions\Completion\CorrectDegreeConferral;
use App\Actions\Completion\CorrectGraduationApplication;
use App\Actions\Completion\RecordDegreeConferral;
use App\Actions\Completion\RecordTranscriptRequest;
use App\Filament\Resources\TranscriptRequests\TranscriptRequestResource;
use App\Models\DegreeConferral;
use App\Models\GraduationApplication;
use App\Models\StudentProfile;
use App\Models\TranscriptRequest;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Throwable;

class CompletionAndTor extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Completion & TOR';

    protected string $view = 'filament.pages.completion-and-tor';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole([
            User::StaffRoleRegistrar,
            User::StaffRoleAcademicHead,
        ]) ?? false;
    }

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        if (! (auth()->user()?->hasRole(User::StaffRoleRegistrar) ?? false)) {
            return [];
        }

        return [
            Action::make('correctApplication')
                ->label('Correct application')
                ->icon('heroicon-o-pencil-square')
                ->disabled(fn (): bool => $this->activeApplicationOptions() === [])
                ->schema([
                    Select::make('application_id')->label('Active application')->options($this->activeApplicationOptions())->searchable()->required(),
                    TextInput::make('authority_reference')->required()->maxLength(255),
                    Textarea::make('reason')->required()->maxLength(1000),
                ])
                ->action(function (array $data): void {
                    $actor = $this->actor();
                    app(CorrectGraduationApplication::class)->execute(
                        GraduationApplication::query()->findOrFail((int) $data['application_id']),
                        $actor,
                        (string) $data['authority_reference'],
                        (string) $data['reason'],
                    );
                    Notification::make()->title('Application correction recorded')->success()->send();
                }),
            Action::make('recordConferral')
                ->label('Record conferral')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription('This creates an immutable Degree Conferral, freezes the final curriculum evaluation, and records the Student as Completed. Later corrections append successors; they never rewrite this record.')
                ->disabled(fn (): bool => $this->readyStudentOptions() === [])
                ->schema([
                    Select::make('student_profile_id')->label('Ready Student')->options($this->readyStudentOptions())->searchable()->required(),
                    TextInput::make('degree_name')->required()->maxLength(255),
                    DatePicker::make('conferred_on')->required(),
                    TextInput::make('authority_reference')->required()->maxLength(255),
                    TextInput::make('honor_text')->maxLength(255),
                    TextInput::make('honor_authority_reference')->maxLength(255),
                ])
                ->action(function (array $data): void {
                    try {
                        app(RecordDegreeConferral::class)->execute(
                            StudentProfile::query()->findOrFail((int) $data['student_profile_id']),
                            $this->actor(),
                            (string) $data['degree_name'],
                            (string) $data['conferred_on'],
                            (string) $data['authority_reference'],
                            filled($data['honor_text'] ?? null) ? (string) $data['honor_text'] : null,
                            filled($data['honor_authority_reference'] ?? null) ? (string) $data['honor_authority_reference'] : null,
                        );
                        Notification::make()->title('Immutable conferral recorded')->success()->send();
                    } catch (Throwable $exception) {
                        report($exception);
                        Notification::make()->title('Conferral was not recorded')->body('Refresh readiness and correct the named source before retrying.')->danger()->send();
                    }
                }),
            Action::make('recordTorRequest')
                ->label('Record TOR request')
                ->icon('heroicon-o-document-plus')
                ->disabled(fn (): bool => $this->conferralOptions() === [])
                ->schema([
                    Select::make('degree_conferral_id')->label('Conferred Student')->options($this->conferralOptions())->searchable()->required(),
                    TextInput::make('request_reference')->required()->maxLength(255),
                    DatePicker::make('requested_on')->required(),
                    TextInput::make('signatory_name')->required()->maxLength(255),
                    TextInput::make('signatory_title')->required()->maxLength(255),
                    Textarea::make('seal_placement_instruction')->required()->maxLength(1000),
                ])
                ->action(function (array $data): void {
                    app(RecordTranscriptRequest::class)->execute(
                        DegreeConferral::query()->findOrFail((int) $data['degree_conferral_id']),
                        $this->actor(),
                        (string) $data['request_reference'],
                        (string) $data['requested_on'],
                        (string) $data['signatory_name'],
                        (string) $data['signatory_title'],
                        TranscriptRequest::SealPlacementInstruction,
                        sealPlacementInstruction: (string) $data['seal_placement_instruction'],
                    );
                    Notification::make()->title('TOR request recorded for Accounting clearance')->success()->send();
                }),
            Action::make('correctConferral')
                ->label('Correct conferral')
                ->icon('heroicon-o-arrow-path-rounded-square')
                ->requiresConfirmation()
                ->modalDescription('This preserves the current conferral and appends an authorized successor. Every affected TOR snapshot remains history and is marked Superseded.')
                ->disabled(fn (): bool => $this->conferralOptions() === [])
                ->schema([
                    Select::make('degree_conferral_id')->label('Current conferral')->options($this->conferralOptions())->searchable()->required(),
                    TextInput::make('degree_name')->required()->maxLength(255),
                    DatePicker::make('conferred_on')->required(),
                    TextInput::make('authority_reference')->required()->maxLength(255),
                    Textarea::make('reason')->required()->maxLength(1000),
                    TextInput::make('honor_text')->maxLength(255),
                    TextInput::make('honor_authority_reference')->maxLength(255),
                ])
                ->action(function (array $data): void {
                    app(CorrectDegreeConferral::class)->execute(
                        DegreeConferral::query()->findOrFail((int) $data['degree_conferral_id']),
                        $this->actor(),
                        (string) $data['degree_name'],
                        (string) $data['conferred_on'],
                        (string) $data['authority_reference'],
                        (string) $data['reason'],
                        filled($data['honor_text'] ?? null) ? (string) $data['honor_text'] : null,
                        filled($data['honor_authority_reference'] ?? null) ? (string) $data['honor_authority_reference'] : null,
                    );
                    Notification::make()->title('Conferral correction recorded')->success()->send();
                }),
            Action::make('torHistory')
                ->label('TOR requests & history')
                ->icon('heroicon-o-clock')
                ->url(TranscriptRequestResource::getUrl('index')),
        ];
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $students = StudentProfile::query()
            ->whereHas('graduationApplications')
            ->with(['program', 'graduationApplications', 'completionReadinessVersions', 'degreeConferrals'])
            ->orderBy('last_name')
            ->limit(100)
            ->get();

        return [
            'completionRows' => $students->map(function (StudentProfile $student): array {
                $projection = app(CompletionReadinessProjection::class)->forStudent($student);

                return ['student' => $student, ...$projection];
            }),
            'torRequestsUrl' => TranscriptRequestResource::getUrl('index'),
            'isRegistrar' => auth()->user()?->hasRole(User::StaffRoleRegistrar) ?? false,
        ];
    }

    /** @return array<int, string> */
    private function readyStudentOptions(): array
    {
        return StudentProfile::query()->whereHas('graduationApplications', fn ($query) => $query->where('state', GraduationApplication::StateActive))
            ->orderBy('last_name')->get()->filter(fn (StudentProfile $student): bool => app(CompletionReadinessProjection::class)->forStudent($student)['state'] === CompletionReadinessProjection::ReadyForConferral)
            ->mapWithKeys(fn (StudentProfile $student): array => [$student->id => $this->studentLabel($student)])->all();
    }

    /** @return array<int, string> */
    private function activeApplicationOptions(): array
    {
        return GraduationApplication::query()->where('state', GraduationApplication::StateActive)->with('studentProfile')->get()
            ->mapWithKeys(fn (GraduationApplication $application): array => [$application->id => $this->studentLabel($application->studentProfile)." · v{$application->version}"])->all();
    }

    /** @return array<int, string> */
    private function conferralOptions(): array
    {
        return DegreeConferral::query()->whereNotNull('active_scope_key')->with('studentProfile')->get()
            ->mapWithKeys(fn (DegreeConferral $conferral): array => [$conferral->id => $this->studentLabel($conferral->studentProfile)." · {$conferral->degree_name}"])->all();
    }

    private function studentLabel(StudentProfile $student): string
    {
        return collect([$student->student_number, $student->last_name, $student->first_name])->filter()->implode(' · ');
    }

    private function actor(): User
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
