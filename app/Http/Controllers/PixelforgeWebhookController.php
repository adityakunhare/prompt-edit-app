<?php

namespace App\Http\Controllers;

use App\Services\ProcessPixelforgeWebhookAction;
use App\Services\VerifyPixelforgeWebhookSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PixelforgeWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        VerifyPixelforgeWebhookSignature $signatureVerifier,
        ProcessPixelforgeWebhookAction $processWebhook,
    ): JsonResponse {
        if (! $signatureVerifier->isValid($request)) {
            return response()->json(['message' => 'Invalid Pixelforge webhook signature.'], 401);
        }

        $event = $request->json()->all();

        if ($event === []) {
            return response()->json(['message' => 'Invalid Pixelforge webhook payload.'], 422);
        }

        $isNewEvent = $processWebhook->handle($event);

        return response()->json(['received' => true], $isNewEvent ? 202 : 200);
    }
}
