<?php

namespace App\Http\Middleware;

use App\Services\Money;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        return [...parent::share($request),
            'auth' => fn () => $request->user() ? [
                'id' => $request->user()->id,
                'name' => $request->user()->name,
                'email' => $request->user()->email,
                'is_admin' => $request->user()->is_admin,
                'balance' => Money::format($request->user()->balance_nanos),
                'reserved' => Money::format($request->user()->reserved_nanos),
                'available' => Money::format($request->user()->balance_nanos - $request->user()->reserved_nanos),
            ] : null,
            'flash' => fn () => ['message' => $request->session()->get('message')],
        ];
    }
}
