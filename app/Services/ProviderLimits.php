<?php

namespace App\Services;

use App\Exceptions\ProviderLimited;
use App\Models\Generation;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;

class ProviderLimits
{
    public const MAX_SUBMISSION_ATTEMPTS = 6;

    /** Atomically reserve an HTTP request and, for creates, provider capacity. */
    public function acquire(?Generation $submission = null): bool
    {
        $this->ensureAccount();

        return DB::transaction(function () use ($submission) {
            $account = $this->lockAccount();
            $now = CarbonImmutable::now('UTC');
            $this->waitUntil($account->blocked_until, 'The image service is busy. Your request will retry automatically.');
            $requests = array_values(array_filter(json_decode($account->request_timestamps, true), fn ($timestamp) => $timestamp > $now->timestamp - 60));
            if (count($requests) >= $account->request_limit) {
                throw new ProviderLimited(max(1, min($requests) + 60 - $now->timestamp), 'Waiting for the image service’s request allowance to reset.');
            }
            $remaining = $account->remaining_resets_at && CarbonImmutable::parse($account->remaining_resets_at, 'UTC')->isFuture()
                ? $account->remaining : null;
            if ($remaining !== null && $remaining <= 0) {
                $this->waitUntil($account->remaining_resets_at, 'Waiting for the image service’s request allowance to reset.');
            }
            $wasUncertain = false;
            if ($submission !== null) {
                $generation = Generation::query()->lockForUpdate()->findOrFail($submission->id);
                if ($generation->provider_account !== config('services.pixelforge.account_scope')) {
                    throw new \LogicException('This generation belongs to a different provider account.');
                }
                $this->waitUntil($generation->submission_retry_at?->toDateTimeString(), 'The image service is busy. Your request will retry automatically.');
                if ($generation->submission_attempts >= self::MAX_SUBMISSION_ATTEMPTS) {
                    throw new \LogicException('The submission retry budget is exhausted.');
                }
                if (! $generation->provider_slot) {
                    $active = Generation::where('provider_account', $generation->provider_account)->where('provider_slot', true)->count();
                    if ($active >= 3) {
                        throw new ProviderLimited(5, 'Waiting for another image to finish. Your credits are reserved.');
                    }
                }
                if ($generation->provider_created_at === null) {
                    if (! $generation->submission_uncertain) {
                        $this->waitUntil($account->daily_blocked_until, 'The image service’s daily allowance is full. Your request is queued for the next UTC day.');
                    }
                    $today = Generation::where('provider_account', $generation->provider_account)
                        ->where('id', '!=', $generation->id)
                        ->where(fn ($query) => $query->whereBetween('provider_created_at', [$now->startOfDay(), $now->endOfDay()])
                            ->orWhere(fn ($pending) => $pending->whereNull('provider_created_at')->where('provider_slot', true)))
                        ->count();
                    if ($today >= 500) {
                        throw new ProviderLimited($now->endOfDay()->timestamp + 1 - $now->timestamp, 'The image service’s daily allowance is full. Your request is queued for the next UTC day.');
                    }
                }
                $wasUncertain = $generation->submission_uncertain;
                $generation->update([
                    'provider_slot' => true, 'submission_uncertain' => true,
                    'submission_attempts' => $generation->submission_attempts + 1,
                    'submission_retry_at' => null, 'waiting_message' => null,
                ]);
            }
            $requests[] = $now->ceilSecond()->timestamp;
            DB::table('provider_accounts')->where('scope', $account->scope)->update([
                'request_timestamps' => json_encode($requests, JSON_THROW_ON_ERROR),
                'remaining' => $remaining === null ? null : $remaining - 1,
            ]);

            return $wasUncertain;
        }, 5);
    }

    /** Server feedback applies to every worker and user, including polls and usage reports. */
    public function observe(Response $response): void
    {
        $this->ensureAccount();

        DB::transaction(function () use ($response) {
            $account = $this->lockAccount();
            $now = CarbonImmutable::now('UTC');
            $updates = [];
            $limit = $response->header('X-RateLimit-Limit');
            if (ctype_digit($limit) && (int) $limit > 0) {
                $updates['request_limit'] = min(60, (int) $limit);
            }
            $remaining = $response->header('X-RateLimit-Remaining');
            if (ctype_digit($remaining)) {
                $current = $account->remaining_resets_at && CarbonImmutable::parse($account->remaining_resets_at, 'UTC')->isFuture()
                    ? $account->remaining : null;
                $updates['remaining'] = $current === null ? (int) $remaining : min($current, (int) $remaining);
                $updates['remaining_resets_at'] = $now->addSeconds(60)->ceilSecond();
            }
            if ($response->status() === 429 && $response->json('error.code') === 'daily_limit_exceeded') {
                $updates['daily_blocked_until'] = $now->addDay()->startOfDay();
                if ((int) $response->header('Retry-After', 0) > 0) {
                    $until = $now->addSeconds((int) $response->header('Retry-After'))->ceilSecond();
                    $updates['blocked_until'] = $account->blocked_until ? max(CarbonImmutable::parse($account->blocked_until, 'UTC'), $until) : $until;
                }
            } elseif (in_array($response->status(), [429, 503], true)) {
                $default = $response->status() === 503 ? 2 : ($response->json('error.code') === 'concurrency_limit_exceeded' ? 5 : 60);
                $until = $now->addSeconds(max(1, (int) $response->header('Retry-After', $default)))->ceilSecond();
                $updates['blocked_until'] = $account->blocked_until ? max(CarbonImmutable::parse($account->blocked_until, 'UTC'), $until) : $until;
            }
            if ($updates !== []) {
                DB::table('provider_accounts')->where('scope', $account->scope)->update($updates);
            }
        }, 5);
    }

    /** A later rejection cannot prove that an earlier timed-out create was rejected. */
    public function rejected(Generation $generation, bool $wasUncertain): void
    {
        if (! $wasUncertain) {
            Generation::whereKey($generation->id)->whereNull('provider_id')->whereIn('status', ['queued', 'processing'])
                ->update(['submission_uncertain' => false, 'provider_slot' => false]);
        }
    }

    private function ensureAccount(): void
    {
        DB::table('provider_accounts')->insertOrIgnore(['scope' => config('services.pixelforge.account_scope'), 'request_timestamps' => '[]']);
    }

    private function lockAccount(): object
    {
        return DB::table('provider_accounts')->where('scope', config('services.pixelforge.account_scope'))->lockForUpdate()->first();
    }

    private function waitUntil(?string $until, string $message): void
    {
        if ($until !== null) {
            $seconds = CarbonImmutable::parse($until, 'UTC')->timestamp - CarbonImmutable::now('UTC')->timestamp;
            if ($seconds > 0) {
                throw new ProviderLimited($seconds, $message);
            }
        }
    }
}
