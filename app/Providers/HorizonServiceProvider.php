<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /** Require operator access in every environment, including local development. */
    protected function authorization(): void
    {
        $this->gate();
        Horizon::auth(fn (Request $request): bool => Gate::forUser($request->user())->allows('viewHorizon'));
    }

    protected function gate(): void
    {
        Gate::define('viewHorizon', fn (User $user): bool => $user->is_admin);
    }
}
