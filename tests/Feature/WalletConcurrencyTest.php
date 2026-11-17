<?php

namespace Tests\Feature;

use App\Exceptions\ProviderLimited;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Services\GenerationService;
use App\Services\Money;
use App\Services\ProviderLimits;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WalletConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private function requireConcurrentDatabase(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'pgsql'], true) || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('This race test needs MySQL/PostgreSQL and PCNTL; SQLite does not provide row locks.');
        }
    }

    /** Start independent processes together, each with its own database connection. */
    private function race(callable $operation, int $workers = 6): array
    {
        $directory = sys_get_temp_dir().'/prism-wallet-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);
        DB::disconnect();
        $children = [];
        try {
            for ($index = 0; $index < $workers; $index++) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new \RuntimeException('Could not fork race worker.');
                }
                if ($pid === 0) {
                    touch($directory.'/ready-'.$index);
                    $deadline = microtime(true) + 10;
                    while (! file_exists($directory.'/start') && microtime(true) < $deadline) {
                        usleep(1000);
                        clearstatcache();
                    }
                    try {
                        $result = $operation($index);
                    } catch (\Throwable $exception) {
                        $result = ['result' => 'error', 'message' => $exception->getMessage()];
                    }
                    file_put_contents($directory.'/result-'.$index, json_encode($result, JSON_THROW_ON_ERROR));
                    exit(0);
                }
                $children[] = $pid;
            }
            $deadline = microtime(true) + 10;
            while (count(glob($directory.'/ready-*')) < $workers && microtime(true) < $deadline) {
                usleep(1000);
                clearstatcache();
            }
            touch($directory.'/start');
            $deadline = microtime(true) + 15;
            $remaining = $children;
            while ($remaining && microtime(true) < $deadline) {
                foreach ($remaining as $key => $pid) {
                    if (pcntl_waitpid($pid, $status, WNOHANG) > 0) {
                        unset($remaining[$key]);
                    }
                }
                usleep(5000);
            }
            if ($remaining) {
                foreach ($remaining as $pid) {
                    posix_kill($pid, SIGTERM);
                    pcntl_waitpid($pid, $status);
                }
                throw new \RuntimeException('Concurrent database test timed out.');
            }
            $results = [];
            for ($index = 0; $index < $workers; $index++) {
                $path = $directory.'/result-'.$index;
                if (! is_file($path)) {
                    throw new \RuntimeException('Race worker did not return a result.');
                }
                $results[] = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            }

            return $results;
        } finally {
            foreach (glob($directory.'/*') as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }

    private function parameters(): array
    {
        return ['prompt' => 'Hello', 'size' => '1024x1024', 'quality' => 'low', 'reference_images' => []];
    }

    public function test_parallel_generations_cannot_reserve_the_same_money(): void
    {
        $this->requireConcurrentDatabase();
        Queue::fake();
        $user = User::factory()->create();
        $service = app(GenerationService::class);
        $service->purchase($user, 'funding', 30_000_000);
        $quotes = [];
        for ($index = 0; $index < 6; $index++) {
            $quotes[] = $service->quote($user, $this->parameters(), $this->pricing())['id'];
        }
        $results = $this->race(function (int $index) use ($user, $quotes): array {
            try {
                $generation = app(GenerationService::class)->create(User::findOrFail($user->id), $quotes[$index]);

                return ['result' => 'created', 'id' => $generation->id];
            } catch (ValidationException) {
                return ['result' => 'insufficient'];
            }
        });
        $this->assertCount(1, array_filter($results, fn ($result) => $result['result'] === 'created'), json_encode($results));
        $this->assertCount(5, array_filter($results, fn ($result) => $result['result'] === 'insufficient'));
        $this->assertSame(1, Generation::count());
        $this->assertSame(30_000_000, $user->fresh()->balance_nanos);
        $this->assertSame(Generation::first()->reserved_nanos, $user->fresh()->reserved_nanos);
        $this->assertSame(1, LedgerEntry::count());
    }

    public function test_parallel_replays_create_only_one_hold(): void
    {
        $this->requireConcurrentDatabase();
        Queue::fake();
        $user = User::factory()->create();
        $service = app(GenerationService::class);
        $service->purchase($user, 'funding', 1_000_000_000);
        $quote = $service->quote($user, $this->parameters(), $this->pricing());
        $results = $this->race(function (int $index) use ($user, $quote): array {
            return ['result' => 'created', 'id' => app(GenerationService::class)->create(User::findOrFail($user->id), $quote['id'])->id];
        });
        $this->assertCount(6, array_filter($results, fn ($result) => $result['result'] === 'created'), json_encode($results));
        $this->assertSame(1, Generation::count());
        $this->assertSame(Generation::first()->reserved_nanos, $user->fresh()->reserved_nanos);
    }

    public function test_parallel_settlement_attempts_can_only_charge_once(): void
    {
        $this->requireConcurrentDatabase();
        Queue::fake();
        $user = User::factory()->create();
        $service = app(GenerationService::class);
        $service->purchase($user, 'funding', 1_000_000_000);
        $quote = $service->quote($user, ['prompt' => 'Hello', 'size' => '1536x1024', 'quality' => 'high', 'reference_images' => ['https://example.com/reference.jpg']], $this->pricing());
        $generation = $service->create($user, $quote['id']);
        $generation->update(['provider_id' => 'gen_test', 'status' => 'processing']);
        $object = $this->object();
        $results = $this->race(function (int $index) use ($generation, $object): array {
            app(GenerationService::class)->apply(Generation::findOrFail($generation->id), $object);

            return ['result' => 'settled'];
        });
        $this->assertCount(6, array_filter($results, fn ($result) => $result['result'] === 'settled'), json_encode($results));
        $this->assertSame(1, LedgerEntry::where('generation_id', $generation->id)->count());
        $this->assertSame(618_112_500, $user->fresh()->balance_nanos);
        $this->assertSame(0, $user->fresh()->reserved_nanos);
        $this->assertSame(618_112_500, LedgerEntry::where('generation_id', $generation->id)->first()->balance_after_nanos);
        $this->assertSame('succeeded', $generation->fresh()->status);
    }

    public function test_parallel_workers_cannot_claim_more_than_three_provider_slots(): void
    {
        $this->requireConcurrentDatabase();
        $generations = Generation::factory()->count(6)->create();
        $results = $this->race(function (int $index) use ($generations): array {
            try {
                app(ProviderLimits::class)->acquire(Generation::findOrFail($generations[$index]->id));

                return ['result' => 'admitted'];
            } catch (ProviderLimited) {
                return ['result' => 'waiting'];
            }
        });
        $this->assertCount(3, array_filter($results, fn ($result) => $result['result'] === 'admitted'), json_encode($results));
        $this->assertCount(3, array_filter($results, fn ($result) => $result['result'] === 'waiting'));
        $this->assertSame(3, Generation::where('provider_slot', true)->count());
        $this->assertSame(3, (int) Generation::sum('submission_attempts'));
    }

    public function test_parallel_workers_cannot_exceed_the_shared_request_allowance(): void
    {
        $this->requireConcurrentDatabase();
        DB::table('provider_accounts')->insert(['scope' => 'primary', 'request_timestamps' => json_encode(array_fill(0, 59, now()->timestamp))]);
        $results = $this->race(function (int $index): array {
            try {
                app(ProviderLimits::class)->acquire();

                return ['result' => 'admitted'];
            } catch (ProviderLimited) {
                return ['result' => 'waiting'];
            }
        });
        $this->assertCount(1, array_filter($results, fn ($result) => $result['result'] === 'admitted'), json_encode($results));
        $this->assertCount(5, array_filter($results, fn ($result) => $result['result'] === 'waiting'), json_encode($results));
        $this->assertCount(60, json_decode(DB::table('provider_accounts')->value('request_timestamps'), true));
    }

    public function test_parallel_workers_cannot_exceed_the_utc_daily_allowance(): void
    {
        $this->requireConcurrentDatabase();
        $user = User::factory()->create();
        Generation::factory()->count(499)->for($user)->create(['status' => 'failed', 'provider_created_at' => now('UTC')]);
        $generations = Generation::factory()->count(6)->for($user)->create();
        $results = $this->race(function (int $index) use ($generations): array {
            try {
                app(ProviderLimits::class)->acquire(Generation::findOrFail($generations[$index]->id));

                return ['result' => 'admitted'];
            } catch (ProviderLimited) {
                return ['result' => 'waiting'];
            }
        });
        $this->assertCount(1, array_filter($results, fn ($result) => $result['result'] === 'admitted'), json_encode($results));
        $this->assertCount(5, array_filter($results, fn ($result) => $result['result'] === 'waiting'), json_encode($results));
        $this->assertSame(1, Generation::where('provider_slot', true)->count());
    }

    public function test_parallel_price_increases_cannot_reserve_the_same_available_money(): void
    {
        $this->requireConcurrentDatabase();
        $user = User::factory()->create(['balance_nanos' => 1_100_000_000, 'reserved_nanos' => 540_000_000]);
        $generations = Generation::factory()->count(6)->for($user)->create(['prompt' => 'Hello']);
        $pricing = $this->pricing();
        $pricing['rates']['output_tokens'] = '400.00';
        $required = Money::estimate($generations[0]->only(['prompt', 'size', 'quality', 'reference_images']), $pricing)['maximum_nanos'];
        $results = $this->race(function (int $index) use ($generations, $pricing): array {
            return ['result' => app(GenerationService::class)->refreshReservation(Generation::findOrFail($generations[$index]->id), $pricing) ? 'increased' : 'insufficient'];
        });
        $this->assertCount(1, array_filter($results, fn ($result) => $result['result'] === 'increased'), json_encode($results));
        $this->assertCount(5, array_filter($results, fn ($result) => $result['result'] === 'insufficient'), json_encode($results));
        $this->assertSame(450_000_000 + $required, $user->fresh()->reserved_nanos);
        $this->assertLessThanOrEqual($user->fresh()->balance_nanos, $user->fresh()->reserved_nanos);
        $this->assertSame($user->fresh()->reserved_nanos, (int) Generation::sum('reserved_nanos'));
    }
}
