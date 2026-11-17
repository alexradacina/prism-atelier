<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HorizonAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_access_the_dashboard_or_its_api(): void
    {
        $this->get('/horizon')->assertRedirect('/login');
        $this->getJson('/horizon/api/stats')->assertUnauthorized();
    }

    public function test_regular_users_are_denied_even_in_the_local_environment(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        $this->actingAs(User::factory()->create())->get('/horizon')->assertForbidden();
        $this->getJson('/horizon/api/stats')->assertForbidden();
    }

    public function test_operators_can_open_the_dashboard(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->get('/horizon')->assertOk()->assertSee('Horizon');
    }
}
