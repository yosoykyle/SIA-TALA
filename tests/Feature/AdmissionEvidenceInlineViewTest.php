<?php

namespace Tests\Feature;

use App\Models\AdmissionApplication;
use App\Models\AdmissionCycle;
use App\Models\AdmissionRequirement;
use App\Models\AdmissionRequirementSet;
use App\Models\DocumentEvidence;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdmissionEvidenceInlineViewTest extends TestCase
{
    use DatabaseTransactions;

    public function test_authorized_pdf_and_image_versions_are_inline_and_download_remains_attachment(): void
    {
        Storage::fake('local');
        $jpeg = UploadedFile::fake()->image('review.jpg');
        $copies = [
            'image/jpeg' => file_get_contents($jpeg->getRealPath()),
            'application/pdf' => "%PDF-1.4\nprivate review copy\n%%EOF",
            'image/png' => base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jhRkAAAAASUVORK5CYII='),
        ];

        foreach ($copies as $mime => $contents) {
            $evidence = $this->evidence($contents);
            $this->actingAs($evidence->admissionApplication->user)
                ->get(route('admissions.evidence.view', $evidence))->assertOk()
                ->assertHeader('Content-Type', $mime)->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('Cache-Control', 'max-age=0, no-store, private');
            $this->assertStringStartsWith('inline;', $this->get(route('admissions.evidence.view', $evidence))->headers->get('Content-Disposition'));
            $this->assertStringStartsWith('attachment;', $this->get(route('admissions.evidence.download', $evidence))->headers->get('Content-Disposition'));
        }
    }

    public function test_inline_view_requires_authentication_and_application_ownership(): void
    {
        Storage::fake('local');
        $evidence = $this->evidence("%PDF-1.4\nprivate\n%%EOF");
        $this->getJson(route('admissions.evidence.view', $evidence))->assertUnauthorized();
        $this->actingAs(User::factory()->create(['status' => User::StatusActive]))
            ->getJson(route('admissions.evidence.view', $evidence))->assertForbidden();
    }

    public function test_inline_view_rejects_forged_paths_and_missing_files(): void
    {
        Storage::fake('local');
        $evidence = $this->evidence("%PDF-1.4\nprivate\n%%EOF");
        $this->actingAs($evidence->admissionApplication->user);
        $path = $evidence->path;
        $requirementId = $evidence->admission_requirement_id;
        $evidence->update(['path' => '../outside-private-boundary.pdf']);
        $this->getJson(route('admissions.evidence.view', $evidence))->assertUnprocessable()->assertInvalid(['evidence']);
        $evidence->update(['path' => $path, 'admission_requirement_id' => AdmissionRequirement::factory()->create([
            'admission_requirement_set_id' => $evidence->admissionRequirement->admission_requirement_set_id,
        ])->id]);
        $this->getJson(route('admissions.evidence.view', $evidence))->assertUnprocessable()->assertInvalid(['evidence']);
        $evidence->update(['admission_requirement_id' => $requirementId, 'path' => "admission-applications/{$evidence->admission_application_id}/requirements/{$requirementId}/missing.pdf"]);
        $this->getJson(route('admissions.evidence.view', $evidence))->assertUnprocessable()
            ->assertInvalid(['evidence' => 'The private evidence file is unavailable. Contact the Registrar.']);
    }

    public function test_inline_view_uses_detected_content_type_and_rejects_active_content(): void
    {
        Storage::fake('local');
        $evidence = $this->evidence('<html><script>alert(1)</script></html>');
        $this->actingAs($evidence->admissionApplication->user)
            ->get(route('admissions.evidence.view', $evidence))->assertStatus(415);
    }

    private function evidence(string $contents): DocumentEvidence
    {
        $application = AdmissionApplication::factory()->create([
            'admission_cycle_id' => AdmissionCycle::factory()->create([
                'term_id' => Term::query()->value('id') ?? Term::factory()->create()->id,
            ])->id,
        ]);
        Role::findOrCreate('applicant', 'web');
        $application->user->assignRole('applicant');
        $set = AdmissionRequirementSet::factory()->for($application->admissionCycle)->create();
        $requirement = AdmissionRequirement::factory()->for($set, 'requirementSet')->create();
        $path = "admission-applications/{$application->id}/requirements/{$requirement->id}/review.pdf";
        $evidence = DocumentEvidence::factory()->canonical($application, $requirement)->create(['path' => $path]);
        Storage::disk('local')->put($path, $contents);

        return $evidence;
    }
}
