<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RoleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('role:super_admin,manager')->get(
            '/_tests/management-only',
            static fn () => response()->json(['authorized' => true]),
        );
    }

    /** @return array<string, array{UserRole}> */
    public static function authorizedRoles(): array
    {
        return [
            'super administrator' => [UserRole::SuperAdmin],
            'manager' => [UserRole::Manager],
        ];
    }

    #[DataProvider('authorizedRoles')]
    public function test_configured_roles_are_authorized(UserRole $role): void
    {
        $user = User::factory()->create(['role' => $role]);

        $this->actingAs($user)
            ->get('/_tests/management-only')
            ->assertOk()
            ->assertJson(['authorized' => true]);
    }

    /** @return array<string, array{UserRole}> */
    public static function unauthorizedRoles(): array
    {
        return [
            'staff' => [UserRole::Staff],
            'driver' => [UserRole::Driver],
            'customer' => [UserRole::Customer],
        ];
    }

    #[DataProvider('unauthorizedRoles')]
    public function test_other_roles_are_forbidden(UserRole $role): void
    {
        $user = User::factory()->create(['role' => $role]);

        $this->actingAs($user)
            ->get('/_tests/management-only')
            ->assertForbidden();
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->get('/_tests/management-only')->assertUnauthorized();
    }

    /** @return array<string, array{AccountStatus}> */
    public static function blockedStatuses(): array
    {
        return [
            'inactive' => [AccountStatus::Inactive],
            'suspended' => [AccountStatus::Suspended],
        ];
    }

    #[DataProvider('blockedStatuses')]
    public function test_non_active_accounts_are_forbidden(AccountStatus $status): void
    {
        $user = User::factory()->create([
            'role' => UserRole::SuperAdmin,
            'status' => $status,
        ]);

        $this->actingAs($user)
            ->get('/_tests/management-only')
            ->assertForbidden();
    }

    public function test_user_attributes_are_cast_to_enums_and_helpers_fail_closed(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::Staff,
            'status' => AccountStatus::Active,
        ])->fresh();

        $this->assertSame(UserRole::Staff, $user->role);
        $this->assertSame(AccountStatus::Active, $user->status);
        $this->assertTrue($user->hasRole(UserRole::Staff));
        $this->assertTrue($user->hasAnyRole(UserRole::Manager, UserRole::Staff));
        $this->assertFalse($user->hasRole('not_a_role'));
        $this->assertTrue($user->isActive());
        $this->assertTrue($user->canAccessAdministration());
    }
}
