<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Services\GenerationService;
use App\Services\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use RefreshDatabase;

    public function test_documented_maximum_and_markup_are_exact_including_unicode(): void
    {
        $estimate = Money::estimate(['prompt' => str_repeat('界', 400), 'quality' => 'high', 'size' => '1536x1024', 'reference_images' => ['a', 'b']], $this->pricing());
        $this->assertSame('0.467343750', Money::format($estimate['maximum_nanos']));
        $this->assertSame('0.381887500', Money::format(Money::charge(Money::cost($this->object()['usage'], $this->object()['rates']))));
        $this->assertSame(2, Money::charge(1));
    }

    public function test_terminal_settlement_uses_saved_rates_and_only_charges_once(): void
    {
        $user = User::factory()->create(['balance_nanos' => 1_000_000_000, 'reserved_nanos' => 500_000_000]);
        $generation = Generation::factory()->for($user)->create(['reserved_nanos' => 500_000_000]);
        $service = app(GenerationService::class);
        $service->apply($generation, $this->object());
        $service->apply($generation, $this->object());
        $service->apply($generation, $this->object('processing'));
        $this->assertSame('succeeded', $generation->fresh()->status);
        $this->assertSame(305_510_000, $generation->fresh()->provider_nanos);
        $this->assertSame(381_887_500, $generation->fresh()->actual_nanos);
        $this->assertSame(618_112_500, $user->fresh()->balance_nanos);
        $this->assertSame(0, $user->fresh()->reserved_nanos);
        $this->assertSame(1, LedgerEntry::where('generation_id', $generation->id)->count());
        $this->assertSame(-381_887_500, LedgerEntry::first()->amount_nanos);
    }

    public function test_failure_releases_the_entire_reservation_and_stays_final(): void
    {
        $user = User::factory()->create(['balance_nanos' => 1_000_000_000, 'reserved_nanos' => 90_000_000]);
        $generation = Generation::factory()->for($user)->create();
        app(GenerationService::class)->apply($generation, $this->object('failed'));
        app(GenerationService::class)->apply($generation, $this->object('processing'));
        $this->assertSame('failed', $generation->fresh()->status);
        $this->assertSame(0, $generation->fresh()->actual_nanos);
        $this->assertSame(1_000_000_000, $user->fresh()->balance_nanos);
        $this->assertSame(0, $user->fresh()->reserved_nanos);
    }

    public function test_a_quote_can_only_create_one_generation_and_reservation(): void
    {
        Queue::fake();
        $user = User::factory()->create(['balance_nanos' => 1_000_000_000]);
        $service = app(GenerationService::class);
        $quote = $service->quote($user, ['prompt' => 'Hello', 'size' => '1024x1024', 'quality' => 'low', 'reference_images' => []], $this->pricing());
        $one = $service->create($user, $quote['id']);
        $two = $service->create($user, $quote['id']);
        $this->assertTrue($one->is($two));
        $this->assertSame(1, Generation::count());
        $this->assertSame($one->reserved_nanos, $user->fresh()->reserved_nanos);
    }

    public function test_available_balance_accounts_for_concurrent_reservations(): void
    {
        Queue::fake();
        $user = User::factory()->create(['balance_nanos' => 30_000_000]);
        $service = app(GenerationService::class);
        $parameters = ['prompt' => 'Hello', 'size' => '1024x1024', 'quality' => 'low', 'reference_images' => []];
        $first = $service->quote($user, $parameters, $this->pricing());
        $second = $service->quote($user, $parameters, $this->pricing());
        $service->create($user, $first['id']);
        $this->expectException(ValidationException::class);
        $service->create($user, $second['id']);
    }

    public function test_expired_quotes_cannot_reserve_or_submit(): void
    {
        Queue::fake();
        $user = User::factory()->create(['balance_nanos' => 1_000_000_000]);
        $service = app(GenerationService::class);
        $quote = $service->quote($user, ['prompt' => 'Hello', 'size' => '1024x1024', 'quality' => 'low', 'reference_images' => []], $this->pricing());
        $this->travel(6)->minutes();
        $this->expectException(ValidationException::class);
        $service->create($user, $quote['id']);
    }

    public function test_credit_purchase_is_idempotent_and_scoped_to_each_user(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $service = app(GenerationService::class);
        $service->purchase($user, 'purchase-1', 5_000_000_000);
        $service->purchase($user, 'purchase-1', 5_000_000_000);
        $service->purchase($other, 'purchase-1', 10_000_000_000);
        $this->assertSame(5_000_000_000, $user->fresh()->balance_nanos);
        $this->assertSame(10_000_000_000, $other->fresh()->balance_nanos);
        $this->assertSame(2, LedgerEntry::count());
    }

    public function test_completion_uses_generation_rates_when_current_pricing_has_changed(): void
    {
        $user = User::factory()->create(['balance_nanos' => 1_000_000_000, 'reserved_nanos' => 90_000_000]);
        $generation = Generation::factory()->for($user)->create(['quoted_pricing' => $this->pricing()]);
        $object = $this->object();
        $object['rates']['reasoning_tokens'] = '25.00';
        app(GenerationService::class)->apply($generation, $object);
        $this->assertSame(345_310_000, $generation->fresh()->provider_nanos);
        $this->assertSame(431_637_500, $generation->fresh()->actual_nanos);
        $this->assertSame('25.00', $generation->fresh()->rates['reasoning_tokens']);
    }
}
