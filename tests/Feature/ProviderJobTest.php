<?php

namespace Tests\Feature;

use App\Jobs\SubmitGeneration;
use App\Jobs\SyncGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Services\GenerationService;
use App\Services\Pixelforge;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ProviderJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_submission_uses_stable_idempotency_key_and_links_metadata(): void
    {
        Queue::fake();
        $generation = Generation::factory()->create();
        Http::fake(['*/pricing' => Http::response($this->pricing()), '*/generations' => Http::response($this->object('queued'), 202)]);
        (new SubmitGeneration($generation->id))->handle(app(Pixelforge::class), app(GenerationService::class));
        Http::assertSent(fn ($request) => $request->hasHeader('Idempotency-Key', $generation->id)
            && $request['metadata']['local_generation_id'] === $generation->id
            && $request['prompt'] === $generation->prompt);
        Queue::assertPushed(SyncGeneration::class);
        $this->assertSame('gen_test', $generation->fresh()->provider_id);
    }

    public function test_definitive_rejection_costs_nothing(): void
    {
        $user = User::factory()->create(['balance_nanos' => 1_000_000_000, 'reserved_nanos' => 90_000_000]);
        $generation = Generation::factory()->for($user)->create();
        Http::fake(['*/pricing' => Http::response($this->pricing()), '*/generations' => Http::response(['error' => ['message' => 'Prompt blocked.']], 400)]);
        (new SubmitGeneration($generation->id))->handle(app(Pixelforge::class), app(GenerationService::class));
        $this->assertSame('failed', $generation->fresh()->status);
        $this->assertSame(0, $generation->fresh()->actual_nanos);
        $this->assertSame(0, $user->fresh()->reserved_nanos);
    }

    public function test_polling_recovers_a_completion_without_webhooks(): void
    {
        Queue::fake();
        $user = User::factory()->create(['balance_nanos' => 1_000_000_000, 'reserved_nanos' => 90_000_000]);
        $generation = Generation::factory()->for($user)->create(['provider_id' => 'gen_test']);
        Http::fake(['*/generations/gen_test' => Http::response($this->object())]);
        (new SyncGeneration($generation->id))->handle(app(Pixelforge::class), app(GenerationService::class));
        $this->assertSame('succeeded', $generation->fresh()->status);
        $this->assertSame(381_887_500, $generation->fresh()->actual_nanos);
    }

    public function test_an_unconfirmed_submission_keeps_reservation_for_safe_recovery(): void
    {
        $user = User::factory()->create(['balance_nanos' => 1_000_000_000, 'reserved_nanos' => 90_000_000]);
        $generation = Generation::factory()->for($user)->create();
        (new SubmitGeneration($generation->id))->failed(new \RuntimeException('Network timeout'));
        $this->assertSame('queued', $generation->fresh()->status);
        $this->assertSame(90_000_000, $user->fresh()->reserved_nanos);
        $this->assertStringContainsString('could not be confirmed', $generation->fresh()->error_message);
    }

    public function test_provider_retry_after_is_respected_without_releasing_reserved_money(): void
    {
        $generation = Generation::factory()->create();
        Http::fake(['*/pricing' => Http::response($this->pricing()), '*/generations' => Http::response(['error' => ['message' => 'Too many requests.']], 429, ['Retry-After' => '45'])]);
        $queueJob = \Mockery::mock(Job::class);
        $queueJob->shouldReceive('attempts')->andReturn(1);
        $queueJob->shouldReceive('release')->once()->with(45);
        $job = new SubmitGeneration($generation->id);
        $job->setJob($queueJob);
        $job->handle(app(Pixelforge::class), app(GenerationService::class));
        $this->assertSame('queued', $generation->fresh()->status);
        $this->assertSame(90_000_000, $generation->fresh()->reserved_nanos);
    }

    public function test_exhausted_explicit_overload_rejections_are_free(): void
    {
        $user = User::factory()->create(['balance_nanos' => 1_000_000_000, 'reserved_nanos' => 90_000_000]);
        $generation = Generation::factory()->for($user)->create(['submission_attempts' => 5]);
        Http::fake(['*/pricing' => Http::response($this->pricing()), '*/generations' => Http::response(['error' => ['message' => 'Overloaded.']], 503)]);
        $queueJob = \Mockery::mock(Job::class);
        $queueJob->shouldReceive('attempts')->andReturn(6);
        $job = new SubmitGeneration($generation->id);
        $job->setJob($queueJob);
        $job->handle(app(Pixelforge::class), app(GenerationService::class));
        $this->assertSame('failed', $generation->fresh()->status);
        $this->assertSame(0, $user->fresh()->reserved_nanos);
        $this->assertSame(1_000_000_000, $user->fresh()->balance_nanos);
    }

    public function test_polling_obeys_provider_retry_after(): void
    {
        $generation = Generation::factory()->create(['provider_id' => 'gen_test']);
        Http::fake(['*/generations/gen_test' => Http::response(['error' => ['message' => 'Too many requests.']], 429, ['Retry-After' => '55'])]);
        $queueJob = \Mockery::mock(Job::class);
        $queueJob->shouldReceive('attempts')->andReturn(1);
        $queueJob->shouldReceive('release')->once()->with(55);
        $job = new SyncGeneration($generation->id);
        $job->setJob($queueJob);
        $job->handle(app(Pixelforge::class), app(GenerationService::class));
        $this->assertSame('queued', $generation->fresh()->status);
    }
}
