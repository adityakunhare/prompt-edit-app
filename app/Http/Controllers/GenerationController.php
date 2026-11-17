<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreGenerationRequest;
use App\Services\SubmitGenerationAction;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class GenerationController extends Controller
{
    public function __invoke(StoreGenerationRequest $request, SubmitGenerationAction $submitGeneration): RedirectResponse
    {
        try {
            $submitGeneration->handle($request->user(), $request->validated());
        } catch (RequestException $exception) {
            $providerError = $exception->response->json('error');
            $providerError = is_array($providerError) ? $providerError : [];
            $errors = $providerError['errors'] ?? null;

            if (! is_array($errors) || $errors === []) {
                $parameter = $providerError['param'] ?? 'prompt';
                $parameter = is_string($parameter) ? $parameter : 'prompt';
                $message = $providerError['message'] ?? 'Pixelforge could not accept this generation request.';
                $errors = [$parameter => $message];
            }

            $providerError['status'] = $exception->response->status();
            $retryAfter = $exception->response->header('Retry-After');

            if ($retryAfter !== null && $retryAfter !== '') {
                $providerError['retry_after'] = $retryAfter;
            }

            return Inertia::flash('pixelforge_error', $providerError)
                ->back()
                ->withErrors($errors);
        }

        return Inertia::flash('status', 'generation_queued')->back();
    }
}
