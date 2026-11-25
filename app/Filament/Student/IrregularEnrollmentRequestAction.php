<?php

namespace App\Filament\Student;

use App\Actions\Academics\IrregularStudentReasons;
use App\Actions\Enrollment\RegistrationClassChoices;
use App\Actions\Enrollment\SubmitIrregularEnrollmentRequest;
use App\Models\Enrollment;
use App\Models\IrregularEnrollmentRequest;
use App\Models\Section;
use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View as SchemaView;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Enums\Width;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Student "Enroll as irregular" request: confirm reason → choose subjects → review and submit (#99 Scope 11).
 */
class IrregularEnrollmentRequestAction
{
    /** @param Closure(): ?Enrollment $case */
    public static function make(Closure $case): Action
    {
        return Action::make('applyAsIrregular')
            ->label(fn (): string => self::request($case()) !== null ? 'Edit irregular application' : 'Apply as irregular')
            ->icon('heroicon-o-pencil-square')
            ->color('primary')
            ->visible(fn (): bool => self::canApply($case()))
            ->modalHeading(fn (): string => 'Apply for irregular enrollment – '.collect([$case()?->term?->label, $case()?->term?->academicYear?->label])->filter()->implode(', '))
            ->modalDescription(fn (): ?string => ($request = self::request($case())) !== null ? 'Returned for correction: '.$request->return_reason : 'Your status: '.self::review($case())['status_label'])
            ->modalWidth(Width::FourExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalSubmitActionLabel('Submit irregular application')
            ->fillForm(function () use ($case): array {
                $availableSectionIds = self::choices()->forCase($case())['sections']
                    ->pluck('id')
                    ->map(fn (mixed $id): int => (int) $id)
                    ->all();
                $prefilledSectionIds = self::request($case())?->sectionIds() ?? self::mandatorySectionDefaults($case());

                return [
                    'section_ids' => array_values(array_intersect(array_map('intval', $prefilledSectionIds), $availableSectionIds)),
                    'student_remarks' => self::request($case())?->student_remarks,
                ];
            })
            ->steps([
                Step::make('Confirm reason')
                    ->description('Why you are irregular')
                    ->schema([
                        SchemaView::make('filament.student.pages.irregular-request-reason')
                            ->viewData(fn (): array => ['case' => $case(), 'review' => self::review($case())]),
                        Textarea::make('student_remarks')->label('Additional remarks (optional)')->rows(2)->maxLength(1000),
                        FileUpload::make('supporting_documents')
                            ->label('Supporting documents')
                            ->helperText('Returnees: LOA letter or medical certificate. Shiftees: shifting approval letter. Not needed for failed, dropped or INC subjects. PDF, JPEG or PNG; up to 10 MiB each.')
                            ->disk('local')
                            ->directory('irregular-requests')
                            ->visibility('private')
                            ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])
                            ->maxSize(10240)
                            ->maxFiles(3)
                            ->multiple()
                            ->visible(fn (): bool => self::needsDocuments($case())),
                        Checkbox::make('confirm_reason')
                            ->label('I confirm that the information above is correct and I understand that I will be enrolled as irregular for this term.')
                            ->accepted()
                            ->required(),
                    ]),
                Step::make('Choose subjects')
                    ->description('Retakes and eligible subjects')
                    ->schema([
                        Text::make('Mandatory retakes are already ticked and cannot be removed. Locked subjects show the prerequisite you still need. Full classes cannot be chosen.'),
                        CheckboxList::make('section_ids')
                            ->label('Subjects and classes')
                            ->options(fn (Get $get): array => self::options($case(), $get('section_ids') ?? []))
                            ->descriptions(fn (): array => self::choices()->sectionDescriptions($case()))
                            ->disableOptionWhen(fn (string $value): bool => self::isLocked($case(), (int) $value))
                            ->searchable()
                            ->columns(1)
                            ->live()
                            ->required()
                            ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail) use ($case): void {
                                $missing = self::missingRetakes($case(), (array) $value);
                                if ($missing !== []) {
                                    $fail('You must retake '.implode(', ', $missing).'. This cannot be removed.');
                                }
                            }),
                        Text::make(fn (Get $get): string => self::summary($case(), $get('section_ids') ?? []))
                            ->weight('bold'),
                        Text::make('Tuition is assessed per unit by Accounting after the Registrar approves your subjects.'),
                    ]),
                Step::make('Review & submit')
                    ->description('Final check')
                    ->schema([
                        SchemaView::make('filament.student.pages.irregular-request-review')
                            ->viewData(fn (Get $get): array => [
                                'case' => $case(),
                                'review' => self::review($case()),
                                'sections' => self::sections($case(), $get('section_ids') ?? []),
                                'summary' => self::summary($case(), $get('section_ids') ?? []),
                            ]),
                        Checkbox::make('understand_charges')
                            ->label(fn (): string => 'I understand that I am applying as an irregular student for '.collect([$case()?->term?->label, $case()?->term?->academicYear?->label])->filter()->implode(' ').' and I will be charged per unit.')
                            ->accepted()
                            ->required(),
                        Checkbox::make('confirm_subjects')
                            ->label('I confirm that my selected subjects are correct.')
                            ->accepted()
                            ->required(),
                        Text::make('After you submit, you cannot edit your subjects unless the Registrar returns your application for correction.'),
                    ]),
            ])
            ->action(function (array $data) use ($case): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                try {
                    app(SubmitIrregularEnrollmentRequest::class)->execute(
                        $case(),
                        $actor,
                        array_map('intval', $data['section_ids'] ?? []),
                        $data['student_remarks'] ?? null,
                        array_values(array_filter((array) ($data['supporting_documents'] ?? []))),
                    );
                } catch (ValidationException $exception) {
                    Notification::make()->title('Your application was not submitted')->body(collect($exception->errors())->flatten()->first())->danger()->send();

                    throw new Halt;
                }

                Notification::make()
                    ->title('Your irregular application has been submitted')
                    ->body('Please wait for Registrar approval.')
                    ->success()
                    ->send();
            });
    }

    /** One class-choice instance per request, so its per-case cache serves every option of the checkbox list. */
    private static function choices(): RegistrationClassChoices
    {
        return once(fn (): RegistrationClassChoices => app(RegistrationClassChoices::class));
    }

    public static function canApply(?Enrollment $case): bool
    {
        $request = $case !== null ? IrregularEnrollmentRequest::query()->where('enrollment_id', $case->id)->first() : null;

        return $case !== null
            && $case->canonical_outcome === Enrollment::OutcomeInProgress
            && $case->selection_basis === Enrollment::SelectionIndividuallyAdvised
            && $case->current_proposal_version_id === null
            && $case->studentProfile !== null
            && $case->admissionApplication === null
            && ($request === null || $request->state === IrregularEnrollmentRequest::StateReturned);
    }

    /** @return array<string, mixed> */
    private static function review(?Enrollment $case): array
    {
        return once(fn (): array => app(IrregularStudentReasons::class)->forStudent($case->studentProfile, $case->term));
    }

    private static function request(?Enrollment $case): ?IrregularEnrollmentRequest
    {
        return $case !== null
            ? IrregularEnrollmentRequest::query()->where('enrollment_id', $case->id)->where('state', IrregularEnrollmentRequest::StateReturned)->first()
            : null;
    }

    private static function needsDocuments(?Enrollment $case): bool
    {
        return collect(self::review($case)['reasons'])->contains(fn (array $reason): bool => in_array($reason['code'], [IrregularStudentReasons::ReasonReturnee, IrregularStudentReasons::ReasonShiftee], true));
    }

    /**
     * @param  list<int|string>  $selected
     * @return array<int, string>
     */
    private static function options(?Enrollment $case, array $selected): array
    {
        $choices = self::choices();
        $mandatory = self::mandatoryCourseIds($case);

        return collect($choices->sectionOptions($case, $selected))
            ->map(function (string $label, int $sectionId) use ($case, $choices, $mandatory): string {
                $section = $choices->forCase($case)['sections']->firstWhere('id', $sectionId);
                $seats = $section instanceof Section ? $choices->seats($case, $section) : null;
                $courseId = (int) ($section?->termOffering?->courseSpecification()?->course_id ?? 0);

                return collect([
                    $label,
                    $mandatory->contains($courseId) ? 'MANDATORY RETAKE' : null,
                    $seats !== null ? $seats['remaining'].'/'.$seats['capacity'].' seats open' : null,
                ])->filter()->implode(' · ');
            })
            ->all();
    }

    private static function isLocked(?Enrollment $case, int $sectionId): bool
    {
        $choices = self::choices();
        $section = $choices->forCase($case)['sections']->firstWhere('id', $sectionId);
        $label = (string) ($choices->sectionOptions($case)[$sectionId] ?? '');

        return ! $section instanceof Section
            || $choices->seats($case, $section)['remaining'] <= 0
            || str_contains($label, 'unmet')
            || str_contains($label, 'Already passed');
    }

    /** @return Collection<int, int> */
    private static function mandatoryCourseIds(?Enrollment $case): Collection
    {
        return $case?->studentProfile !== null
            ? app(IrregularStudentReasons::class)->mandatoryRetakeCourseIds($case->studentProfile)
            : collect();
    }

    /** @return list<int> */
    private static function mandatorySectionDefaults(?Enrollment $case): array
    {
        $mandatory = self::mandatoryCourseIds($case);
        $choices = self::choices();

        return $choices->forCase($case)['sections']
            ->filter(fn (Section $section): bool => $mandatory->contains((int) ($section->termOffering?->courseSpecification()?->course_id ?? 0))
                && $choices->seats($case, $section)['remaining'] > 0)
            ->unique(fn (Section $section): int => (int) $section->termOffering?->courseSpecification()?->course_id)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @param  list<int|string>  $selected
     * @return list<string>
     */
    private static function missingRetakes(?Enrollment $case, array $selected): array
    {
        $choices = self::choices()->forCase($case)['sections'];
        $chosenCourseIds = $choices->whereIn('id', array_map('intval', $selected))
            ->map(fn (Section $section): int => (int) ($section->termOffering?->courseSpecification()?->course_id ?? 0));

        return $choices
            ->filter(fn (Section $section): bool => self::mandatoryCourseIds($case)->contains((int) ($section->termOffering?->courseSpecification()?->course_id ?? 0))
                && ! $chosenCourseIds->contains((int) ($section->termOffering?->courseSpecification()?->course_id ?? 0)))
            ->map(fn (Section $section): string => (string) $section->termOffering?->course()?->code)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<int|string>  $selected
     * @return Collection<int, Section>
     */
    private static function sections(?Enrollment $case, array $selected): Collection
    {
        return self::choices()->forCase($case)['sections']->whereIn('id', array_map('intval', $selected))->values();
    }

    /** @param list<int|string> $selected */
    private static function summary(?Enrollment $case, array $selected): string
    {
        $sections = self::sections($case, $selected);
        $units = $sections->sum(fn (Section $section): float => (float) $section->termOffering?->courseSpecification()?->credit_units);

        return 'Total: '.$sections->count().' '.str('subject')->plural($sections->count()).' · '.rtrim(rtrim(number_format($units, 2), '0'), '.').' units';
    }
}
