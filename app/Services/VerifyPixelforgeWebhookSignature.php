<?php

namespace App\Services;

use Illuminate\Http\Request;

class VerifyPixelforgeWebhookSignature
{
    public function isValid(Request $request): bool
    {
        $secret = (string) config('services.pixelforge.webhook_secret');
        $timestamp = $request->header('X-Pixelforge-Timestamp');
        $signature = $request->header('X-Pixelforge-Signature');

        if ($secret === '' || ! is_string($timestamp) || ! ctype_digit($timestamp) || strlen($timestamp) > 12) {
            return false;
        }

        if (abs(now()->timestamp - (int) $timestamp) > 300) {
            return false;
        }

        if (! is_string($signature)) {
            return false;
        }

        $expectedSignature = hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expectedSignature, $signature);
    }
}
