<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class StudentHubScopeResetTest extends TestCase
{
    public function test_old_authenticated_student_dashboard_surface_is_removed_from_active_scope(): void
    {
        foreach ([
            'student.dashboard',
            'student.cor',
            'student.schedule',
            'student.grades',
            'student.financials',
            'student.help',
        ] as $routeName) {
            $this->assertFalse(Route::has($routeName), "{$routeName} belongs to the old authenticated /student dashboard surface and should not be registered.");
        }

        $this->assertFileDoesNotExist(app_path('Http/Middleware/EnsureActiveStudentHubUser.php'));
        $this->assertFileDoesNotExist(resource_path('views/layouts/student.blade.php'));
        $this->assertFileDoesNotExist(resource_path('views/pages/student-hub/⚡dashboard.blade.php'));
        $this->assertFileDoesNotExist(resource_path('views/pages/student-hub/⚡cor.blade.php'));
        $this->assertFileDoesNotExist(resource_path('views/components/student/page-header.blade.php'));
    }
}
