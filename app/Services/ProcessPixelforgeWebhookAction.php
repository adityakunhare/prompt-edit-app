<?php

namespace App\Services;

use App\Models\Generation;
use App\Models\PixelforgeWebhookEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ProcessPixelforgeWebhookAction
{
    private const array STATUS_ORDER = [
        'queued' => 0,
        'processing' => 1,
        'succeeded' => 2,
        'failed' => 2,
    ];

    public function __construct(private QueueGenerationImageDownloadAction $queueImageDownload) {}

    /**
     * Record an event and apply it immediately when its generation is known.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload): bool
    {
        $validated = Validator::make($payload, [
            'id' => ['required', 'string', 'max:255'],
            'data' => ['required', 'array'],
            'data.id' => ['required', 'string', 'max:255'],
            'data.status' => ['required', 'string', Rule::in(array_keys(self::STATUS_ORDER))],
            'data.image_url' => ['sometimes', 'nullable', 'string'],
            'data.image_url_expires_at' => ['sometimes', 'nullable', 'date'],
            'data.usage' => ['sometimes', 'nullable', 'array'],
            'data.rates' => ['sometimes', 'nullable', 'array'],
            'data.error' => ['sometimes', 'nullable', 'array'],
            'data.created_at' => ['sometimes', 'nullable', 'date'],
            'data.started_at' => ['sometimes', 'nullable', 'date'],
            'data.completed_at' => ['sometimes', 'nullable', 'date'],
        ])->validate();

        $generationData = $validated['data'];
        $webhookEvent = PixelforgeWebhookEvent::query()->firstOrCreate(
            ['event_id' => $validated['id']],
            [
                'pixelforge_generation_id' => $generationData['id'],
                'payload' => $payload,
            ],
        );
        $isNewEvent = $webhookEvent->wasRecentlyCreated;

        if ($webhookEvent->processed_at === null) {
            $generation = $this->applyEvent($webhookEvent->event_id);

            if ($generation !== null) {
                $this->queueImageDownload->handle($generation);
            }
        }

        return $isNewEvent;
    }

    public function reconcile(string $pixelforgeGenerationId): void
    {
        $pendingEventIds = PixelforgeWebhookEvent::query()
            ->where('pixelforge_generation_id', $pixelforgeGenerationId)
            ->whereNull('processed_at')
            ->orderBy('id')
            ->pluck('event_id');

        foreach ($pendingEventIds as $eventId) {
            $this->applyEvent($eventId);
        }
    }

    private function applyEvent(string $eventId): ?Generation
    {
        return DB::transaction(function () use ($eventId): ?Generation {
            $webhookEvent = PixelforgeWebhookEvent::query()
                ->where('event_id', $eventId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($webhookEvent->processed_at !== null) {
                return null;
            }

            $generation = Generation::query()
                ->where('pixelforge_id', $webhookEvent->pixelforge_generation_id)
                ->lockForUpdate()
                ->first();

            if ($generation === null) {
                return null;
            }

            $generationData = $webhookEvent->payload['data'];
            $incomingStatus = $generationData['status'];

            if ($this->canApplyStatus($generation->status, $incomingStatus)) {
                $generation->fill($this->generationAttributes($generationData))->save();
            }

            $webhookEvent->update(['processed_at' => now()]);

            return $generation;
        });
    }

    /**
     * @param  array<string, mixed>  $generationData
     * @return array<string, mixed>
     */
    private function generationAttributes(array $generationData): array
    {
        $attributes = ['status' => $generationData['status']];

        foreach ([
            'image_url' => 'image_url',
            'image_url_expires_at' => 'image_url_expires_at',
            'usage' => 'usage',
            'rates' => 'rates',
            'error' => 'error',
            'created_at' => 'pixelforge_created_at',
            'started_at' => 'started_at',
            'completed_at' => 'completed_at',
        ] as $providerAttribute => $generationAttribute) {
            if (array_key_exists($providerAttribute, $generationData)) {
                $attributes[$generationAttribute] = $generationData[$providerAttribute];
            }
        }

        return $attributes;
    }

    private function canApplyStatus(string $currentStatus, string $incomingStatus): bool
    {
        if (! array_key_exists($currentStatus, self::STATUS_ORDER)) {
            return false;
        }

        if ($currentStatus === $incomingStatus) {
            return true;
        }

        $isTerminal = in_array($currentStatus, ['succeeded', 'failed'], true);

        return ! $isTerminal && self::STATUS_ORDER[$incomingStatus] >= self::STATUS_ORDER[$currentStatus];
    }
}
