<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Services\GenerationService;
use App\Services\Money;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WalletTest extends TestCase
{
    use RefreshDatabase;

    private function funded(): User
    {
        $user = User::factory()->create();
        app(GenerationService::class)->purchase($user, 'funding', 5_000_000_000);

        return $user->fresh();
    }

    private function pending(User $user): Generation
    {
        Queue::fake();
        $service = app(GenerationService::class);
        $quote = $service->quote($user, ['prompt' => 'Hello', 'size' => '1024x1024', 'quality' => 'low', 'reference_images' => []], $this->pricing());

        return $service->create($user, $quote['id']);
    }

    public function test_pending_reservations_do_not_change_balance_or_create_a_charge(): void
    {
        $user = $this->funded();
        $generation = $this->pending($user);
        $wallet = app(WalletService::class)->details($user);
        $this->assertSame('5.000000000', $wallet['summary']['balance']);
        $this->assertSame(Money::format($generation->reserved_nanos), $wallet['summary']['reserved']);
        $this->assertSame(Money::format(5_000_000_000 - $generation->reserved_nanos), $wallet['summary']['available']);
        $this->assertTrue($wallet['summary']['reconciled']);
        $this->assertSame(1, $wallet['entries']->total());
        $this->assertSame(1, $wallet['reservations']->total());
        $this->assertSame(0, LedgerEntry::where('kind', 'generation')->count());
        app(GenerationService::class)->apply($generation, $this->object('processing'));
        $this->assertSame(5_000_000_000, $user->fresh()->balance_nanos);
    }

    public function test_history_records_exact_additions_final_spend_and_running_balances(): void
    {
        $user = $this->funded();
        $generation = $this->pending($user);
        $object = $this->object('succeeded', ['usage' => ['input_text_tokens' => 2, 'input_image_tokens' => 0, 'output_tokens' => 280, 'reasoning_tokens' => 100]]);
        app(GenerationService::class)->apply($generation, $object);
        app(GenerationService::class)->apply($generation, $object);
        $wallet = app(WalletService::class)->details($user);
        $this->assertSame('5.000000000', $wallet['summary']['added']);
        $this->assertSame('0.015575000', $wallet['summary']['spent']);
        $this->assertSame('4.984425000', $wallet['summary']['balance']);
        $this->assertSame('4.984425000', $wallet['summary']['available']);
        $this->assertSame('0.000000000', $wallet['summary']['reserved']);
        $this->assertTrue($wallet['summary']['reconciled']);
        $this->assertSame(2, $wallet['entries']->total());
        $this->assertSame('4.984425000', $wallet['entries']->items()[0]['balance_after']);
        $this->assertSame('5.000000000', $wallet['entries']->items()[1]['balance_after']);
    }

    public function test_failure_releases_a_hold_and_records_zero_spend(): void
    {
        $user = $this->funded();
        $generation = $this->pending($user);
        app(GenerationService::class)->reject($generation, 'Rejected by the provider.');
        app(GenerationService::class)->reject($generation, 'Duplicate rejection.');
        $wallet = app(WalletService::class)->details($user);
        $this->assertSame('5.000000000', $wallet['summary']['balance']);
        $this->assertSame('0.000000000', $wallet['summary']['spent']);
        $this->assertSame('0.000000000', $wallet['summary']['reserved']);
        $this->assertSame(1, LedgerEntry::where('generation_id', $generation->id)->count());
        $this->assertSame(5_000_000_000, LedgerEntry::where('generation_id', $generation->id)->first()->balance_after_nanos);
    }

    public function test_ledger_rows_cannot_be_edited_or_deleted_through_the_model(): void
    {
        $this->funded();
        foreach (['update', 'delete'] as $operation) {
            $entry = LedgerEntry::first();
            try {
                $operation === 'update' ? $entry->update(['amount_nanos' => 1]) : $entry->delete();
                $this->fail('History mutation was allowed.');
            } catch (\LogicException $exception) {
                $this->assertStringContainsString('cannot be', $exception->getMessage());
            }
        }
        $this->assertSame(1, LedgerEntry::count());
        $this->assertSame(5_000_000_000, LedgerEntry::first()->amount_nanos);
    }

    public function test_failed_ledger_write_rolls_back_the_balance_and_generation_together(): void
    {
        $user = $this->funded();
        $generation = $this->pending($user);
        $reserved = $generation->reserved_nanos;
        Event::listen('eloquent.creating: '.LedgerEntry::class, function (LedgerEntry $entry): void {
            if ($entry->kind === 'generation') {
                throw new \RuntimeException('Simulated ledger storage failure');
            }
        });
        try {
            app(GenerationService::class)->apply($generation, $this->object());
            $this->fail('The simulated write failure did not occur.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated ledger storage failure', $exception->getMessage());
        }
        $this->assertSame(5_000_000_000, $user->fresh()->balance_nanos);
        $this->assertSame($reserved, $user->fresh()->reserved_nanos);
        $this->assertSame('queued', $generation->fresh()->status);
        $this->assertNull($generation->fresh()->actual_nanos);
        $this->assertSame(1, LedgerEntry::count());
    }

    public function test_customer_wallet_is_private_and_operators_can_inspect_each_user(): void
    {
        $owner = $this->funded();
        $other = User::factory()->create();
        app(GenerationService::class)->purchase($other, 'other-funding', 10_000_000_000);
        $this->actingAs($owner)->get('/credits?user_id='.$other->id)->assertInertia(fn (Assert $page) => $page->component('Credits')->where('wallet.owner.id', $owner->id)->where('wallet.summary.balance', '5.000000000')->has('wallet.entries.data', 1));
        $this->get('/admin/wallets')->assertForbidden();
        $this->get('/admin/wallets/'.$other->id)->assertForbidden();
        $operator = User::factory()->create(['is_admin' => true]);
        $this->actingAs($operator)->get('/admin/wallets')->assertInertia(fn (Assert $page) => $page->component('Wallets')->has('users.data', 3));
        $this->get('/admin/wallets/'.$other->id)->assertInertia(fn (Assert $page) => $page->component('UserWallet')->where('wallet.summary.balance', '10.000000000')->where('wallet.owner.id', $other->id));
    }

    public function test_reusing_a_purchase_key_for_another_amount_is_rejected(): void
    {
        $user = $this->funded();
        try {
            app(GenerationService::class)->purchase($user, 'funding', 10_000_000_000);
            $this->fail('Conflicting purchase was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('purchase_id', $exception->errors());
        }
        $this->assertSame(5_000_000_000,$user->fresh()->balance_nanos);
        $this->assertSame(1,LedgerEntry::count());
    }
}
