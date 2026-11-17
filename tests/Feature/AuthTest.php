<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_hashes_password_and_cannot_grant_admin_access(): void
    {
        $this->post('/register', [
            'name' => 'Alex', 'email' => 'alex@example.com', 'password' => 'a-secure-password',
            'password_confirmation' => 'a-secure-password', 'is_admin' => true, 'balance_nanos' => 999999999,
        ])->assertRedirect('/studio');
        $user = User::first();
        $this->assertAuthenticatedAs($user);
        $this->assertTrue(Hash::check('a-secure-password', $user->password));
        $this->assertFalse($user->is_admin);
        $this->assertSame(0, $user->balance_nanos);
    }

    public function test_login_logout_and_failed_login(): void
    {
        $user = User::factory()->create(['password' => 'a-secure-password']);
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->post('/login', ['email' => $user->email, 'password' => 'a-secure-password'])->assertRedirect('/studio');
        $this->assertAuthenticatedAs($user);
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_login_attempts_are_throttled(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/login', ['email' => 'nobody@example.com', 'password' => 'wrong'])->assertUnprocessable();
        }
        $this->postJson('/login', ['email' => 'nobody@example.com', 'password' => 'wrong'])->assertStatus(429);
    }
}
