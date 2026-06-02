<?php

namespace App\Http\Controllers;

use App\Actions\Integrations\Payments\PayMongoWebhookSignatureVerifier;
use App\Jobs\ProcessPayMongoWebhookCall;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PayMongoWebhookController extends Controller
{
    public function __invoke(Request $request, PayMongoWebhookSignatureVerifier $signatureVerifier): JsonResponse
    {
        if (! $signatureVerifier->isValid($request)) {
            return response()->json([
                'message' => 'Invalid PayMongo webhook signature.',
            ], 401);
        }

        $payload = $request->json()->all();
        $now = CarbonImmutable::now(config('app.timezone'))->toDateTimeString();

        $webhookCallId = DB::table('webhook_calls')->insertGetId([
            'name' => 'paymongo',
            'url' => $request->fullUrl(),
            'headers' => json_encode($request->headers->all(), JSON_UNESCAPED_SLASHES),
            'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        ProcessPayMongoWebhookCall::dispatch($webhookCallId);

        return response()->json([
            'status' => 'accepted',
            'webhook_call_id' => $webhookCallId,
        ], 202);
    }
}
