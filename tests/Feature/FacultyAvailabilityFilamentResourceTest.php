<?php

namespace Tests\Feature;

use Tests\TestCase;

class FacultyAvailabilityFilamentResourceTest extends TestCase
{
    public function test_period_resource_is_registrar_owned_and_service_backed(): void
    {
        $resource = $this->resourceSource('FacultyAvailabilityPeriods/FacultyAvailabilityPeriodResource.php');
        $form = $this->resourceSource('FacultyAvailabilityPeriods/Schemas/FacultyAvailabilityPeriodForm.php');
        $table = $this->resourceSource('FacultyAvailabilityPeriods/Tables/FacultyAvailabilityPeriodsTable.php');
        $createPage = $this->resourceSource('FacultyAvailabilityPeriods/Pages/CreateFacultyAvailabilityPeriod.php');
        $editPage = $this->resourceSource('FacultyAvailabilityPeriods/Pages/EditFacultyAvailabilityPeriod.php');

        $this->assertStringContainsString("'Registrar'", $resource);
        $this->assertStringContainsString('Availability Periods', $resource);
        $this->assertStringContainsString("Select::make('term_id')", $form);
        $this->assertStringContainsString("DateTimePicker::make('opens_at')", $form);
        $this->assertStringContainsString("DateTimePicker::make('closes_at')", $form);
        $this->assertStringContainsString('FacultyAvailabilityService', $createPage);
        $this->assertStringContainsString('preparePeriodData', $createPage);
        $this->assertStringContainsString('FacultyAvailabilityService', $editPage);
        $this->assertStringContainsString('preparePeriodData', $editPage);
        $this->assertStringNotContainsString('DeleteAction::make()', $table);
        $this->assertStringNotContainsString('DeleteBulkAction::make()', $table);
        $this->assertStringNotContainsString('DeleteAction::make()', $editPage);
    }

    public function test_submission_resource_uses_submit_and_lock_flow_not_generic_crud(): void
    {
        $resource = $this->resourceSource('FacultyAvailabilitySubmissions/FacultyAvailabilitySubmissionResource.php');
        $form = $this->resourceSource('FacultyAvailabilitySubmissions/Schemas/FacultyAvailabilitySubmissionForm.php');
        $table = $this->resourceSource('FacultyAvailabilitySubmissions/Tables/FacultyAvailabilitySubmissionsTable.php');
        $createPage = $this->resourceSource('FacultyAvailabilitySubmissions/Pages/CreateFacultyAvailabilitySubmission.php');
        $viewPage = $this->resourceSource('FacultyAvailabilitySubmissions/Pages/ViewFacultyAvailabilitySubmission.php');
        $provider = file_get_contents(app_path('Providers/AppServiceProvider.php'));

        $this->assertIsString($provider);
        $this->assertStringContainsString('FacultyAvailabilitySubmissionPolicy::class', $provider);
        $this->assertStringContainsString('StaffRoleFaculty', $resource);
        $this->assertStringContainsString('StaffRoleAcademicHead', $resource);
        $this->assertStringContainsString("'Registrar'", $resource);
        $this->assertStringContainsString('getEloquentQuery', $resource);
        $this->assertStringContainsString("Select::make('availability_period_id')", $form);
        $this->assertStringContainsString("Repeater::make('windows')", $form);
        $this->assertStringContainsString("Select::make('day_of_week')", $form);
        $this->assertStringContainsString("TimePicker::make('starts_at')", $form);
        $this->assertStringContainsString("TimePicker::make('ends_at')", $form);
        $this->assertStringNotContainsString("TextInput::make('faculty_id')", $form);
        $this->assertStringNotContainsString("TextInput::make('term_id')", $form);
        $this->assertStringNotContainsString("Select::make('status')", $form);
        $this->assertStringContainsString('submitAvailability', $createPage);
        $this->assertStringContainsString("Action::make('lockAvailability')", $table);
        $this->assertStringContainsString('FacultyAvailabilityService', $table);
        $this->assertStringContainsString('lockSubmission', $table);
        $this->assertStringNotContainsString('EditAction::make()', $table);
        $this->assertStringNotContainsString('DeleteAction::make()', $table);
        $this->assertStringNotContainsString('DeleteBulkAction::make()', $table);
        $this->assertStringNotContainsString('EditAction::make()', $viewPage);
        $this->assertStringNotContainsString('EditFacultyAvailabilitySubmission::route', $resource);
        $this->assertFileDoesNotExist(app_path('Filament/Resources/FacultyAvailabilitySubmissions/Pages/EditFacultyAvailabilitySubmission.php'));
    }

    private function resourceSource(string $relativePath): string
    {
        $source = file_get_contents(app_path("Filament/Resources/{$relativePath}"));

        $this->assertIsString($source);

        return $source;
    }
}
