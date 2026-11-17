<?php

namespace App\Services;

use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GenerationService
{
    public function quote(User $user, array $parameters, array $pricing): array
    {
        $amounts = Money::estimate($parameters, $pricing);
        $id = (string) Str::uuid();
        Cache::put('quote:'.$id, [
            'user_id' => $user->id, 'parameters' => $parameters, 'pricing' => $pricing, ...$amounts,
        ], now()->addMinutes(5));

        return [
            'id' => $id, 'estimated_cost' => Money::format($amounts['estimated_nanos']),
            'maximum_cost' => Money::format($amounts['maximum_nanos']),
            'expires_at' => now()->addMinutes(5)->toIso8601String(),
        ];
    }

    public function create(User $user, string $quoteId): Generation
    {
        return DB::transaction(function () use ($user, $quoteId) {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $existing = Generation::find($quoteId);
            if ($existing) {
                abort_unless($existing->user_id === $user->id, 404);

                return $existing;
            }
            $quote = Cache::get('quote:'.$quoteId);
            if (! $quote || $quote['user_id'] !== $user->id) {
                throw ValidationException::withMessages(['quote_id' => 'Your estimate expired. Please request a fresh estimate.']);
            }
            if ($lockedUser->balance_nanos - $lockedUser->reserved_nanos < $quote['maximum_nanos']) {
                throw ValidationException::withMessages(['quote_id' => 'Add credits to cover the maximum estimated cost.']);
            }
            $lockedUser->increment('reserved_nanos', $quote['maximum_nanos']);
            $generation = Generation::create([
                'id' => $quoteId, 'user_id' => $user->id, ...$quote['parameters'],
                'quoted_pricing' => $quote['pricing'], 'estimated_nanos' => $quote['estimated_nanos'],
                'reserved_nanos' => $quote['maximum_nanos'], 'status' => 'queued',
            ]);
            SubmitGeneration::dispatch($generation->id)->afterCommit();

            return $generation;
        }, 5);
    }

    public function purchase(User $user, string $purchaseId, int $nanos): void
    {
        if ($nanos <= 0) {
            throw new \InvalidArgumentException('Credit additions must be positive.');
        }
        DB::transaction(function () use ($user, $purchaseId, $nanos) {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $key = 'purchase:'.$user->id.':'.$purchaseId;
            $existing = LedgerEntry::where('idempotency_key', $key)->first();
            if ($existing) {
                if ($existing->amount_nanos !== $nanos) {
                    throw ValidationException::withMessages(['purchase_id' => 'This purchase was already recorded with a different amount.']);
                }

                return;
            }
            LedgerEntry::create([
                'user_id' => $user->id, 'idempotency_key' => $key, 'kind' => 'purchase', 'amount_nanos' => $nanos,
                'balance_after_nanos' => $lockedUser->balance_nanos + $nanos,
            ]);
            $lockedUser->increment('balance_nanos', $nanos);
        }, 5);
    }

    /** Shared settlement path for submission responses, polling, and webhook events. */
    public function apply(Generation $generation, array $object): void
    {
        DB::transaction(function () use ($generation, $object) {
            $user = User::query()->lockForUpdate()->findOrFail($generation->user_id);
            $locked = Generation::query()->lockForUpdate()->findOrFail($generation->id);
            if ($locked->provider_id !== null && $locked->provider_id !== $object['id']) {
                throw new \LogicException('Provider generation identity mismatch.');
            }
            if (! in_array($object['status'], ['queued', 'processing', 'succeeded', 'failed'], true)) {
                throw new \UnexpectedValueException('Unknown provider status.');
            }
            if ($locked->terminal()) {
                return;
            }
            $locked->provider_id = $object['id'];
            $locked->synced_at = now();
            $locked->rates = $object['rates'];
            if ($locked->status === 'processing' && $object['status'] === 'queued') {
                $locked->save();

                return;
            }
            $locked->status = $object['status'];
            $locked->error_message = $object['error']['message'] ?? null;
            if ($locked->terminal()) {
                $providerCost = $locked->status === 'succeeded' ? Money::cost($object['usage'], $object['rates']) : 0;
                $charge = Money::charge($providerCost);
                $locked->usage = $object['usage'] ?? null;
                $locked->provider_nanos = $providerCost;
                $locked->actual_nanos = $charge;
                $locked->completed_at = $object['completed_at'];
                $locked->settled_at = now();
                $locked->image_url = $object['image_url'] ?? null;
                $locked->image_url_expires_at = $object['image_url_expires_at'] ?? null;
                $user->reserved_nanos -= $locked->reserved_nanos;
                $user->balance_nanos -= $charge;
                $user->save();
                LedgerEntry::create([
                    'user_id' => $user->id, 'generation_id' => $locked->id,
                    'idempotency_key' => 'generation:'.$locked->id, 'kind' => 'generation', 'amount_nanos' => -$charge,
                    'balance_after_nanos' => $user->balance_nanos,
                ]);
                $locked->reserved_nanos = 0;
            }
            $locked->save();
        }, 5);
    }

    public function reject(Generation $generation, string $message): void
    {
        DB::transaction(function () use ($generation, $message) {
            $user = User::query()->lockForUpdate()->findOrFail($generation->user_id);
            $locked = Generation::query()->lockForUpdate()->findOrFail($generation->id);
            if ($locked->terminal() || $locked->provider_id !== null) {
                return;
            }
            $user->decrement('reserved_nanos', $locked->reserved_nanos);
            LedgerEntry::create([
                'user_id' => $user->id, 'generation_id' => $locked->id,
                'idempotency_key' => 'generation:'.$locked->id, 'kind' => 'generation',
                'amount_nanos' => 0, 'balance_after_nanos' => $user->balance_nanos,
            ]);
            $locked->update([
                'status' => 'failed', 'error_message' => $message,
                'reserved_nanos' => 0, 'actual_nanos' => 0, 'provider_nanos' => 0,
                'completed_at' => now(), 'settled_at' => now(),
            ]);
        }, 5);
    }
}
