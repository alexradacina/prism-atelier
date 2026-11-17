<?php

namespace Tests\Feature;

use App\Jobs\ProcessWebhook;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\GenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    private function deliver(array $event, ?int $timestamp = null, string $secret = 'whsec_test'): TestResponse
    {
        $body = json_encode($event, JSON_THROW_ON_ERROR);
        $timestamp ??= time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        return $this->call('POST', '/webhooks/pixelforge', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_PIXELFORGE_SIGNATURE' => 't='.$timestamp.',v1='.$signature,
        ], $body);
    }

    private function event(string $id, string $status, array $overrides = []): array
    {
        return ['id' => $id, 'type' => 'generation.'.$status, 'data' => ['object' => $this->object($status, $overrides)]];
    }

    public function test_bad_or_stale_signatures_are_rejected_before_recording(): void
    {
        $event = ['id' => 'evt_ping', 'type' => 'ping'];
        $this->deliver($event, secret: 'wrong')->assertStatus(400);
        $this->deliver($event, time() - 301)->assertStatus(400);
        $this->deliver($event, time() + 301)->assertStatus(400);
        $this->assertSame(0, WebhookEvent::count());
    }

    public function test_duplicate_event_is_recorded_once_and_settled_once(): void
    {
        Queue::fake();
        $user = User::factory()->create(['balance_nanos' => 1_000_000_000, 'reserved_nanos' => 90_000_000]);
        $generation = Generation::factory()->for($user)->create(['provider_id' => 'gen_test']);
        $event = $this->event('evt_once', 'succeeded');
        $this->deliver($event)->assertNoContent();
        $this->deliver($event)->assertNoContent();
        $job = new ProcessWebhook('evt_once');
        $job->handle(app(GenerationService::class));
        $job->handle(app(GenerationService::class));
        $this->assertSame(1, WebhookEvent::count());
        $this->assertNotNull(WebhookEvent::first()->processed_at);
        $this->assertSame(1, LedgerEntry::count());
        $this->assertSame('succeeded', $generation->fresh()->status);
    }

    public function test_late_processing_event_cannot_reverse_success(): void
    {
        Queue::fake();
        $user = User::factory()->create(['balance_nanos' => 1_000_000_000, 'reserved_nanos' => 90_000_000]);
        $generation = Generation::factory()->for($user)->create(['provider_id' => 'gen_test']);
        $this->deliver($this->event('evt_success', 'succeeded'))->assertNoContent();
        (new ProcessWebhook('evt_success'))->handle(app(GenerationService::class));
        $this->deliver($this->event('evt_processing', 'processing'))->assertNoContent();
        (new ProcessWebhook('evt_processing'))->handle(app(GenerationService::class));
        $this->assertSame('succeeded', $generation->fresh()->status);
        $this->assertSame(1, LedgerEntry::count());
        $this->assertSame(2, WebhookEvent::count());
    }

    public function test_early_webhook_links_by_local_metadata_before_submission_response(): void
    {
        Queue::fake();
        $user = User::factory()->create(['balance_nanos' => 1_000_000_000, 'reserved_nanos' => 90_000_000]);
        $generation = Generation::factory()->for($user)->create();
        $this->deliver($this->event('evt_early', 'succeeded', ['metadata' => ['local_generation_id' => $generation->id]]))->assertNoContent();
        (new ProcessWebhook('evt_early'))->handle(app(GenerationService::class));
        app(GenerationService::class)->apply($generation, $this->object('queued'));
        $this->assertSame('succeeded', $generation->fresh()->status);
        $this->assertSame('gen_test', $generation->fresh()->provider_id);
        $this->assertSame(1, LedgerEntry::count());
    }

    public function test_unsigned_requests_and_missing_configuration_fail_closed(): void
    {
        $this->postJson('/webhooks/pixelforge', ['type' => 'ping'])->assertStatus(400);
        config(['services.pixelforge.webhook_secret' => null]);
        $this->deliver(['id' => 'evt_ping', 'type' => 'ping'])->assertStatus(503);
    }

    public function test_ping_is_durable_and_processes_without_a_generation(): void
    {
        Queue::fake();
        $this->deliver(['id' => 'evt_ping', 'type' => 'ping'])->assertNoContent();
        (new ProcessWebhook('evt_ping'))->handle(app(GenerationService::class));
        $this->assertNotNull(WebhookEvent::first()->processed_at);
        $this->assertSame(0, Generation::count());
    }

    public function test_failure_then_late_processing_remains_free(): void
    {
        Queue::fake();
        $user = User::factory()->create(['balance_nanos' => 1_000_000_000, 'reserved_nanos' => 90_000_000]);
        $generation = Generation::factory()->for($user)->create(['provider_id' => 'gen_test']);
        foreach (['failed', 'processing'] as $status) {
            $this->deliver($this->event('evt_'.$status, $status))->assertNoContent();
            (new ProcessWebhook('evt_'.$status))->handle(app(GenerationService::class));
        }
        $this->assertSame('failed', $generation->fresh()->status);
        $this->assertSame(0, $generation->fresh()->actual_nanos);
        $this->assertSame(1_000_000_000, $user->fresh()->balance_nanos);
    }
}
