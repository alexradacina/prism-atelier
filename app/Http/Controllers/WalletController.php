<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Money;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WalletController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->is_admin, 403);

        return Inertia::render('Wallets', [
            'users' => fn () => User::orderBy('id')->paginate(25)->through(fn (User $user) => [
                'id' => $user->id, 'name' => $user->name, 'email' => $user->email,
                'balance' => Money::format($user->balance_nanos),
                'reserved' => Money::format($user->reserved_nanos),
                'available' => Money::format($user->balance_nanos - $user->reserved_nanos),
            ]),
        ]);
    }

    public function show(Request $request, User $user, WalletService $wallets): Response
    {
        abort_unless($request->user()->is_admin, 403);

        return Inertia::render('UserWallet', ['wallet' => fn () => $wallets->details($user)]);
    }
}
