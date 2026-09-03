<?php

namespace Tests\Feature;

use App\Mail\NewAccountCredentialsMail;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AutomaticCredentialDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected User $staffUser;
    protected Employee $testEmployee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->superAdmin = User::where('username', 'user01')->first();

        // Create standard employee
        $this->testEmployee = Employee::create([
            'employee_code' => 'EMP_AUTO_TEST',
            'f_name' => 'Auto',
            'l_name' => 'Test',
            'full_name' => 'Auto Test Delivery',
            'name_with_initials' => 'A. T. Delivery',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '199512345678',
            'date_of_birth' => '1995-05-05',
            'email' => 'delivery_test@cdp.lk',
            'phone' => '+94112233445',
            'phone_primary' => '+94771234560',
            'address_line_1' => 'No 45 Galle Road',
            'city' => 'Colombo',
            'country' => 'Sri Lanka',
            'start_date' => '2026-01-01',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'department_code' => 'DEP01',
            'designation_code' => 'DES01',
            'is_active' => true,
        ]);

        // Create staff employee and user
        $staffEmp = Employee::create([
            'employee_code' => 'EMP_STAFF_TEST',
            'f_name' => 'Staff',
            'l_name' => 'Test',
            'full_name' => 'Staff No Perms',
            'name_with_initials' => 'S. N. Perms',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '199012345679',
            'date_of_birth' => '1990-01-01',
            'email' => 'staff_no_perms@example.com',
            'phone' => '+94112233446',
            'phone_primary' => '+94771234561',
            'address_line_1' => 'No 46 Galle Road',
            'city' => 'Colombo',
            'country' => 'Sri Lanka',
            'start_date' => '2026-01-01',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'department_code' => 'DEP01',
            'designation_code' => 'DES01',
            'is_active' => true,
        ]);

        $this->staffUser = User::create([
            'employee_code' => $staffEmp->employee_code,
            'name' => 'Staff No Perms',
            'username' => 'staff_no_perms',
            'email' => 'staff_no_perms@example.com',
            'password' => Hash::make('Password123!'),
            'is_active' => true,
            'can_login' => true,
        ]);
        $this->staffUser->syncRoles(['Staff']);
    }

    public function test_principal_creation_triggers_automatic_credential_mail(): void
    {
        Mail::fake();

        $response = $this->actingAs($this->superAdmin, 'web')->postJson('/api/v1/users', [
            'employee_code' => $this->testEmployee->employee_code,
            'name' => 'Auto Test Delivery',
            'username' => 'auto_test_user',
            'email' => 'delivery_test@cdp.lk',
            'roles' => ['Staff'],
            'is_active' => true,
            'can_login' => true,
        ]);

        $response->assertStatus(201);

        Mail::assertSent(NewAccountCredentialsMail::class, function (NewAccountCredentialsMail $mail) {
            return $mail->hasTo('delivery_test@cdp.lk') &&
                   $mail->envelope()->subject === 'Your GIAM Account Is Ready' &&
                   $mail->user->username === 'auto_test_user' &&
                   ! empty($mail->temporaryPassword);
        });
    }

    public function test_mail_contains_correct_username_and_recipient(): void
    {
        Mail::fake();

        $this->actingAs($this->superAdmin, 'web')->postJson('/api/v1/users', [
            'employee_code' => $this->testEmployee->employee_code,
            'name' => 'Auto Test Delivery',
            'username' => 'siva_principal',
            'email' => 'delivery_test@cdp.lk',
            'roles' => ['Admin'],
            'is_active' => true,
            'can_login' => true,
        ]);

        Mail::assertSent(NewAccountCredentialsMail::class, function (NewAccountCredentialsMail $mail) {
            $this->assertEquals('delivery_test@cdp.lk', $mail->user->email);
            $this->assertEquals('siva_principal', $mail->user->username);
            $this->assertEquals('Your GIAM Account Is Ready', $mail->envelope()->subject);
            return true;
        });
    }

    public function test_login_url_comes_from_configuration_rather_than_hardcoded_localhost(): void
    {
        Mail::fake();
        Config::set('app.frontend_url', 'https://identity.enterprise.corp');

        $this->actingAs($this->superAdmin, 'web')->postJson('/api/v1/users', [
            'employee_code' => $this->testEmployee->employee_code,
            'name' => 'Auto Test Delivery',
            'username' => 'enterprise_user',
            'email' => 'delivery_test@cdp.lk',
            'roles' => ['Staff'],
            'is_active' => true,
            'can_login' => true,
        ]);

        Mail::assertSent(NewAccountCredentialsMail::class, function (NewAccountCredentialsMail $mail) {
            return $mail->loginUrl === 'https://identity.enterprise.corp/login';
        });
    }

    public function test_must_change_password_and_hash_storage(): void
    {
        Mail::fake();

        $this->actingAs($this->superAdmin, 'web')->postJson('/api/v1/users', [
            'employee_code' => $this->testEmployee->employee_code,
            'name' => 'Auto Test Delivery',
            'username' => 'hashed_user',
            'email' => 'delivery_test@cdp.lk',
            'roles' => ['Staff'],
            'is_active' => true,
            'can_login' => true,
        ]);

        $created = User::where('username', 'hashed_user')->firstOrFail();

        $this->assertTrue((bool) $created->must_change_password);
        $this->assertStringStartsWith('$2y$', $created->password);
    }

    public function test_api_response_does_not_contain_temporary_password(): void
    {
        Mail::fake();

        $response = $this->actingAs($this->superAdmin, 'web')->postJson('/api/v1/users', [
            'employee_code' => $this->testEmployee->employee_code,
            'name' => 'Auto Test Delivery',
            'username' => 'clean_response_user',
            'email' => 'delivery_test@cdp.lk',
            'roles' => ['Staff'],
            'is_active' => true,
            'can_login' => true,
        ]);

        $response->assertStatus(201);
        $json = $response->json();

        $this->assertArrayNotHasKey('password', $json['data']);
        $this->assertArrayNotHasKey('temporary_password', $json['data']);
        $this->assertArrayNotHasKey('raw_password', $json['data']);
    }

    public function test_audit_data_does_not_contain_temporary_password(): void
    {
        Mail::fake();

        $this->actingAs($this->superAdmin, 'web')->postJson('/api/v1/users', [
            'employee_code' => $this->testEmployee->employee_code,
            'name' => 'Auto Test Delivery',
            'username' => 'audited_user',
            'email' => 'delivery_test@cdp.lk',
            'roles' => ['Staff'],
            'is_active' => true,
            'can_login' => true,
        ]);

        $created = User::where('username', 'audited_user')->firstOrFail();
        $auditLogs = AuditLog::where('entity_id', (string) $created->id)->get();

        foreach ($auditLogs as $log) {
            $serialized = json_encode([$log->before_data, $log->after_data, $log->metadata]);
            $this->assertStringNotContainsString('password_raw', $serialized);
            $this->assertStringNotContainsString('tempPassword', $serialized);
            $this->assertStringNotContainsString('rawPassword', $serialized);
        }
    }

    public function test_successful_mail_submission_records_safe_audit_event(): void
    {
        Mail::fake();

        $this->actingAs($this->superAdmin, 'web')->postJson('/api/v1/users', [
            'employee_code' => $this->testEmployee->employee_code,
            'name' => 'Auto Test Delivery',
            'username' => 'mail_audit_user',
            'email' => 'delivery_test@cdp.lk',
            'roles' => ['Staff'],
            'is_active' => true,
            'can_login' => true,
        ]);

        $created = User::where('username', 'mail_audit_user')->firstOrFail();

        $mailAudit = AuditLog::where('entity_id', (string) $created->id)
            ->where('action', 'MAIL_SUBMITTED')
            ->first();

        $this->assertNotNull($mailAudit, 'Expected MAIL_SUBMITTED audit log');
        $this->assertEquals('SUCCESS', $mailAudit->status);
        $this->assertEquals('delivery_test@cdp.lk', $mailAudit->after_data['recipient_email']);
        $this->assertEquals('NEW_ACCOUNT_CREDENTIALS', $mailAudit->after_data['mail_type']);
        $this->assertEquals('SUBMITTED_TO_TRANSPORT', $mailAudit->after_data['delivery_status']);
    }

    public function test_mail_transport_exception_does_not_rollback_principal_and_records_mail_failed(): void
    {
        // Mock Mail facade to simulate SMTP connection timeout/refusal
        Mail::shouldReceive('to->send')
            ->once()
            ->andThrow(new \Exception('Connection to smtp-mail.outlook.com:587 timed out.'));

        $response = $this->actingAs($this->superAdmin, 'web')->postJson('/api/v1/users', [
            'employee_code' => $this->testEmployee->employee_code,
            'name' => 'Auto Test Delivery',
            'username' => 'resilient_user',
            'email' => 'delivery_test@cdp.lk',
            'roles' => ['Staff'],
            'is_active' => true,
            'can_login' => true,
        ]);

        // Principal creation succeeds despite mail transport failure
        $response->assertStatus(201);

        $created = User::where('username', 'resilient_user')->first();
        $this->assertNotNull($created, 'User must remain persisted even if mail transport throws exception');
        $this->assertTrue((bool) $created->must_change_password);

        // Safe audit log for failure
        $failAudit = AuditLog::where('entity_id', (string) $created->id)
            ->where('action', 'MAIL_FAILED')
            ->first();

        $this->assertNotNull($failAudit, 'Expected MAIL_FAILED audit log');
        $this->assertEquals('WARNING', $failAudit->status);
        $this->assertEquals('delivery_test@cdp.lk', $failAudit->after_data['recipient_email']);

        // Safe credential delivery status in response
        $this->assertEquals('failed', $response->json('data.credential_delivery.status'));
    }

    public function test_resend_credentials_generates_new_password_hashes_it_invalidates_old_and_sends_mail(): void
    {
        Mail::fake();

        // 1. Create user initially
        $user = User::create([
            'employee_code' => $this->testEmployee->employee_code,
            'name' => 'Resend Test User',
            'username' => 'resend_user',
            'email' => 'delivery_test@cdp.lk',
            'password' => Hash::make('InitialPassword123!'),
            'user_type' => 'staff',
            'must_change_password' => false,
            'is_active' => true,
            'can_login' => true,
        ]);
        $user->syncRoles(['Staff']);

        $initialHash = $user->password;

        // 2. Call resend-credentials as authorized admin
        $response = $this->actingAs($this->superAdmin, 'web')->postJson("/api/v1/users/{$user->id}/resend-credentials");

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
            'mail_submitted' => true,
        ]);

        // 3. User refreshed: new hash, must_change_password = true
        $user->refresh();
        $this->assertNotEquals($initialHash, $user->password, 'Password hash must change');
        $this->assertTrue((bool) $user->must_change_password, 'must_change_password must be re-enabled');

        // 4. Mail was sent with new credentials
        Mail::assertSent(NewAccountCredentialsMail::class, function (NewAccountCredentialsMail $mail) use ($user) {
            return $mail->hasTo('delivery_test@cdp.lk') &&
                   $mail->user->id === $user->id;
        });

        // 5. Audit log recorded
        $regenAudit = AuditLog::where('entity_id', (string) $user->id)
            ->where('action', 'USER_CREDENTIALS_REGENERATED')
            ->first();

        $this->assertNotNull($regenAudit);
    }

    public function test_unauthorized_users_cannot_trigger_credential_resend(): void
    {
        $user = User::create([
            'employee_code' => $this->testEmployee->employee_code,
            'name' => 'Target User',
            'username' => 'target_user',
            'email' => 'target@example.com',
            'password' => Hash::make('Password123!'),
            'user_type' => 'staff',
            'is_active' => true,
            'can_login' => true,
        ]);

        $response = $this->actingAs($this->staffUser, 'web')->postJson("/api/v1/users/{$user->id}/resend-credentials");

        $response->assertStatus(403);
    }

    public function test_cannot_resend_credentials_for_inactive_or_disabled_user(): void
    {
        $user = User::create([
            'employee_code' => $this->testEmployee->employee_code,
            'name' => 'Inactive User',
            'username' => 'inactive_user',
            'email' => 'inactive@example.com',
            'password' => Hash::make('Password123!'),
            'user_type' => 'staff',
            'is_active' => false,
            'can_login' => false,
        ]);

        $response = $this->actingAs($this->superAdmin, 'web')->postJson("/api/v1/users/{$user->id}/resend-credentials");

        $response->assertStatus(422);
    }
}
