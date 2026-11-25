<?php

namespace App\Filament\Resources\AcademicYears\Pages;

use App\Filament\Concerns\HasAcademicSetupBreadcrumbs;
use App\Filament\Resources\AcademicYears\AcademicYearResource;
use App\Models\AcademicYear;
use App\Models\Term;
use Carbon\CarbonImmutable;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class EditAcademicYear extends EditRecord
{
    use HasAcademicSetupBreadcrumbs;

    protected static string $resource = AcademicYearResource::class;

    /** @return array<string|int, string> */
    public function getBreadcrumbs(): array
    {
        return [
            ...$this->academicSetupParentBreadcrumb(),
            AcademicYearResource::getUrl('index') => 'Academic Years',
            AcademicYearResource::getUrl('view', ['record' => $this->getRecord()]) => $this->getRecord()->label,
            'Edit',
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return DB::transaction(function () use ($record, $data): AcademicYear {
            $locked = AcademicYear::query()->whereKey($record)->lockForUpdate()->firstOrFail();
            Gate::authorize('update', $locked);

            $newStart = CarbonImmutable::parse((string) ($data['starts_on'] ?? $locked->starts_on))->startOfDay();
            $newEnd = CarbonImmutable::parse((string) ($data['ends_on'] ?? $locked->ends_on))->startOfDay();

            $outsideTerm = $locked->terms()
                ->where(function (Builder $query) use ($newStart, $newEnd): void {
                    $query->whereDate('starts_on', '<', $newStart->toDateString())
                        ->orWhereDate('ends_on', '>', $newEnd->toDateString());
                })
                ->first();

            if ($outsideTerm instanceof Term) {
                throw ValidationException::withMessages([
                    'starts_on' => sprintf(
                        'Cannot change dates because %s (%s – %s) would fall outside this academic year.',
                        $outsideTerm->typeLabel(),
                        $outsideTerm->starts_on->format('M j, Y'),
                        $outsideTerm->ends_on->format('M j, Y'),
                    ),
                ]);
            }

            $newState = (string) ($data['state'] ?? $locked->state);
            $isClosingOrDeactivating = in_array($newState, [AcademicYear::StateClosed, AcademicYear::StateArchived], true)
                || ($newState !== AcademicYear::StateActive && $newState !== $locked->state);

            if ($isClosingOrDeactivating
                && $locked->terms()->whereIn('state', [Term::StateActive, Term::StateDraft])->exists()) {
                throw ValidationException::withMessages([
                    'state' => 'Cannot close or deactivate this academic year while active or draft terms still exist under it. Close or remove those terms first.',
                ]);
            }

            $locked->update($data);

            return $locked;
        }, attempts: 3);
    }
}
