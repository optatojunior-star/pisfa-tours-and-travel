<?php

namespace Tests\Feature\Auth;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));

        $user = User::query()->where('email', 'test@example.com')->sole();

        $this->assertNull($user->phone);
        $this->assertSame(UserRole::Customer, $user->role);
        $this->assertSame(AccountStatus::Active, $user->status);
    }

    public function test_registration_captures_phone_and_ignores_privileged_account_fields(): void
    {
        $this->post('/register', [
            'name' => 'Safe Customer',
            'email' => 'customer@example.com',
            'phone' => '+256 700 123456',
            'password' => 'password',
            'password_confirmation' => 'password',
            'role' => UserRole::SuperAdmin->value,
            'status' => AccountStatus::Suspended->value,
        ])->assertSessionHasNoErrors();

        $user = User::query()->where('email', 'customer@example.com')->sole();

        $this->assertSame('+256 700 123456', $user->phone);
        $this->assertSame(UserRole::Customer, $user->role);
        $this->assertSame(AccountStatus::Active, $user->status);
    }
}
