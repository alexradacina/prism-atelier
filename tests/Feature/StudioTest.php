<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\User;
use App\Services\GenerationService;
use App\Services\Pixelforge;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StudioTest extends TestCase
{
    use RefreshDatabase;

    public function test_pages_require_authentication_and_reports_require_operator_access(): void
    {
        $this->get('/studio')->assertRedirect('/login');
        $this->get('/history')->assertRedirect('/login');
        $this->get('/admin/reports')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/admin/reports')->assertForbidden();
    }

    public function test_history_is_private_and_status_filter_is_validated(): void
    {
        $user = User::factory()->create();
        Generation::factory()->for($user)->create(['prompt' => 'My private prompt']);
        Generation::factory()->create(['prompt' => 'Another user private prompt']);
        $this->actingAs($user)->get('/history')->assertOk()->assertInertia(fn (Assert $page) => $page->component('History')->has('generations.data', 1)->where('generations.data.0.prompt', 'My private prompt'));
        $this->getJson('/history?status=invalid')->assertUnprocessable();
    }

    public function test_quote_is_server_calculated_and_cross_user_submission_is_denied(): void
    {
        Queue::fake();
        Http::fake(['*/pricing' => Http::response($this->pricing())]);
        $user = User::factory()->create(['balance_nanos' => 1_000_000_000]);
        $quote = $this->actingAs($user)->postJson('/quotes', ['prompt' => 'Hello', 'quality' => 'low', 'size' => '1024x1024', 'reference_images' => []])->assertOk()->json();
        $this->assertArrayHasKey('maximum_cost', $quote);
        $other = User::factory()->create(['balance_nanos' => 1_000_000_000]);
        $this->actingAs($other)->post('/generations', ['quote_id' => $quote['id']])->assertSessionHasErrors('quote_id');
        $this->assertSame(0, Generation::count());
    }

    public function test_invalid_prompts_references_and_sizes_are_rejected(): void
    {
        $this->actingAs(User::factory()->create())->postJson('/quotes', [
            'prompt' => str_repeat('a', 4001), 'quality' => 'ultra', 'size' => '1x1', 'reference_images' => ['file:///private/test'],
        ])->assertUnprocessable()->assertJsonValidationErrors(['prompt', 'quality', 'size', 'reference_images.0']);
    }

    public function test_credit_purchase_endpoint_is_idempotent_and_validates_amounts(): void
    {
        $user = User::factory()->create();
        $body = ['purchase_id' => (string) Str::uuid(), 'amount' => '10'];
        $this->actingAs($user)->post('/credits', $body)->assertRedirect('/credits');
        $this->post('/credits', $body)->assertRedirect('/credits');
        $this->assertSame(10_000_000_000, $user->fresh()->balance_nanos);
        $this->postJson('/credits', [...$body, 'amount' => '-10'])->assertUnprocessable();
    }

    public function test_report_uses_completion_day_saved_rates_and_authoritative_usage(): void
    {
        $user = User::factory()->create(['balance_nanos' => 1_000_000_000, 'reserved_nanos' => 90_000_000, 'is_admin' => true]);
        $generation = Generation::factory()->for($user)->create(['created_at' => '2026-11-16 12:00:00']);
        app(GenerationService::class)->apply($generation, $this->object());
        Http::fake(['*/usage*' => Http::response([
            'data' => [['date' => '2026-11-17', 'generations' => 1, 'cost_usd' => '0.305510000']],
            'total' => ['cost_usd' => '0.305510000'],
        ])]);
        $report = app(ReportService::class)->build('2026-11-17', '2026-11-17', app(Pixelforge::class));
        $this->assertSame('0.305510000', $report['total']['provider']);
        $this->assertSame('0.000000000', $report['total']['difference']);
        $this->assertSame('0.076377500', $report['total']['profit']);
        $this->assertSame('2026-11-17', $report['users'][0]['days'][0]['date']);
        $this->actingAs($user)->get('/admin/reports?start_date=2026-11-17&end_date=2026-11-17')
            ->assertInertia(fn (Assert $page) => $page->component('Report')->where('report.total.provider', '0.305510000'));
    }

    public function test_report_exposes_unattributed_provider_spend_instead_of_faking_a_match(): void
    {
        Http::fake(['*/usage*' => Http::response([
            'data' => [['date' => '2026-11-17', 'generations' => 1, 'cost_usd' => '0.123456789']],
            'total' => ['cost_usd' => '0.123456789'],
        ])]);
        $report = app(ReportService::class)->build('2026-11-17', '2026-11-17', app(Pixelforge::class));
        $this->assertSame('0.123456789', $report['total']['provider']);
        $this->assertSame('0.123456789', $report['total']['difference']);
        $this->assertSame('-0.123456789', $report['total']['profit']);
    }
}
