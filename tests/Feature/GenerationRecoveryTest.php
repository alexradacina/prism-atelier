<?php

namespace Tests\Feature;

use App\Jobs\ProcessWebhook;
use App\Jobs\SubmitGeneration;
use App\Jobs\SyncGeneration;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\GenerationService;
use App\Services\Money;
use App\Services\Pixelforge;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GenerationRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private function fundedGeneration(array $overrides = []): Generation
    {
        $user = User::factory()->create(['balance_nanos' => 1_000_000_000, 'reserved_nanos' => 90_000_000]);

        return Generation::factory()->for($user)->create($overrides);
    }

    private function event(string $id, array $object): WebhookEvent
    {
        return WebhookEvent::create(['id' => $id, 'type' => 'generation.succeeded',
            'payload' => ['id' => $id, 'type' => 'generation.succeeded', 'data' => ['object' => $object]]]);
    }

    public function test_submission_bypasses_cached_pricing_and_raises_the_hold_before_the_post(): void
    {
        Queue::fake();
        Cache::put('pixelforge.pricing', $this->pricing(), 60);
        $generation = $this->fundedGeneration(['quoted_pricing' => $this->pricing()]);
        $pricing = $this->pricing();
        $pricing['rates']['output_tokens'] = '400.00';
        $required = Money::estimate($generation->only(['prompt', 'size', 'quality', 'reference_images']), $pricing)['maximum_nanos'];
        Http::fake(['*/pricing' => Http::response($pricing), '*/generations' => function () use ($generation, $required, $pricing) {
            $this->assertSame($required, $generation->fresh()->reserved_nanos);
            $this->assertSame($required, $generation->user->fresh()->reserved_nanos);

            return Http::response($this->object('processing', ['rates' => $pricing['rates']]), 202);
        }]);
        (new SubmitGeneration($generation->id))->handle(app(Pixelforge::class), app(GenerationService::class));
        $this->assertGreaterThan(90_000_000, $required);
        $this->assertEquals($pricing, $generation->fresh()->submission_pricing);
        $this->assertSame('400.00', $generation->fresh()->submission_pricing['rates']['output_tokens']);
        $this->assertNotNull($generation->fresh()->pricing_checked_at);
        $this->assertStringContainsString('hold was increased', $generation->fresh()->display()['notice']);
        $this->assertSame(1_000_000_000, $generation->user->fresh()->balance_nanos);
        $this->assertSame(0, LedgerEntry::count());
        $usage = ['input_text_tokens' => 12, 'input_image_tokens' => 0, 'output_tokens' => 1100, 'reasoning_tokens' => 1000];
        $completed = $this->object('succeeded', ['rates' => $pricing['rates'], 'usage' => $usage]);
        $service = app(GenerationService::class);
        $service->apply($generation, $completed);
        $service->apply($generation, $completed);
        $charge = Money::charge(Money::cost($usage, $pricing['rates']));
        $this->assertSame($charge, $generation->fresh()->actual_nanos);
        $this->assertSame(1_000_000_000 - $charge, $generation->user->fresh()->balance_nanos);
        $this->assertSame(0, $generation->user->fresh()->reserved_nanos);
        $this->assertSame(1, LedgerEntry::count());
    }

    public function test_unaffordable_new_pricing_stops_before_submission_and_releases_the_original_hold(): void
    {
        $generation = $this->fundedGeneration();
        $generation->user->forceFill(['balance_nanos' => 90_000_000])->save();
        $pricing = $this->pricing();
        $pricing['rates']['output_tokens'] = '400.00';
        Http::fake(['*/pricing' => Http::response($pricing)]);
        (new SubmitGeneration($generation->id))->handle(app(Pixelforge::class), app(GenerationService::class));
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
        $this->assertSame('failed', $generation->fresh()->status);
        $this->assertSame(0, $generation->fresh()->submission_attempts);
        $this->assertSame(0, $generation->user->fresh()->reserved_nanos);
        $this->assertSame(90_000_000, $generation->user->fresh()->balance_nanos);
        $this->assertStringContainsString('Prices increased', $generation->fresh()->error_message);
        $this->assertSame(0, LedgerEntry::first()->amount_nanos);
    }

    public function test_an_uncertain_submission_is_not_refunded_when_a_new_hold_is_unaffordable(): void
    {
        $generation = $this->fundedGeneration(['submission_uncertain' => true, 'provider_slot' => true]);
        $generation->user->forceFill(['balance_nanos' => 90_000_000])->save();
        $pricing = $this->pricing();
        $pricing['rates']['output_tokens'] = '400.00';
        Http::fake(['*/pricing' => Http::response($pricing)]);
        (new SubmitGeneration($generation->id))->handle(app(Pixelforge::class), app(GenerationService::class));
        $this->assertSame('queued', $generation->fresh()->status);
        $this->assertSame(90_000_000, $generation->user->fresh()->reserved_nanos);
        $this->assertTrue($generation->fresh()->provider_slot);
        $this->assertSame(0, LedgerEntry::count());
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_repeated_repricing_does_not_reserve_the_increase_twice(): void
    {
        $generation = $this->fundedGeneration();
        $pricing = $this->pricing();
        $pricing['rates']['output_tokens'] = '400.00';
        $service = app(GenerationService::class);
        $this->assertTrue($service->refreshReservation($generation, $pricing));
        $required = $generation->fresh()->reserved_nanos;
        $this->assertTrue($service->refreshReservation($generation, $pricing));
        $this->assertSame($required, $generation->user->fresh()->reserved_nanos);
        $this->assertSame(1_000_000_000, $generation->user->fresh()->balance_nanos);
    }

    public function test_permanent_poll_errors_pause_without_refunding_and_scheduler_does_not_restart_them(): void
    {
        Queue::fake();
        Http::fake(['*/generations/*' => fn ($request) => Http::response(['error' => ['code' => 'not_available']], str_ends_with($request->url(), '401') ? 401 : 404)]);
        foreach ([401, 404] as $status) {
            $generation = $this->fundedGeneration(['provider_id' => 'gen_'.$status, 'created_at' => now()->subHour()]);
            $queue = \Mockery::mock(Job::class);
            $queue->shouldReceive('fail')->once();
            $job = new SyncGeneration($generation->id);
            $job->setJob($queue);
            $job->handle(app(Pixelforge::class), app(GenerationService::class));
            $this->assertNotNull($generation->fresh()->sync_paused_at);
            $this->assertSame($status, $generation->fresh()->sync_error_status);
            $this->assertSame(90_000_000, $generation->user->fresh()->reserved_nanos);
            $this->assertNull($generation->fresh()->actual_nanos);
            $this->assertStringContainsString('Support needs to investigate', $generation->fresh()->error_message);
            (new SyncGeneration($generation->id))->handle(app(Pixelforge::class), app(GenerationService::class));
        }
        $this->artisan('prism:reconcile')->assertSuccessful();
        Queue::assertNotPushed(SyncGeneration::class);
        Http::assertSentCount(2);
        $this->assertSame(0, LedgerEntry::count());
    }

    public function test_operator_can_resume_polling_and_a_late_webhook_can_settle_a_paused_generation(): void
    {
        Queue::fake();
        $generation = $this->fundedGeneration(['provider_id' => 'gen_test', 'sync_paused_at' => now(), 'sync_error_status' => 401, 'error_message' => 'Paused']);
        $this->artisan('prism:resume-polling', ['generation' => $generation->id])->assertSuccessful();
        Queue::assertPushed(SyncGeneration::class, fn ($job) => $job->generationId === $generation->id);
        $this->assertNull($generation->fresh()->sync_paused_at);
        $this->assertSame(90_000_000, $generation->user->fresh()->reserved_nanos);
        $generation->update(['sync_paused_at' => now(), 'sync_error_status' => 404]);
        app(GenerationService::class)->apply($generation, $this->object());
        $this->assertNull($generation->fresh()->sync_paused_at);
        $this->assertNull($generation->fresh()->error_message);
        $this->assertSame(1, LedgerEntry::count());
        $this->artisan('prism:resume-polling', ['generation' => $generation->id])->assertFailed();
    }

    public function test_unknown_webhook_is_visible_unprocessed_and_bounded_then_replay_settles_once(): void
    {
        Queue::fake();
        $this->travelTo(now()->startOfSecond());
        $event = $this->event('evt_missing', $this->object());
        $job = new ProcessWebhook($event->id);
        $service = app(GenerationService::class);
        foreach ([30, 120, 600, 1800, 3600, 0] as $delay) {
            $job->handle($service);
            $attempts = $event->fresh()->processing_attempts;
            $job->handle($service);
            $this->assertSame($attempts, $event->fresh()->processing_attempts);
            $this->travel($delay)->seconds();
        }
        $this->assertSame(6, $event->fresh()->processing_attempts);
        $this->assertSame('unmatched', $event->fresh()->status);
        $this->assertNull($event->fresh()->processed_at);
        $this->assertNull($event->fresh()->next_attempt_at);
        $this->artisan('prism:webhooks')->expectsOutputToContain('evt_missing')->assertSuccessful();
        $this->artisan('prism:reconcile')->assertSuccessful();
        Queue::assertNotPushed(ProcessWebhook::class);
        $generation = $this->fundedGeneration(['provider_id' => 'gen_test']);
        $this->artisan('prism:webhooks', ['--replay' => $event->id])->assertSuccessful();
        Queue::assertPushed(ProcessWebhook::class, fn ($queued) => $queued->eventId === $event->id && $queued->force);
        (new ProcessWebhook($event->id, force: true))->handle($service);
        (new ProcessWebhook($event->id, force: true))->handle($service);
        $this->assertSame('processed', $event->fresh()->status);
        $this->assertNotNull($event->fresh()->processed_at);
        $this->assertSame(7, $event->fresh()->processing_attempts);
        $this->assertSame(1, WebhookEvent::count());
        $this->assertSame(1, LedgerEntry::count());
        $this->assertSame(618_112_500, $generation->user->fresh()->balance_nanos);
    }

    public function test_unmatched_event_can_match_on_a_scheduled_retry(): void
    {
        Queue::fake();
        $this->travelTo(now()->startOfSecond());
        $event = $this->event('evt_late_match', $this->object());
        (new ProcessWebhook($event->id))->handle(app(GenerationService::class));
        $generation = $this->fundedGeneration(['provider_id' => 'gen_test']);
        $this->travel(31)->seconds();
        $this->artisan('prism:reconcile')->assertSuccessful();
        Queue::assertPushed(ProcessWebhook::class, fn ($job) => $job->eventId === $event->id);
        (new ProcessWebhook($event->id))->handle(app(GenerationService::class));
        $this->assertSame('succeeded', $generation->fresh()->status);
        $this->assertSame('processed', $event->fresh()->status);
    }

    public function test_failed_event_is_listed_and_can_be_replayed_without_changing_payload(): void
    {
        Queue::fake();
        $event = $this->event('evt_failed', $this->object())->fresh();
        (new ProcessWebhook($event->id))->failed(new \RuntimeException('Storage temporarily unavailable'));
        $this->assertSame('failed', $event->fresh()->status);
        $this->artisan('prism:webhooks')->expectsOutputToContain('evt_failed')->assertSuccessful();
        $this->artisan('prism:webhooks', ['--replay' => $event->id])->assertSuccessful();
        Queue::assertPushed(ProcessWebhook::class, fn ($job) => $job->eventId === $event->id && $job->force);
        $this->assertSame($event->payload, $event->fresh()->payload);
        $this->assertSame(1, WebhookEvent::count());
        $this->artisan('prism:webhooks', ['--replay' => 'evt_absent'])->assertFailed();
    }

    public function test_unavailable_fresh_pricing_stops_after_six_checks_without_a_provider_create(): void
    {
        $this->travelTo(now()->startOfSecond());
        $generation = $this->fundedGeneration();
        Http::fake(['*/pricing' => Http::response(['error' => ['message' => 'Unavailable']], 503)]);
        foreach ([5, 15, 30, 60, 120, null] as $delay) {
            $job = new SubmitGeneration($generation->id);
            if ($delay !== null) {
                $queue = \Mockery::mock(Job::class);
                $queue->shouldReceive('release')->once()->with($delay);
                $job->setJob($queue);
            }
            $job->handle(app(Pixelforge::class), app(GenerationService::class));
            if ($delay !== null) {
                $this->travel($delay)->seconds();
            }
        }
        Http::assertSentCount(6);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
        $this->assertSame(6, $generation->fresh()->pricing_failures);
        $this->assertSame(0, $generation->fresh()->submission_attempts);
        $this->assertSame('failed', $generation->fresh()->status);
        $this->assertSame(0, $generation->user->fresh()->reserved_nanos);
    }
}
