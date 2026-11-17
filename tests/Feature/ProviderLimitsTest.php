<?php

namespace Tests\Feature;

use App\Exceptions\ProviderLimited;
use App\Jobs\SubmitGeneration;
use App\Jobs\SyncGeneration;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Services\GenerationService;
use App\Services\Pixelforge;
use App\Services\ProviderLimits;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ProviderLimitsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now('UTC')->startOfSecond());
    }

    private function limited(callable $operation, int $seconds): void
    {
        try {
            $operation();
            $this->fail('Expected the provider request to wait.');
        } catch (ProviderLimited $exception) {
            $this->assertSame($seconds, $exception->retryAfter);
        }
    }

    private function queuedJob(SubmitGeneration|SyncGeneration $job, ?int $release = null, bool $fails = false): SubmitGeneration|SyncGeneration
    {
        $queue = \Mockery::mock(Job::class);
        if ($release !== null) {
            $queue->shouldReceive('release')->once()->with($release);
        }
        if ($fails) {
            $queue->shouldReceive('fail')->once()->with(\Mockery::type(\RuntimeException::class));
        }
        $job->setJob($queue);

        return $job;
    }

    public function test_requests_share_a_rolling_minute_across_keys_and_endpoints(): void
    {
        $this->freezeTime();
        Http::fake(['*' => Http::response(['ok' => true])]);
        $provider = app(Pixelforge::class);
        for ($index = 0; $index < 60; $index++) {
            config(['services.pixelforge.key' => 'test-key-'.$index]);
            $index % 2 === 0 ? $provider->retrieve('gen_test') : $provider->usage('2026-11-17', '2026-11-17');
        }
        $this->limited(fn () => $provider->retrieve('gen_test'), 60);
        $this->travel(59)->seconds();
        $this->limited(fn () => $provider->retrieve('gen_test'), 1);
        Http::assertSentCount(60);
        $this->travel(1)->seconds();
        $provider->retrieve('gen_test');
        Http::assertSentCount(61);
    }

    public function test_only_three_submissions_can_occupy_slots_until_a_terminal_result(): void
    {
        Http::fake(['*/generations' => fn ($request) => Http::response($this->object('processing', ['id' => 'gen_'.$request->header('Idempotency-Key')[0]]), 202)]);
        $provider = app(Pixelforge::class);
        $service = app(GenerationService::class);
        $generations = Generation::factory()->count(4)->create();
        foreach ($generations->take(3) as $generation) {
            $service->apply($generation, $provider->submit($generation));
        }
        $this->limited(fn () => $provider->submit($generations[3]), 5);
        Http::assertSentCount(3);
        $this->assertSame(0, $generations[3]->fresh()->submission_attempts);
        $service->apply($generations[0], $this->object('failed', ['id' => $generations[0]->fresh()->provider_id]));
        $service->apply($generations[3], $provider->submit($generations[3]));
        $this->assertSame(3, Generation::where('provider_slot', true)->count());
        Http::assertSentCount(4);
    }

    public function test_daily_quota_counts_acceptance_day_including_failed_and_unconfirmed_creates(): void
    {
        $this->travelTo(now('UTC')->setDate(2026, 11, 17)->setTime(23, 59, 50));
        $user = User::factory()->create();
        Generation::factory()->count(499)->for($user)->create([
            'status' => 'failed', 'reserved_nanos' => 0,
            'provider_created_at' => now('UTC')->startOfDay(), 'completed_at' => now('UTC')->subDay(),
        ]);
        $pending = Generation::factory()->for($user)->create(['provider_slot' => true, 'submission_uncertain' => true]);
        $waiting = Generation::factory()->for($user)->create();
        $this->limited(fn () => app(ProviderLimits::class)->acquire($waiting), 10);
        $this->assertSame(0, $waiting->fresh()->submission_attempts);
        $this->travel(10)->seconds();
        app(ProviderLimits::class)->acquire($waiting);
        $this->assertSame(1, $waiting->fresh()->submission_attempts);
        $this->assertTrue($pending->fresh()->provider_slot);
    }

    public function test_the_five_hundredth_generation_can_start_but_the_next_one_waits(): void
    {
        $this->freezeTime();
        $user = User::factory()->create();
        Generation::factory()->count(499)->for($user)->create(['status' => 'succeeded', 'provider_created_at' => now('UTC')]);
        $last = Generation::factory()->for($user)->create();
        $next = Generation::factory()->for($user)->create();
        app(ProviderLimits::class)->acquire($last);
        $this->limited(fn () => app(ProviderLimits::class)->acquire($next), now('UTC')->endOfDay()->timestamp + 1 - now('UTC')->timestamp);
        app(ProviderLimits::class)->rejected($last, false);
        app(ProviderLimits::class)->acquire($next);
        $this->assertTrue($next->fresh()->provider_slot);
    }

    public function test_retry_after_blocks_other_users_polling_and_reports_too(): void
    {
        $this->freezeTime();
        Http::fakeSequence()->push(['error' => ['code' => 'rate_limit_exceeded']], 429, ['Retry-After' => '45'])->push(['ok' => true]);
        $provider = app(Pixelforge::class);
        try {
            $provider->retrieve('gen_one');
            $this->fail('Expected a rate-limit response.');
        } catch (RequestException) {
        }
        config(['services.pixelforge.key' => 'another-key-in-the-same-account']);
        $this->limited(fn () => $provider->usage('2026-11-17', '2026-11-17'), 45);
        $this->limited(fn () => $provider->submit(Generation::factory()->create()), 45);
        Http::assertSentCount(1);
        $this->travel(45)->seconds();
        $provider->usage('2026-11-17', '2026-11-17');
        Http::assertSentCount(2);
    }

    public function test_server_remaining_and_limit_headers_reduce_the_shared_allowance(): void
    {
        $this->freezeTime();
        Http::fakeSequence()->push(['ok' => true], 200, ['X-RateLimit-Limit' => '60', 'X-RateLimit-Remaining' => '1'])
            ->push(['ok' => true], 200, ['X-RateLimit-Limit' => '60', 'X-RateLimit-Remaining' => '0'])->push(['ok' => true]);
        $provider = app(Pixelforge::class);
        $provider->retrieve('gen_one');
        $provider->retrieve('gen_two');
        $this->limited(fn () => $provider->retrieve('gen_three'), 60);
        $this->travel(60)->seconds();
        $provider->retrieve('gen_three');
        Http::assertSentCount(3);
    }

    public function test_daily_provider_rejection_pauses_creates_until_utc_midnight_but_allows_polling(): void
    {
        $this->travelTo(now('UTC')->setDate(2026, 11, 17)->setTime(23, 59, 50));
        Http::fakeSequence()->push(['error' => ['code' => 'daily_limit_exceeded']], 429)->push(['ok' => true])->push($this->object('queued'), 202);
        $provider = app(Pixelforge::class);
        $generation = Generation::factory()->create();
        try {
            $provider->submit($generation);
            $this->fail('Expected a daily-limit response.');
        } catch (RequestException) {
        }
        $this->assertFalse($generation->fresh()->submission_uncertain);
        $this->assertFalse($generation->fresh()->provider_slot);
        $provider->retrieve('already_accepted');
        $waiting = Generation::factory()->create();
        $this->limited(fn () => $provider->submit($waiting), 10);
        $this->travel(10)->seconds();
        $provider->submit($waiting);
        Http::assertSentCount(3);
    }

    public function test_local_quota_waits_do_not_consume_submission_retry_budget(): void
    {
        $this->freezeTime();
        Generation::factory()->count(3)->create(['provider_slot' => true, 'provider_created_at' => now('UTC')]);
        $waiting = Generation::factory()->create();
        for ($index = 0; $index < 10; $index++) {
            $this->queuedJob(new SubmitGeneration($waiting->id), 5)->handle(app(Pixelforge::class), app(GenerationService::class));
            $this->travel(5)->seconds();
        }
        Http::assertNothingSent();
        $this->assertSame(0, $waiting->fresh()->submission_attempts);
        $this->assertSame('queued', $waiting->fresh()->status);
        $this->assertStringContainsString('another image', $waiting->fresh()->waiting_message);
    }

    public function test_overload_retries_back_off_and_stop_after_six_real_requests_across_fresh_jobs(): void
    {
        $this->freezeTime();
        $user = User::factory()->create(['balance_nanos' => 1_000_000_000, 'reserved_nanos' => 90_000_000]);
        $generation = Generation::factory()->for($user)->create();
        Http::fake(['*/pricing' => Http::response($this->pricing()), '*' => Http::response(['error' => ['code' => 'overloaded', 'message' => 'Overloaded.']], 503, ['Retry-After' => '2'])]);
        foreach ([5, 15, 30, 60, 120, null] as $delay) {
            $this->queuedJob(new SubmitGeneration($generation->id), $delay)->handle(app(Pixelforge::class), app(GenerationService::class));
            if ($delay !== null) {
                $this->travel($delay)->seconds();
            }
        }
        (new SubmitGeneration($generation->id))->handle(app(Pixelforge::class), app(GenerationService::class));
        Http::assertSentCount(12);
        $this->assertSame(6, $generation->fresh()->submission_attempts);
        $this->assertSame('failed', $generation->fresh()->status);
        $this->assertSame(0, $user->fresh()->reserved_nanos);
        $this->assertSame(1_000_000_000, $user->fresh()->balance_nanos);
        $this->assertSame(1, LedgerEntry::where('generation_id', $generation->id)->count());
    }

    public function test_a_rejection_after_a_lost_response_keeps_the_hold_and_late_success_charges_once(): void
    {
        $this->freezeTime();
        Queue::fake();
        $user = User::factory()->create(['balance_nanos' => 1_000_000_000, 'reserved_nanos' => 90_000_000]);
        $generation = Generation::factory()->for($user)->create();
        $sequence = Http::sequence()->pushFailedConnection('Response was lost.')->push(['error' => ['code' => 'invalid_api_key']], 401);
        Http::fake(['*/pricing' => Http::response($this->pricing()), '*/generations' => $sequence]);
        $service = app(GenerationService::class);
        $this->queuedJob(new SubmitGeneration($generation->id), 5)->handle(app(Pixelforge::class), $service);
        $this->assertTrue($generation->fresh()->submission_uncertain);
        $this->assertTrue($generation->fresh()->provider_slot);
        $this->travel(5)->seconds();
        $this->queuedJob(new SubmitGeneration($generation->id), fails: true)->handle(app(Pixelforge::class), $service);
        $this->assertSame('queued', $generation->fresh()->status);
        $this->assertSame(90_000_000, $user->fresh()->reserved_nanos);
        $this->assertSame(0, LedgerEntry::count());
        $this->assertStringContainsString('could not be confirmed', $generation->fresh()->error_message);
        $service->reject($generation, 'A later rejection must not finalize an uncertain request.');
        $this->assertSame('queued', $generation->fresh()->status);
        $service->apply($generation, $this->object());
        $service->apply($generation, $this->object());
        $this->assertSame(618_112_500, $user->fresh()->balance_nanos);
        $this->assertSame(0, $user->fresh()->reserved_nanos);
        $this->assertSame(1, LedgerEntry::count());
        $this->assertFalse($generation->fresh()->provider_slot);
    }

    public function test_a_new_poll_or_scheduler_cannot_bypass_a_persisted_cooldown(): void
    {
        $this->freezeTime();
        Queue::fake();
        $generation = Generation::factory()->create(['provider_id' => 'gen_test', 'synced_at' => now()->subMinute(), 'created_at' => now()->subMinutes(5)]);
        Http::fake(['*' => Http::response(['error' => ['code' => 'rate_limit_exceeded']], 429, ['Retry-After' => '55'])]);
        $this->queuedJob(new SyncGeneration($generation->id), 55)->handle(app(Pixelforge::class), app(GenerationService::class));
        $this->queuedJob(new SyncGeneration($generation->id), 55)->handle(app(Pixelforge::class), app(GenerationService::class));
        $this->artisan('prism:reconcile')->assertSuccessful();
        Queue::assertNotPushed(SyncGeneration::class);
        Http::assertSentCount(1);
    }

    public function test_public_pricing_does_not_send_credentials_or_use_authenticated_quota(): void
    {
        Http::fake(['*/pricing' => Http::response($this->pricing())]);
        app(Pixelforge::class)->pricing();
        Http::assertSent(fn ($request) => ! $request->hasHeader('Authorization'));
        $this->assertSame(0, DB::table('provider_accounts')->count());
    }

    public function test_fractional_seconds_cannot_shorten_retry_after_or_the_rolling_window(): void
    {
        $this->travelTo(now('UTC')->setMicrosecond(900000));
        Http::fakeSequence()->push(['error' => ['code' => 'rate_limit_exceeded']], 429, ['Retry-After' => '45'])->push(['ok' => true]);
        $provider = app(Pixelforge::class);
        try {
            $provider->retrieve('gen_test');
            $this->fail('Expected a rate-limit response.');
        } catch (RequestException) {
        }
        $this->travel(45)->seconds();
        $this->limited(fn () => $provider->retrieve('gen_test'), 1);
        $this->travel(1)->seconds();
        $provider->retrieve('gen_test');
        Http::assertSentCount(2);
        $timestamps = json_decode(DB::table('provider_accounts')->value('request_timestamps'), true);
        $this->assertGreaterThanOrEqual(now()->subSeconds(46)->timestamp + 1, $timestamps[0]);
    }

    public function test_a_daily_response_retry_after_is_still_honored_across_midnight(): void
    {
        $this->travelTo(now('UTC')->setDate(2026, 11, 17)->setTime(23, 59, 50));
        Http::fakeSequence()->push(['error' => ['code' => 'daily_limit_exceeded']], 429, ['Retry-After' => '120'])->push($this->object('queued'), 202);
        $provider = app(Pixelforge::class);
        try {
            $provider->submit(Generation::factory()->create());
            $this->fail('Expected a daily-limit response.');
        } catch (RequestException) {
        }
        $this->travel(10)->seconds();
        $waiting = Generation::factory()->create();
        $this->limited(fn () => $provider->submit($waiting), 110);
        $this->travel(110)->seconds();
        $provider->submit($waiting);
        Http::assertSentCount(2);
    }

    public function test_operator_recovery_resets_only_the_attempt_budget_and_keeps_the_original_hold_and_key(): void
    {
        Queue::fake();
        $user = User::factory()->create(['balance_nanos' => 1_000_000_000, 'reserved_nanos' => 90_000_000]);
        $generation = Generation::factory()->for($user)->create([
            'created_at' => now()->subMinutes(6), 'submission_attempts' => 6, 'submission_uncertain' => true,
            'pricing_failures' => 6,
            'provider_slot' => true, 'error_message' => 'Submission could not be confirmed.',
        ]);
        $this->artisan('prism:reconcile', ['--retry-submissions' => true])->assertSuccessful();
        Queue::assertPushed(SubmitGeneration::class, fn ($job) => $job->generationId === $generation->id);
        $this->assertSame(0, $generation->fresh()->submission_attempts);
        $this->assertTrue($generation->fresh()->submission_uncertain);
        $this->assertSame(0, $generation->fresh()->pricing_failures);
        $this->assertSame(90_000_000, $user->fresh()->reserved_nanos);
        Http::fake(['*/pricing' => Http::response($this->pricing()), '*/generations' => Http::response($this->object(), 202, ['Idempotent-Replayed' => 'true'])]);
        (new SubmitGeneration($generation->id))->handle(app(Pixelforge::class), app(GenerationService::class));
        Http::assertSent(fn ($request) => $request->hasHeader('Idempotency-Key', $generation->id));
        $this->assertSame(1, LedgerEntry::count());
        $this->assertSame(0, $user->fresh()->reserved_nanos);
        $this->assertSame(618_112_500, $user->fresh()->balance_nanos);
    }
}
