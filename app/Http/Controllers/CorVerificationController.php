<?php

namespace App\Http\Controllers;

use App\Actions\Registrar\CorVerificationLifecycleService;
use Illuminate\Contracts\View\View;

class CorVerificationController extends Controller
{
    public function __invoke(string $token, CorVerificationLifecycleService $service): View
    {
        return view('cor-verifications.show', [
            'result' => $service->verificationResult($token),
        ]);
    }
}
