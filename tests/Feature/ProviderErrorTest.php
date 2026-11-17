<?php

namespace Tests\Feature;

use App\Jobs\SubmitGeneration;
use App\Jobs\SyncGeneration;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Services\GenerationService;
use App\Services\Pixelforge;
use App\Services\ProviderError;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProviderErrorTest extends TestCase
{
    use RefreshDatabase;

    private function validationError(): array
    {
        return ['type' => 'invalid_request_error', 'code' => 'validation_failed',
            'message' => 'The selected size is invalid.', 'param' => 'size',
            'errors' => ['size' => ['The size must be one of: 1024x1024, 1536x1024, 1024x1536.'],
                'quality' => ['The selected quality is invalid.']]];
    }

    private function generation(array $attributes = []): Generation
    {
        $user = User::factory()->create(['balance_nanos' => 1_000_000_000, 'reserved_nanos' => 90_000_000]);

        return Generation::factory()->for($user)->create($attributes);
    }

    public function test_validation_rejection_retains_all_structured_details_and_releases_the_hold(): void
    {
        $generation = $this->generation();
        Http::fake(['*/pricing' => Http::response($this->pricing()),
            '*/generations' => Http::response(['error' => $this->validationError()], 422)]);
        (new SubmitGeneration($generation->id))->handle(app(Pixelforge::class), app(GenerationService::class));
        $generation->refresh();
        $this->assertSame('failed', $generation->status);
        $this->assertSame(0, $generation->user->reserved_nanos);
        $this->assertSame(0, $generation->actual_nanos);
        $this->assertEquals([...$this->validationError(), 'http_status' => 422], $generation->provider_error);
        $this->assertSame($this->validationError()['errors'], $generation->display()['validation_errors']);
        $this->assertSame('The selected size is invalid.', $generation->display()['error']);
    }

    public function test_code_takes_precedence_over_status_with_http_fallback_for_unknown_codes(): void
    {
        $this->assertFalse(ProviderError::fromPayload($this->validationError(), 500)->retryable());
        $this->assertTrue(ProviderError::fromPayload(['code' => 'internal_error'], 400)->retryable());
        $this->assertFalse(ProviderError::fromPayload(['code' => 'internal_error'], 503)->definitiveRejection());
        foreach (['daily_limit_exceeded', 'concurrency_limit_exceeded', 'rate_limit_exceeded'] as $code) {
            $this->assertTrue(ProviderError::fromPayload(['code' => $code], 429)->retryable());
        }
        $this->assertFalse(ProviderError::fromPayload(['code' => 'future_code'], 403)->retryable());
        $this->assertTrue(ProviderError::fromPayload(['code' => 'future_code'], 502)->retryable());
    }

    public function test_validation_after_a_lost_response_never_releases_the_hold(): void
    {
        $generation = $this->generation(['submission_uncertain' => true, 'provider_slot' => true]);
        Http::fake(['*/pricing' => Http::response($this->pricing()),
            '*/generations' => Http::response(['error' => $this->validationError()], 422)]);
        $queue = \Mockery::mock(Job::class);
        $queue->shouldReceive('fail')->once();
        $job = new SubmitGeneration($generation->id);
        $job->setJob($queue);
        $job->handle(app(Pixelforge::class), app(GenerationService::class));
        $this->assertSame('queued', $generation->fresh()->status);
        $this->assertTrue($generation->fresh()->submission_uncertain);
        $this->assertSame(90_000_000, $generation->user->fresh()->reserved_nanos);
        $this->assertSame('validation_failed', $generation->fresh()->provider_error['code']);
        $this->assertSame(0, LedgerEntry::count());
    }

    public function test_transient_submission_errors_keep_details_and_honor_retry_after(): void
    {
        $generation = $this->generation();
        Http::fake(['*/pricing' => Http::response($this->pricing()),
            '*/generations' => Http::response(['error' => ['type' => 'server_error', 'code' => 'internal_error', 'message' => 'Try later.']], 503, ['Retry-After' => '45'])]);
        $queue = \Mockery::mock(Job::class);
        $queue->shouldReceive('release')->once()->with(45);
        $job = new SubmitGeneration($generation->id);
        $job->setJob($queue);
        $job->handle(app(Pixelforge::class), app(GenerationService::class));
        $this->assertSame('internal_error', $generation->fresh()->provider_error['code']);
        $this->assertTrue($generation->fresh()->submission_uncertain);
        $this->assertSame('queued', $generation->fresh()->status);
        $this->assertSame(90_000_000, $generation->user->fresh()->reserved_nanos);
        $this->assertSame(0, LedgerEntry::count());
    }

    public function test_polling_preserves_structured_errors_and_a_successful_recovery_clears_them(): void
    {
        $generation = $this->generation(['provider_id' => 'gen_test']);
        $error = ['type' => 'invalid_request_error', 'code' => 'unknown_access_error', 'message' => 'Access denied.'];
        Http::fake(['*/generations/*' => Http::response(['error' => $error], 403)]);
        $queue = \Mockery::mock(Job::class);
        $queue->shouldReceive('fail')->once();
        $job = new SyncGeneration($generation->id);
        $job->setJob($queue);
        $job->handle(app(Pixelforge::class), app(GenerationService::class));
        $this->assertSame('unknown_access_error', $generation->fresh()->provider_error['code']);
        $this->assertNotNull($generation->fresh()->sync_paused_at);
        $this->assertSame(90_000_000, $generation->user->fresh()->reserved_nanos);
        app(GenerationService::class)->apply($generation, $this->object());
        $this->assertNull($generation->fresh()->provider_error);
        $this->assertSame([], $generation->fresh()->display()['validation_errors']);
    }

    public function test_terminal_generation_errors_are_retained_without_charging(): void
    {
        $generation = $this->generation();
        app(GenerationService::class)->apply($generation, $this->object('failed', ['error' => ['code' => 'internal_error', 'message' => 'Generation failed.']]));
        $this->assertSame('internal_error', $generation->fresh()->provider_error['code']);
        $this->assertSame(0, $generation->fresh()->actual_nanos);
        $this->assertSame(0, $generation->user->fresh()->reserved_nanos);
    }

    public function test_malformed_or_missing_error_fields_have_safe_defaults(): void
    {
        foreach ([null, 'invalid json response', ['code' => [], 'message' => [], 'errors' => 'invalid']] as $payload) {
            $error = ProviderError::fromPayload($payload, 502);
            $this->assertNull($error->details['code']);
            $this->assertNull($error->details['message']);
            $this->assertSame([], $error->details['errors']);
            $this->assertTrue($error->retryable());
        }
        $error = ProviderError::fromPayload(['errors' => ['size' => [null, [], 'Invalid size.']]], 422);
        $this->assertSame(['size' => ['Invalid size.']], $error->details['errors']);
    }
}
