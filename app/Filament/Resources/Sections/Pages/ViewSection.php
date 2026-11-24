<?php

namespace App\Filament\Resources\Sections\Pages;

use App\Actions\Grades\ManageTeachingAssignment;
use App\Actions\Grades\SynchronizeOfficialGradeRoster;
use App\Filament\Resources\Sections\SectionResource;
use App\Models\ClassOfferingTeachingAssignment;
use App\Models\Section;
use App\Models\User;
use App\Support\HasTermPlanningContext;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ViewSection extends ViewRecord
{
    use HasTermPlanningContext {
        getPageClasses as protected getTermPlanningPageClasses;
    }

    protected static string $resource = SectionResource::class;

    /** @return array<string> */
    public function getPageClasses(): array
    {
        return [...$this->getTermPlanningPageClasses(), 'tala-class-detail'];
    }

    public function getSubheading(): string
    {
        return 'Class · '.$this->getRecord()->code;
    }

    public function getBreadcrumb(): string
    {
        return 'Class details';
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->assignTeachingFacultyAction(),
            EditAction::make()->label('Edit class'),
        ];
    }

    /**
     * Registrar correction path for shared classes, substitutes and wrong assignments.
     * The published timetable records the ordinary assignment automatically.
     */
    public function assignTeachingFacultyAction(): Action
    {
        return Action::make('assignTeachingFaculty')
            ->label('Assign teaching Faculty')
            ->icon('heroicon-o-user-plus')
            ->color('gray')
            ->modalHeading('Assign teaching Faculty')
            ->modalDescription('The published timetable assigns its Faculty automatically. Use this for a shared class, a substitute or a correction; a timetable revision will not replace an assignment recorded here.')
            ->modalSubmitActionLabel('Record assignment')
            ->visible(fn (): bool => (bool) auth()->user()?->hasRole(User::StaffRoleRegistrar)
                && $this->getRecord()->state !== Section::StateCancelled)
            ->schema([
                Select::make('faculty_user_id')
                    ->label('Faculty')
                    ->options(fn (): array => User::role(User::StaffRoleFaculty)
                        ->where('status', User::StatusActive)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->required(),
                Select::make('role')
                    ->label('Role in this class')
                    ->options([
                        ClassOfferingTeachingAssignment::RoleDesignated => 'Designated (submits the grades)',
                        ClassOfferingTeachingAssignment::RoleCoFaculty => 'Co-Faculty (views the roster)',
                    ])
                    ->default(ClassOfferingTeachingAssignment::RoleDesignated)
                    ->required(),
                TextInput::make('authority_reference')
                    ->label('Assignment authority')
                    ->helperText('The memo, teaching load or Registrar reference for this assignment.')
                    ->required()
                    ->maxLength(255),
            ])
            ->action(function (array $data): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                $section = $this->getRecord();
                $faculty = User::query()->findOrFail((int) $data['faculty_user_id']);

                try {
                    DB::transaction(function () use ($data, $section, $faculty, $actor): void {
                        $assignments = app(ManageTeachingAssignment::class);

                        if ($data['role'] === ClassOfferingTeachingAssignment::RoleDesignated) {
                            $assignments->designate($section, $faculty, $actor, (string) $data['authority_reference']);
                            app(SynchronizeOfficialGradeRoster::class)->execute($section, $actor);

                            return;
                        }

                        $assignments->addCoFaculty($section, $faculty, $actor, (string) $data['authority_reference']);
                    });
                } catch (RuntimeException $exception) {
                    Notification::make()->title('Assignment was not recorded')->body($exception->getMessage())->danger()->send();

                    throw new Halt;
                }

                Notification::make()->title('Teaching assignment recorded')->body($faculty->name.' now teaches '.$section->displayReference().'.')->success()->send();
            });
    }
}
