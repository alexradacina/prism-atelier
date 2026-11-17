<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Models\WebhookEvent;
use App\Services\GenerationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public string $eventId, public bool $force = false) {}

    public function backoff(): array
    {
        return [5, 15, 30, 60];
    }

    public function handle(GenerationService $service): void
    {
        DB::transaction(function () use ($service) {
            $event = WebhookEvent::query()->lockForUpdate()->findOrFail($this->eventId);
            if ($event->processed_at) {
                return;
            }
            if (! $this->force && ($event->status === 'failed' || ($event->status === 'unmatched' &&
                ($event->processing_attempts >= 6 || $event->next_attempt_at?->isFuture())))) {
                return;
            }
            $event->processing_attempts++;
            $event->last_attempted_at = now();
            if (str_starts_with($event->type, 'generation.')) {
                $object = $event->payload['data']['object'];
                $generation = Generation::where('provider_id', $object['id'])->first();
                if (! $generation && isset($object['metadata']['local_generation_id'])) {
                    $generation = Generation::find($object['metadata']['local_generation_id']);
                }
                if ($generation) {
                    $service->apply($generation, $object);
                } else {
                    $firstUnmatched = $event->status !== 'unmatched';
                    $event->fill(['status' => 'unmatched', 'last_error' => 'No local generation matches this event.',
                        'next_attempt_at' => $event->processing_attempts >= 6 ? null : now()->addSeconds([30, 120, 600, 1800, 3600][$event->processing_attempts - 1])])->save();
                    if ($firstUnmatched) {
                        Log::warning('Unmatched Pixelforge webhook requires reconciliation.', ['event_id' => $event->id, 'provider_id' => $object['id']]);
                    }

                    return;
                }
            }
            $event->fill(['status' => 'processed', 'processed_at' => now(), 'next_attempt_at' => null, 'last_error' => null])->save();
        }, 5);
    }

    public function failed(?\Throwable $exception): void
    {
        WebhookEvent::whereKey($this->eventId)->whereNull('processed_at')->update([
            'status' => 'failed', 'last_error' => 'Processing failed. Inspect Horizon and replay this stored event.',
        ]);
    }
}
