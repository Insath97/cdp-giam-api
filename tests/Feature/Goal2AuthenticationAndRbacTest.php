<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class Goal2AuthenticationAndRbacTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        // Register dummy routes guarded by GIAM permission middleware to test authorization gates
        Route::middleware(['web', 'auth:web', 'giam.can_login', 'giam.permission:USER_CREATE'])->get('/api/test/users-create', function () {
            return response()->json(['status' => 'success', 'action' => 'user_created']);
        });

        Route::middleware(['web', 'auth:web', 'giam.can_login', 'giam.permission:AUDIT_VIEW'])->get('/api/test/audit-view', function () {
            return response()->json(['status' => 'success', 'action' => 'audit_viewed']);
        });
    }

    /**
     * Test 1: Successful login with username.
     */
    public function test_successful_login_with_username(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'login' => 'user01',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'user' => [
                        'username' => 'user01',
                        'email' => 'sample@example.com',
                        'user_type' => 'staff',
                    ],
                ],
            ])
            ->assertJsonStructure([
                'status',
                'message',
                'data' => [
                    'user',
                    'employee',
                    'roles',
                    'permissions',
                ],
            ]);

        $this->assertAuthenticated();

        // Check user record updated
        $user = User::where('username', 'user01')->first();
        $this->assertEquals(0, $user->failed_login_attempts);
        $this->assertNotNull($user->last_login_at);

        // Verify audit log
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $user->id,
            'action' => 'LOGIN_SUCCESS',
            'status' => 'SUCCESS',
        ]);
    }

    /**
     * Test 2: Successful login with email.
     */
    public function test_successful_login_with_email(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'login' => 'sample@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200);
        $this->assertAuthenticated();
    }

    /**
     * Test 3: Failed login attempt with incorrect password.
     */
    public function test_failed_login_with_wrong_password(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'login' => 'user01',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['login']);

        $this->assertGuest();

        $user = User::where('username', 'user01')->first();
        $this->assertEquals(1, $user->failed_login_attempts);

        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $user->id,
            'action' => 'LOGIN_FAILED',
            'status' => 'FAILED',
        ]);
    }

    /**
     * Test 4: Account lockout after 5 consecutive failed attempts.
     */
    public function test_brute_force_lockout_after_five_failed_attempts(): void
    {
        $user = User::where('username', 'user01')->first();

        // Perform 4 failed attempts
        for ($i = 1; $i <= 4; $i++) {
            $resp = $this->postJson('/api/v1/auth/login', [
                'login' => 'user01',
                'password' => 'wrongpassword',
            ]);
            $resp->assertStatus(422);
        }

        $user->refresh();
        $this->assertEquals(4, $user->failed_login_attempts);
        $this->assertNull($user->lockout_until);

        // 5th attempt triggers lockout
        $fifthResponse = $this->postJson('/api/v1/auth/login', [
            'login' => 'user01',
            'password' => 'wrongpassword',
        ]);
        $fifthResponse->assertStatus(429);

        $user->refresh();
        $this->assertEquals(5, $user->failed_login_attempts);
        $this->assertNotNull($user->lockout_until);
        $this->assertTrue($user->lockout_until->isFuture());

        // 6th attempt is rejected because of lockout
        $sixthResponse = $this->postJson('/api/v1/auth/login', [
            'login' => 'user01',
            'password' => 'password123', // Even with correct password, locked out!
        ]);
        $sixthResponse->assertStatus(429);

        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $user->id,
            'action' => 'LOGIN_LOCKED',
            'status' => 'WARNING',
        ]);
    }

    /**
     * Test 5: Inactive user cannot log in (HTTP 403).
     */
    public function test_inactive_user_cannot_login(): void
    {
        $user = User::where('username', 'user01')->first();
        $user->update(['is_active' => false]);

        $response = $this->postJson('/api/v1/auth/login', [
            'login' => 'user01',
            'password' => 'password123',
        ]);

        $response->assertStatus(403)
            ->assertSee('inactive');

        $this->assertGuest();

        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $user->id,
            'action' => 'LOGIN_BLOCKED',
            'status' => 'WARNING',
        ]);
    }

    /**
     * Test 6: User with can_login = 0 cannot log in (HTTP 403).
     */
    public function test_can_login_false_user_cannot_login(): void
    {
        $user = User::where('username', 'user01')->first();
        $user->update(['can_login' => false]);

        $response = $this->postJson('/api/v1/auth/login', [
            'login' => 'user01',
            'password' => 'password123',
        ]);

        $response->assertStatus(403)
            ->assertSee('disabled');

        $this->assertGuest();

        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $user->id,
            'action' => 'LOGIN_BLOCKED',
            'status' => 'WARNING',
        ]);
    }

    /**
     * Test 7: EnsureUserCanLogin middleware blocks active session if user deactivated.
     */
    public function test_ensure_user_can_login_middleware_blocks_session(): void
    {
        $user = User::where('username', 'user01')->first();
        $this->actingAs($user, 'web');

        // Initial request succeeds
        $resp = $this->getJson('/api/v1/auth/me');
        $resp->assertStatus(200);

        // Deactivate user
        $user->update(['can_login' => false]);

        // Subsequent request is blocked by middleware
        $blockedResp = $this->getJson('/api/v1/auth/me');
        $blockedResp->assertStatus(403)
            ->assertJson([
                'status' => 'error',
                'code' => 'LOGIN_REVOKED',
            ]);
    }

    /**
     * Test 8: Logout terminates session and logs audit.
     */
    public function test_logout_terminates_session(): void
    {
        $user = User::where('username', 'user01')->first();
        $this->actingAs($user, 'web');

        $response = $this->postJson('/api/v1/auth/logout');
        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'Successfully logged out.',
            ]);

        $this->assertGuest('web');

        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $user->id,
            'action' => 'LOGOUT',
            'status' => 'SUCCESS',
        ]);
    }

    /**
     * Test 9: Spatie permission enforcement and Super Admin bypass.
     */
    public function test_spatie_giam_permission_enforcement(): void
    {
        // 1. Create a staff user with no admin permissions
        $staffEmployee = Employee::create([
            'employee_code' => 'EMP2001',
            'f_name' => 'Staff',
            'l_name' => 'User',
            'full_name' => 'Staff User',
            'name_with_initials' => 'S. User',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '987654321V',
            'date_of_birth' => '1998-08-18',
            'email' => 'staff@example.com',
            'phone' => '+94115556677',
            'address_line_1' => 'Street 2',
            'city' => 'Colombo',
            'phone_primary' => '+94772233445',
            'start_date' => '2026-03-01',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'department_code' => 'DEP01',
            'designation_code' => 'DES01',
        ]);

        $staffUser = User::create([
            'employee_code' => 'EMP2001',
            'name' => 'Staff User',
            'username' => 'staff01',
            'email' => 'staff@example.com',
            'password' => 'password123',
            'user_type' => 'staff',
            'is_active' => true,
            'can_login' => true,
        ]);
        $staffUser->assignRole('Staff');

        // Staff user trying to access USER_CREATE route is rejected (HTTP 403)
        $this->actingAs($staffUser, 'web');
        $staffResponse = $this->getJson('/api/test/users-create');
        $staffResponse->assertStatus(403)
            ->assertJson([
                'status' => 'error',
                'code' => 'FORBIDDEN',
            ]);

        // 2. Super Admin user accesses same route successfully (HTTP 200)
        $superAdmin = User::where('username', 'user01')->first();
        $this->actingAs($superAdmin, 'web');

        $adminResponse = $this->getJson('/api/test/users-create');
        $adminResponse->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'action' => 'user_created',
            ]);
    }
}
