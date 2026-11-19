<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL82DImportTemplateAcceptanceTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function catalog_csv_screens_and_source_download_endpoints_are_retired(): void
    {
        foreach ([User::StaffRoleRegistrar, User::StaffRoleAcademicHead] as $role) {
            Role::findOrCreate($role, 'web');
            $user = User::factory()->create(['status' => User::StatusActive]);
            $user->assignRole($role);
            $this->actingAs($user);
            foreach (['/admin/import-batches', '/admin/import-batches/create', '/admin/import-batches/retired-batch'] as $url) {
                $this->get($url)->assertNotFound();
            }
        }
    }
}
