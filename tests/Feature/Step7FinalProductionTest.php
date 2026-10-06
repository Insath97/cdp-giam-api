<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\PasswordResetRequest;
use App\Models\PasswordResetToken;
use App\Models\User;
use App\Services\Auth\PasswordSecurityService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class Step7FinalProductionTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected User $regularStaff;
    protected User $testUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        // Super Admin
        $empAdmin = Employee::create([
            'employee_code' => 'EMP7001',
            'f_name' => 'Super',
            'l_name' => 'Admin',
            'full_name' => 'Super Admin',
            'name_with_initials' => 'S. Admin',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '970000001V',
            'date_of_birth' => '1995-01-01',
            'email' => 'admin.prod@cdp.lk',
            'phone' => '+94112347001',
            'address_line_1' => 'HQ',
            'city' => 'Colombo',
            'phone_primary' => '+94770007001',
            'start_date' => '2026-01-01',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'department_code' => 'DEP01',
            'designation_code' => 'DES01',
        ]);

        $this->superAdmin = User::create([
            'employee_code' => 'EMP7001',
            'name' => 'Super Admin',
            'username' => 'super_admin_prod',
            'email' => 'admin.prod@cdp.lk',
            'password' => 'SecurePass@123',
            'user_type' => 'admin',
            'is_active' => true,
            'can_login' => true,
            'must_change_password' => false,
            'self_service_reset_count' => 0,
        ]);
        $this->superAdmin->syncRoles(['Super Admin']);

        // Regular Staff
        $empStaff = Employee::create([
            'employee_code' => 'EMP7002',
            'f_name' => 'Staff',
            'l_name' => 'Member',
            'full_name' => 'Staff Member',
            'name_with_initials' => 'S. Member',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '970000002V',
            'date_of_birth' => '1995-02-02',
            'email' => 'staff.member@cdp.lk',
            'phone' => '+94112347002',
            'address_line_1' => 'HQ',
            'city' => 'Colombo',
            'phone_primary' => '+94770007002',
            'start_date' => '2026-01-01',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'department_code' => 'DEP01',
            'designation_code' => 'DES01',
        ]);

        $this->regularStaff = User::create([
            'employee_code' => 'EMP7002',
            'name' => 'Staff Member',
            'username' => 'staff_prod',
            'email' => 'staff.member@cdp.lk',
            'password' => 'SecurePass@123',
            'user_type' => 'staff',
            'is_active' => true,
            'can_login' => true,
            'must_change_password' => false,
            'self_service_reset_count' => 0,
        ]);
        $this->regularStaff->syncRoles(['Staff']);

        // Test User for password reset tests
        $empTest = Employee::create([
            'employee_code' => 'EMP7003',
            'f_name' => 'Test',
            'l_name' => 'Subject',
            'full_name' => 'Test Subject',
            'name_with_initials' => 'T. Subject',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '970000003V',
            'date_of_birth' => '1995-03-03',
            'email' => 'test.subject@cdp.lk',
            'phone' => '+94112347003',
            'address_line_1' => 'HQ',
            'city' => 'Colombo',
            'phone_primary' => '+94770007003',
            'start_date' => '2026-01-01',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'department_code' => 'DEP01',
            'designation_code' => 'DES01',
        ]);

        $this->testUser = User::create([
            'employee_code' => 'EMP7003',
            'name' => 'Test Subject',
            'username' => 'test_subject',
            'email' => 'test.subject@cdp.lk',
            'password' => 'InitialTemp@123',
            'user_type' => 'staff',
            'is_active' => true,
            'can_login' => true,
            'must_change_password' => true,
            'self_service_reset_count' => 0,
        ]);
        $this->testUser->syncRoles(['Staff']);
    }

    /**
     * 1. Temporary password account requires password change upon login.
     */
    public function test_temporary_password_account_reports_must_change_password(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'login' => 'test_subject',
            'password' => 'InitialTemp@123',
        ]);

        $response->assertOk();
        $this->assertTrue($response->json('data.user.must_change_password'));
        $this->assertEquals(0, $response->json('data.user.self_service_reset_count'));
    }

    /**
     * 2. First password change succeeds and clears must_change_password.
     */
    public function test_first_password_change_succeeds_and_clears_flag(): void
    {
        $response = $this->actingAs($this->testUser)->postJson('/api/v1/auth/change-password', [
            'current_password' => 'InitialTemp@123',
            'new_password' => 'FreshNewPass@2026',
            'new_password_confirmation' => 'FreshNewPass@2026',
        ]);

        $response->assertOk();
        $this->assertFalse($this->testUser->fresh()->must_change_password);
        $this->assertTrue(Hash::check('FreshNewPass@2026', $this->testUser->fresh()->password));
    }

    /**
     * 3. Password policy is authoritatively enforced backend-side.
     */
    public function test_password_policy_enforcement(): void
    {
        // Missing uppercase
        $res1 = $this->actingAs($this->testUser)->postJson('/api/v1/auth/change-password', [
            'current_password' => 'InitialTemp@123',
            'new_password' => 'lowercase@123',
            'new_password_confirmation' => 'lowercase@123',
        ]);
        $res1->assertStatus(422);

        // Missing number
        $res2 = $this->actingAs($this->testUser)->postJson('/api/v1/auth/change-password', [
            'current_password' => 'InitialTemp@123',
            'new_password' => 'NoNumberSpecial@',
            'new_password_confirmation' => 'NoNumberSpecial@',
        ]);
        $res2->assertStatus(422);

        // Missing special character
        $res3 = $this->actingAs($this->testUser)->postJson('/api/v1/auth/change-password', [
            'current_password' => 'InitialTemp@123',
            'new_password' => 'NoSpecialChar123',
            'new_password_confirmation' => 'NoSpecialChar123',
        ]);
        $res3->assertStatus(422);

        // Too short (< 8)
        $res4 = $this->actingAs($this->testUser)->postJson('/api/v1/auth/change-password', [
            'current_password' => 'InitialTemp@123',
            'new_password' => 'Sh1@',
            'new_password_confirmation' => 'Sh1@',
        ]);
        $res4->assertStatus(422);
    }

    /**
     * 4, 5, 6, 7: Self-service resets #1, #2, #3 succeed; reset #4 is blocked.
     */
    public function test_three_self_service_resets_rule_and_fourth_blocked(): void
    {
        $securityService = app(PasswordSecurityService::class);

        // Reset #1
        $securityService->requestSelfServiceReset('test_subject');
        $token1Record = PasswordResetToken::where('user_id', $this->testUser->id)->firstOrFail();
        // Simulate known token
        $rawToken1 = 'raw_token_1_string_value_32_chars_long!!';
        $token1Record->update(['token_hash' => hash('sha256', $rawToken1)]);

        $securityService->redeemResetToken($rawToken1, 'PassOne@1234');
        $this->assertEquals(1, $this->testUser->fresh()->self_service_reset_count);

        // Reset #2
        $securityService->requestSelfServiceReset('test_subject');
        $token2Record = PasswordResetToken::where('user_id', $this->testUser->id)->firstOrFail();
        $rawToken2 = 'raw_token_2_string_value_32_chars_long!!';
        $token2Record->update(['token_hash' => hash('sha256', $rawToken2)]);

        $securityService->redeemResetToken($rawToken2, 'PassTwo@1234');
        $this->assertEquals(2, $this->testUser->fresh()->self_service_reset_count);

        // Reset #3
        $securityService->requestSelfServiceReset('test_subject');
        $token3Record = PasswordResetToken::where('user_id', $this->testUser->id)->firstOrFail();
        $rawToken3 = 'raw_token_3_string_value_32_chars_long!!';
        $token3Record->update(['token_hash' => hash('sha256', $rawToken3)]);

        $securityService->redeemResetToken($rawToken3, 'PassThree@1234');
        $this->assertEquals(3, $this->testUser->fresh()->self_service_reset_count);

        // Reset #4 Attempt: Requesting reset when count is 3 does NOT issue a token
        PasswordResetToken::where('user_id', $this->testUser->id)->delete();
        $securityService->requestSelfServiceReset('test_subject');
        $this->assertDatabaseMissing('password_reset_tokens', ['user_id' => $this->testUser->id]);

        // Trying to redeem any token for this user throws 422
        $dummyToken = PasswordResetToken::create([
            'user_id' => $this->testUser->id,
            'token_hash' => hash('sha256', 'dummy'),
            'expires_at' => now()->addMinutes(15),
        ]);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $securityService->redeemResetToken('dummy', 'PassFour@1234');
    }

    /**
     * 8, 9: Assistance request can be submitted (enumeration-safe) and duplicate PENDING is prevented.
     */
    public function test_assistance_request_submission_and_duplicate_pending_prevention(): void
    {
        // 1. Submit assistance request
        $res1 = $this->postJson('/api/v1/auth/password-reset-assistance', [
            'login' => 'test_subject',
        ]);
        $res1->assertOk();
        $this->assertStringContainsString('If an eligible account matches', $res1->json('message'));

        // Assert 1 PENDING row in database
        $this->assertEquals(1, PasswordResetRequest::where('user_id', $this->testUser->id)->where('status', 'PENDING')->count());

        // 2. Submit second request for same user
        $res2 = $this->postJson('/api/v1/auth/password-reset-assistance', [
            'login' => 'test_subject',
        ]);
        $res2->assertOk();

        // Assert STILL only 1 PENDING row (duplicate blocked)
        $this->assertEquals(1, PasswordResetRequest::where('user_id', $this->testUser->id)->where('status', 'PENDING')->count());
    }

    /**
     * 10, 11, 12: Admin assistance approval workflow, permissions, row-locking, and 409 conflict.
     */
    public function test_admin_assistance_approval_workflow_and_concurrency_locking(): void
    {
        // Create a PENDING request
        $request = PasswordResetRequest::create([
            'user_id' => $this->testUser->id,
            'status' => 'PENDING',
            'requested_at' => now(),
        ]);

        // Unauthorized user (Staff without USER_UPDATE) cannot approve
        $resForbidden = $this->actingAs($this->regularStaff)->postJson("/api/v1/admin/password-reset-requests/{$request->id}/approve");
        $resForbidden->assertStatus(403);

        // Authorized user (Super Admin with USER_UPDATE) approves
        $resApprove = $this->actingAs($this->superAdmin)->postJson("/api/v1/admin/password-reset-requests/{$request->id}/approve");
        $resApprove->assertOk();

        $freshReq = $request->fresh();
        $this->assertEquals('APPROVED', $freshReq->status);
        $this->assertEquals($this->superAdmin->id, $freshReq->reviewed_by_user_id);
        $this->assertNotNull($freshReq->reviewed_at);

        // User has must_change_password = true
        $this->assertTrue($this->testUser->fresh()->must_change_password);

        // Lifetime self_service_reset_count is NOT reset
        $this->assertEquals(0, $this->testUser->fresh()->self_service_reset_count);

        // Concurrency test: Attempting to approve or reject already APPROVED request throws 409 Conflict
        $resConflict = $this->actingAs($this->superAdmin)->postJson("/api/v1/admin/password-reset-requests/{$request->id}/approve");
        $resConflict->assertStatus(409);

        $resConflictReject = $this->actingAs($this->superAdmin)->postJson("/api/v1/admin/password-reset-requests/{$request->id}/reject", [
            'resolution_notes' => 'Late rejection',
        ]);
        $resConflictReject->assertStatus(409);
    }

    /**
     * 13. Reset token single use: token deleted upon redemption and cannot be reused.
     */
    public function test_reset_token_cannot_be_reused(): void
    {
        $securityService = app(PasswordSecurityService::class);
        $rawToken = 'single_use_test_token_string_32_chars';

        PasswordResetToken::create([
            'user_id' => $this->testUser->id,
            'token_hash' => hash('sha256', $rawToken),
            'expires_at' => now()->addMinutes(15),
        ]);

        // First redemption succeeds
        $securityService->redeemResetToken($rawToken, 'FirstRedeem@123');

        // Token record is deleted from DB
        $this->assertDatabaseMissing('password_reset_tokens', [
            'token_hash' => hash('sha256', $rawToken),
        ]);

        // Second redemption attempt throws 422
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $securityService->redeemResetToken($rawToken, 'SecondRedeem@123');
    }

    /**
     * 14. GIAM Internal RBAC hierarchy & roles endpoints return real data and principals_count.
     */
    public function test_giam_internal_rbac_endpoints(): void
    {
        $resRoles = $this->actingAs($this->superAdmin)->getJson('/api/v1/rbac/roles');
        $resRoles->assertOk();
        $roles = $resRoles->json('data');
        $this->assertIsArray($roles);
        $this->assertNotEmpty($roles);

        // Check that principals_count exists for Super Admin role
        $superAdminRole = collect($roles)->firstWhere('name', 'Super Admin');
        $this->assertNotNull($superAdminRole);
        $this->assertGreaterThanOrEqual(1, $superAdminRole['principals_count']);

        // Check hierarchy
        $resHierarchy = $this->actingAs($this->superAdmin)->getJson('/api/v1/rbac/hierarchy');
        $resHierarchy->assertOk();
        $this->assertIsArray($resHierarchy->json('data'));
    }

    /**
     * 15. Rate limiting produces 429 Too Many Requests when limits are exceeded.
     */
    public function test_rate_limiting_enforces_429_too_many_requests(): void
    {
        // Named limiter 'password-reset' allows 5 requests per minute
        for ($i = 0; $i < 5; $i++) {
            $res = $this->postJson('/api/v1/auth/forgot-password', ['login' => 'test_subject']);
            $res->assertOk();
        }

        // 6th request triggers HTTP 429 Too Many Requests
        $resThrottled = $this->postJson('/api/v1/auth/forgot-password', ['login' => 'test_subject']);
        $resThrottled->assertStatus(429);
    }
}
