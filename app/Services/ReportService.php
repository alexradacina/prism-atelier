<?php

namespace App\Services;

use App\Models\Generation;
use Illuminate\Support\Facades\Cache;

class ReportService
{
    public function build(string $start, string $end, Pixelforge $provider): array
    {
        $generations = Generation::with('user:id,name,email')->where('status', 'succeeded')
            ->whereBetween('completed_at', [$start.' 00:00:00', $end.' 23:59:59'])->get();
        $aggregate = function ($rows): array {
            $charged = (int) $rows->sum('actual_nanos');
            $cost = (int) $rows->sum('provider_nanos');

            return [
                'generations' => $rows->count(), 'charged' => Money::format($charged),
                'provider' => Money::format($cost), 'profit' => Money::format($charged - $cost),
            ];
        };
        $remote = Cache::remember('usage:'.hash('sha256', (string) config('services.pixelforge.key')).':'.$start.':'.$end,
            30, fn () => $provider->usage($start, $end));
        $daily = collect($remote['data'])->map(function ($day) use ($generations, $aggregate) {
            $rows = $generations->filter(fn ($generation) => $generation->completed_at->utc()->toDateString() === $day['date']);
            $local = $aggregate($rows);
            $cost = Money::parse($day['cost_usd']);

            return [
                'date' => $day['date'], ...$local,
                'provider' => Money::format($cost), 'provider_generations' => $day['generations'],
                'local_provider' => $local['provider'],
                'difference' => Money::format($cost - (int) $rows->sum('provider_nanos')),
                'profit' => Money::format((int) $rows->sum('actual_nanos') - $cost),
            ];
        });
        $users = $generations->groupBy('user_id')->map(fn ($rows) => [
            'name' => $rows->first()->user->name, 'email' => $rows->first()->user->email, ...$aggregate($rows),
            'days' => $rows->groupBy(fn ($generation) => $generation->completed_at->utc()->toDateString())
                ->map(fn ($dayRows, $date) => ['date' => $date, ...$aggregate($dayRows)])->values(),
        ])->values();
        $totalCost = Money::parse($remote['total']['cost_usd']);

        return [
            'daily' => $daily, 'users' => $users,
            'total' => [
                ...$aggregate($generations), 'provider' => Money::format($totalCost),
                'profit' => Money::format((int) $generations->sum('actual_nanos') - $totalCost),
                'difference' => Money::format($totalCost - (int) $generations->sum('provider_nanos')),
            ],
            'checked_at' => now()->toIso8601String(),
        ];
    }
}
