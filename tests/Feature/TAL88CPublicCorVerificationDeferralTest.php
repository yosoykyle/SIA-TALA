<?php

namespace Tests\Feature;

use App\Http\Controllers\CorPrintController;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

class TAL88CPublicCorVerificationDeferralTest extends TestCase
{
    public function test_public_cor_verification_routes_and_controller_are_deferred_for_v1(): void
    {
        $this->assertFalse(
            file_exists(app_path('Http/Controllers/CorVerificationController.php')),
            'Public COR verification controller must remain deferred until an approved policy activates public lookup.',
        );

        $this->assertFalse(RouteFacade::has('cor.verify'));
        $this->assertFalse(RouteFacade::has('cor.verifications.show'));
        $this->assertFalse(RouteFacade::has('cor-verifications.show'));

        $publicCorVerificationRoutes = collect(RouteFacade::getRoutes())
            ->filter(fn (Route $route): bool => $this->isPublicCorVerificationRoute($route))
            ->map(fn (Route $route): string => implode('|', $route->methods()).' '.$route->uri())
            ->values()
            ->all();

        $this->assertSame([], $publicCorVerificationRoutes);
    }

    public function test_authenticated_cor_print_route_remains_available(): void
    {
        $route = RouteFacade::getRoutes()->getByName('cor.print');

        $this->assertTrue(RouteFacade::has('cor.print'));
        $this->assertInstanceOf(Route::class, $route);
        $this->assertSame(CorPrintController::class, $route->getControllerClass());
        $this->assertContains('auth', $route->gatherMiddleware());
    }

    private function isPublicCorVerificationRoute(Route $route): bool
    {
        $uri = $route->uri();
        $name = $route->getName() ?? '';
        $controller = $route->getControllerClass() ?? '';

        if (str_contains($controller, 'CorVerificationController')) {
            return true;
        }

        if (str_contains($uri, 'cor-verification') || str_contains($uri, 'cor/verify')) {
            return true;
        }

        if (str_contains($name, 'cor.verification') || str_contains($name, 'cor.verify')) {
            return true;
        }

        return false;
    }
}
