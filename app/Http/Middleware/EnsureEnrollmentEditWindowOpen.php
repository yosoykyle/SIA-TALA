<?php

namespace App\Http\Middleware;

use App\Actions\Calendar\CalendarPhaseGateService;
use App\Actions\Calendar\Exceptions\CalendarGateViolation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureEnrollmentEditWindowOpen
{
    public function __construct(private readonly CalendarPhaseGateService $calendarPhaseGateService) {}

    public function handle(Request $request, Closure $next): Response
    {
        $termId = (int) ($request->route('term_id') ?? $request->input('term_id', 0));

        if ($termId <= 0) {
            abort(422, 'term_id is required for enrollment edit gate checks.');
        }

        try {
            $this->calendarPhaseGateService->assertEnrollmentEditWindowOpen($termId);
        } catch (CalendarGateViolation $exception) {
            abort(423, $exception->getMessage());
        }

        return $next($request);
    }
}
