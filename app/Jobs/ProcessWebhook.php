<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Models\WebhookEvent;
use App\Services\GenerationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class ProcessWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public string $eventId) {}

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
            if (str_starts_with($event->type, 'generation.')) {
                $object = $event->payload['data']['object'];
                $generation = Generation::where('provider_id', $object['id'])->first();
                if (! $generation && isset($object['metadata']['local_generation_id'])) {
                    $generation = Generation::find($object['metadata']['local_generation_id']);
                }
                if ($generation) {
                    $service->apply($generation, $object);
                }
            }
            $event->update(['processed_at' => now()]);
        }, 5);
    }
}
