<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RateLimitFeedbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_validation_also_survives_redirect(): void
    {
        $this->post('/login', ['email' => 'preview@example.com', 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->withCookie(config('session.cookie'), session()->getId())->get('/login')->assertInertia(fn (Assert $page) => $page->where('errors.email', 'The email or password is incorrect.'));
    }

    public function test_login_throttle_returns_an_inline_form_error_for_inertia(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', ['email' => 'preview@example.com', 'password' => 'wrong'])->assertSessionHasErrors('email');
        }
        $response = $this->withHeader('X-Inertia', 'true')->post('/login', ['email' => 'preview@example.com', 'password' => 'wrong']);
        $response->assertStatus(303)->assertRedirect('/login')->assertSessionHasErrors('email');
        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
        $this->withCookie(config('session.cookie'), session()->getId())->withoutHeader('X-Inertia')->get('/login')->assertInertia(fn (Assert $page) => $page->component('Auth')
            ->where('errors.email', fn ($message) => str_contains($message, 'before trying to sign in again') && ! str_contains($message, 'Too Many')));
        $this->assertNull(session()->getOldInput('password'));
    }

    public function test_quote_throttle_returns_friendly_json_for_the_existing_inline_estimate_error(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        for ($attempt = 0; $attempt < 60; $attempt++) {
            $this->postJson('/quotes', [])->assertUnprocessable();
        }
        $response = $this->postJson('/quotes', [])->assertStatus(429)->assertJsonStructure(['message', 'retry_after']);
        $this->assertStringContainsString('try your estimate again', $response->json('message'));
        $this->assertSame((int) $response->headers->get('Retry-After'), $response->json('retry_after'));
        $this->assertSame(0, Generation::count());
    }

    public function test_generation_throttle_keeps_the_user_on_the_studio_without_creating_a_hold(): void
    {
        $user = User::factory()->create(['balance_nanos' => 1_000_000_000]);
        $this->actingAs($user);
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $this->postJson('/generations', [])->assertUnprocessable();
        }
        $this->withHeader('X-Inertia', 'true')->post('/generations', [])
            ->assertStatus(303)->assertRedirect('/studio')->assertSessionHasErrors('quote_id');
        $this->withCookie(config('session.cookie'), session()->getId())->withoutHeader('X-Inertia')->get('/studio')->assertInertia(fn (Assert $page) => $page->component('Studio')
            ->where('errors.quote_id', fn ($message) => str_contains($message, 'no credits were reserved')));
        $this->assertSame(0, $user->fresh()->reserved_nanos);
        $this->assertSame(0, Generation::count());
        $this->assertSame(0, LedgerEntry::count());
    }

    public function test_credit_throttle_returns_an_inline_error_without_recording_a_purchase(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson('/credits', [])->assertUnprocessable();
        }
        $this->withHeader('X-Inertia', 'true')->post('/credits', [])
            ->assertStatus(303)->assertRedirect('/credits')->assertSessionHasErrors('amount');
        $this->assertStringContainsString('top-up wasn’t processed', session('errors')->first('amount'));
        $this->assertSame(0, LedgerEntry::count());
    }

    public function test_registration_throttle_preserves_the_registration_page(): void
    {
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->postJson('/register', [])->assertUnprocessable();
        }
        $this->withHeader('X-Inertia', 'true')->post('/register', [])
            ->assertStatus(303)->assertRedirect('/register')->assertSessionHasErrors('email');
        $this->assertStringContainsString('Account creation is temporarily paused', session('errors')->first('email'));
    }

    public function test_plain_json_requests_keep_the_real_429_status_and_retry_headers(): void
    {
        Route::post('/test-rate-limit', fn (Request $request) => throw new ThrottleRequestsException(headers: [
            'Retry-After' => 17, 'X-RateLimit-Limit' => 60, 'X-RateLimit-Remaining' => 0,
        ]));
        $this->postJson('/test-rate-limit')->assertStatus(429)
            ->assertHeader('Retry-After', '17')->assertHeader('X-RateLimit-Remaining', '0')
            ->assertJsonPath('retry_after', 17)->assertJsonPath('message', 'Please give us 17 seconds before trying again.');
    }
}
