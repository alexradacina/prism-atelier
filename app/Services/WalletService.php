<?php

namespace App\Services;

use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class WalletService
{
    public function details(User $owner): array
    {
        return DB::transaction(function () use ($owner) {
            $user = User::query()->sharedLock()->findOrFail($owner->id);
            $ledger = LedgerEntry::where('user_id', $user->id);
            $added = (int) (clone $ledger)->where('amount_nanos', '>', 0)->sum('amount_nanos');
            $spent = -(int) (clone $ledger)->where('amount_nanos', '<', 0)->sum('amount_nanos');
            $held = Generation::where('user_id', $user->id)->where('reserved_nanos', '>', 0);
            $heldTotal = (int) (clone $held)->sum('reserved_nanos');

            return [
                'owner' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
                'trend' => (clone $ledger)->latest('id')->limit(30)->get(['id', 'created_at', 'balance_after_nanos'])->reverse()->values()
                    ->map(fn (LedgerEntry $entry) => ['label' => $entry->created_at->utc()->format('M j H:i').' · #'.$entry->id,
                        'balance' => Money::format($entry->balance_after_nanos)]),
                'summary' => [
                    'balance' => Money::format($user->balance_nanos),
                    'reserved' => Money::format($user->reserved_nanos),
                    'available' => Money::format($user->balance_nanos - $user->reserved_nanos),
                    'added' => Money::format($added), 'spent' => Money::format($spent),
                    'reconciled' => $added - $spent === $user->balance_nanos && $heldTotal === $user->reserved_nanos,
                    'balance_difference' => Money::format($user->balance_nanos - ($added - $spent)),
                    'reservation_difference' => Money::format($user->reserved_nanos - $heldTotal),
                ],
                'reservations' => $held->latest()->paginate(10, ['*'], 'reservations_page')->withQueryString()
                    ->through(fn (Generation $generation) => [
                        'id' => $generation->id, 'prompt' => $generation->prompt, 'status' => $generation->status,
                        'reserved' => Money::format($generation->reserved_nanos),
                        'estimated' => Money::format($generation->estimated_nanos),
                        'created_at' => $generation->created_at->toIso8601String(),
                    ]),
                'entries' => $ledger->with('generation:id,prompt,status')->latest('id')->paginate(20)->withQueryString()
                    ->through(fn (LedgerEntry $entry) => [
                        'id' => $entry->id, 'kind' => $entry->kind,
                        'generation_id' => $entry->generation_id,
                        'description' => $entry->generation?->prompt ?? 'Demo credit purchase',
                        'generation_status' => $entry->generation?->status,
                        'added' => $entry->amount_nanos > 0 ? Money::format($entry->amount_nanos) : null,
                        'spent' => $entry->amount_nanos < 0 ? Money::format(-$entry->amount_nanos) : null,
                        'balance_after' => Money::format($entry->balance_after_nanos),
                        'created_at' => $entry->created_at->toIso8601String(),
                    ]),
            ];
        }, 5);
    }
}
