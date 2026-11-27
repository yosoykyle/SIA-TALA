<?php

namespace App\Filament\Resources\StudentProfiles\Pages;

use App\Filament\Concerns\HasAcademicOversightReturn;
use App\Filament\Pages\GradesAndCompletion;
use App\Filament\Resources\Enrollments\EnrollmentResource;
use App\Filament\Resources\StudentProfiles\StudentOnboardingActions;
use App\Filament\Resources\StudentProfiles\StudentProfileResource;
use Filament\Resources\Pages\ListRecords;
use Livewire\Attributes\Url;

class ListStudentProfiles extends ListRecords
{
    use HasAcademicOversightReturn;

    protected static string $resource = StudentProfileResource::class;

    #[Url]
    public string $viewTab = 'academic-progress';

    /** @return array<string|int, string> */
    public function getBreadcrumbs(): array
    {
        if ($this->isAcademicHeadReader()) {
            return [
                $this->oversightReturnUrl() => 'Academic Oversight',
                'Student Profiles',
            ];
        }

        if ($this->viewTab === 'academic-progress') {
            return [
                GradesAndCompletion::getUrl(['viewTab' => 'academic-progress']) => 'Students Record',
                'Student Profiles',
            ];
        }

        return [
            EnrollmentResource::getUrl('index') => 'Students & Enrollment',
            'Student Profiles',
        ];
    }

    protected function getHeaderActions(): array
    {
        if ($this->isAcademicHeadReader()) {
            return [$this->returnToOversightAction()];
        }

        if ($this->isRegistrarActor()) {
            return StudentOnboardingActions::make(
                isVisible: fn (): bool => $this->isRegistrarActor(),
            );
        }

        return $this->isAcademicHead()
            ? [$this->returnToOversightAction()]
            : [];
    }
}
