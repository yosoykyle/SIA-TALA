<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\AdmissionCapacityPlan;
use App\Models\AdmissionOffering;
use App\Models\ApplicantIntake;
use App\Models\FeeTemplate;
use App\Models\InstallmentPolicy;
use App\Models\StudentProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CollegeOnlyScopeGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_college_only_tables_do_not_keep_education_level_discriminator(): void
    {
        $models = [
            new AcademicYear,
            new AdmissionCapacityPlan,
            new AdmissionOffering,
            new ApplicantIntake,
            new FeeTemplate,
            new InstallmentPolicy,
            new StudentProfile,
        ];

        foreach ($models as $model) {
            $this->assertFalse(
                Schema::hasColumn($model->getTable(), 'education_level'),
                "{$model->getTable()} should not keep an education_level discriminator.",
            );

            $this->assertNotContains('education_level', $model->getFillable());
        }
    }
}
