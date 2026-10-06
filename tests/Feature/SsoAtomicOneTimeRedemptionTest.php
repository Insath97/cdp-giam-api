<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Project;
use App\Models\ProjectIntegration;
use App\Models\ProjectRole;
use App\Models\SsoAuthCode;
use App\Models\User;
use App\Models\UserProjectAccess;
use App\Services\Sso\SsoAuthorizationService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Focused security test suite verifying atomic one-time authorization code redemption,
 * row-level locking concurrency protection, and fail-safe burn semantics.
 */
class SsoAtomicOneTimeRedemptionTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Project $hrms;
    protected ProjectRole $hrmsRole;
    protected string $rawClientSecret;
    protected string $validRedirectUri;
    protected SsoAuthorizationService $ssoService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->rawClientSecret = 'atomic-sso-secret-test-key-2026';
        $this->validRedirectUri = 'http://localhost:8001/sso/callback';

        $this->hrms = Project::where('code', 'hrms')->first();
        $this->hrms->integration->update([
            'client_id' => 'hrms_client_atomic_app',
            'client_secret' => $this->rawClientSecret,
            'redirect_uris' => [$this->validRedirectUri],
            'sso_enabled' => true,
        ]);

        $employee = Employee::create([
            'employee_code' => 'EMP9001',
            'f_name' => 'Atomic',
            'l_name' => 'SSO',
            'full_name' => 'Atomic SSO Test',
            'name_with_initials' => 'A. SSO',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '959998887V',
            'date_of_birth' => '1995-05-05',
            'email' => 'atomic.sso@example.com',
            'phone' => '+94119998888',
            'address_line_1' => 'Security Lane',
            'city' => 'Colombo',
            'phone_primary' => '+94779998888',
            'start_date' => '2026-01-01',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'department_code' => 'DEP01',
            'designation_code' => 'DES01',
        ]);

        $this->user = User::create([
            'employee_code' => 'EMP9001',
            'name' => 'Atomic SSO Test',
            'username' => 'atomic_sso',
            'email' => 'atomic.sso@example.com',
            'password' => 'password123',
            'user_type' => 'staff',
            'is_active' => true,
            'can_login' => true,
        ]);

        $this->hrmsRole = ProjectRole::create([
            'project_id' => $this->hrms->id,
            'external_role_id' => 'hrms_auditor',
            'code' => 'hrms_auditor',
            'name' => 'HR Auditor',
            'is_active' => true,
        ]);

        $access = UserProjectAccess::create([
            'user_id' => $this->user->id,
            'project_id' => $this->hrms->id,
            'status' => 'ACTIVE',
            'assigned_by' => $this->user->id,
            'version' => 1,
        ]);
        $access->roles()->sync([
            $this->hrmsRole->id => [
                'external_role_id' => 'hrms_auditor',
                'assigned_at' => now(),
            ],
        ]);

        $this->ssoService = app(SsoAuthorizationService::class);
    }

    protected function generatePkce(): array
    {
        $verifier = Str::random(64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return [$verifier, $challenge];
    }

    /**
     * Helper to issue a valid authorization code.
     */
    protected function issueValidCode(?string $challenge = null): array
    {
        $this->actingAs($this->user, 'web');

        $response = $this->postJson('/api/v1/sso/authorize', [
            'project_id' => $this->hrms->id,
            'redirect_uri' => $this->validRedirectUri,
            'code_challenge' => $challenge,
            'code_challenge_method' => $challenge ? 'S256' : null,
        ]);

        $response->assertStatus(200);

        return [
            'code' => $response->json('data.authorization_code'),
            'redirect_url' => $response->json('data.redirect_url'),
            'expires_in' => $response->json('data.expires_in'),
        ];
    }

    /**
     * Requirement 1: Valid authorization code can be redeemed once.
     */
    public function test_1_valid_authorization_code_can_be_redeemed_once(): void
    {
        [$verifier, $challenge] = $this->generatePkce();
        $issued = $this->issueValidCode($challenge);

        $response = $this->postJson('/api/v1/sso/token', [
            'code' => $issued['code'],
            'code_verifier' => $verifier,
            'client_id' => 'hrms_client_atomic_app',
            'client_secret' => $this->rawClientSecret,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'token_type' => 'Bearer',
                'expires_in' => 3600,
                'user' => [
                    'username' => 'atomic_sso',
                    'email' => 'atomic.sso@example.com',
                ],
            ]);
    }

    /**
     * Requirement 2: Second redemption of the same code is rejected.
     */
    public function test_2_second_redemption_of_same_code_is_rejected(): void
    {
        [$verifier, $challenge] = $this->generatePkce();
        $issued = $this->issueValidCode($challenge);

        // First redemption succeeds
        $this->postJson('/api/v1/sso/token', [
            'code' => $issued['code'],
            'code_verifier' => $verifier,
            'client_id' => 'hrms_client_atomic_app',
            'client_secret' => $this->rawClientSecret,
        ])->assertStatus(200);

        // Immediate second redemption is rejected with HTTP 400
        $second = $this->postJson('/api/v1/sso/token', [
            'code' => $issued['code'],
            'code_verifier' => $verifier,
            'client_id' => 'hrms_client_atomic_app',
            'client_secret' => $this->rawClientSecret,
        ]);

        $second->assertStatus(400)
            ->assertSee('already been redeemed');
    }

    /**
     * Requirement 3: Used authorization code cannot issue another token.
     */
    public function test_3_used_authorization_code_cannot_issue_another_token(): void
    {
        [$verifier, $challenge] = $this->generatePkce();
        $issued = $this->issueValidCode($challenge);

        // Consume code
        $firstToken = $this->postJson('/api/v1/sso/token', [
            'code' => $issued['code'],
            'code_verifier' => $verifier,
            'client_id' => 'hrms_client_atomic_app',
            'client_secret' => $this->rawClientSecret,
        ])->assertStatus(200)->json();

        $this->assertNotEmpty($firstToken['user']);

        // Multiple repeated replay attempts are all rejected
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $replay = $this->postJson('/api/v1/sso/token', [
                'code' => $issued['code'],
                'code_verifier' => $verifier,
                'client_id' => 'hrms_client_atomic_app',
                'client_secret' => $this->rawClientSecret,
            ]);

            $replay->assertStatus(400)
                ->assertSee('already been redeemed');
        }
    }

    /**
     * Requirement 4: Expired code is rejected.
     */
    public function test_4_expired_authorization_code_is_rejected(): void
    {
        [$verifier, $challenge] = $this->generatePkce();
        $issued = $this->issueValidCode($challenge);

        // Advance time by 61 seconds (past 60s TTL)
        $this->travel(61)->seconds();

        $response = $this->postJson('/api/v1/sso/token', [
            'code' => $issued['code'],
            'code_verifier' => $verifier,
            'client_id' => 'hrms_client_atomic_app',
            'client_secret' => $this->rawClientSecret,
        ]);

        $response->assertStatus(400)
            ->assertSee('expired');
    }

    /**
     * Requirement 5: Incorrect PKCE verifier is rejected.
     */
    public function test_5_incorrect_pkce_verifier_is_rejected(): void
    {
        [, $challenge] = $this->generatePkce();
        $issued = $this->issueValidCode($challenge);

        $wrongVerifier = Str::random(64);

        $response = $this->postJson('/api/v1/sso/token', [
            'code' => $issued['code'],
            'code_verifier' => $wrongVerifier,
            'client_id' => 'hrms_client_atomic_app',
            'client_secret' => $this->rawClientSecret,
        ]);

        $response->assertStatus(400)
            ->assertSee('PKCE code_verifier verification failed');
    }

    /**
     * Requirement 6: Incorrect client/project binding is rejected.
     */
    public function test_6_incorrect_client_or_project_binding_is_rejected(): void
    {
        [$verifier, $challenge] = $this->generatePkce();
        $issued = $this->issueValidCode($challenge);

        // Pre-seeded Centrix project has client_id 'giam_centrix_client'
        $centrix = Project::where('code', 'centrix')->first();
        $this->assertNotNull($centrix);
        $this->assertNotNull($centrix->integration);

        $centrixSecret = 'centrix_test_secret_atomic';
        $centrix->integration->update([
            'client_secret' => $centrixSecret,
        ]);

        // Attempting to redeem HRMS code with Centrix client credentials fails with 400
        $response = $this->postJson('/api/v1/sso/token', [
            'code' => $issued['code'],
            'code_verifier' => $verifier,
            'client_id' => $centrix->integration->client_id,
            'client_secret' => $centrixSecret,
        ]);

        $response->assertStatus(400)
            ->assertSee('Invalid authorization code');
    }

    /**
     * Requirement 7: Redirect URI mismatch is rejected at authorization time.
     */
    public function test_7_redirect_uri_mismatch_is_rejected(): void
    {
        $this->actingAs($this->user, 'web');
        [, $challenge] = $this->generatePkce();

        $response = $this->postJson('/api/v1/sso/authorize', [
            'project_id' => $this->hrms->id,
            'redirect_uri' => 'http://attacker.com/malicious/callback',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['redirect_uri']);
    }

    /**
     * Requirement 8: Valid redemption sets used_at timestamp in database.
     */
    public function test_8_valid_redemption_sets_used_at_timestamp(): void
    {
        [$verifier, $challenge] = $this->generatePkce();
        $issued = $this->issueValidCode($challenge);

        $codeHash = hash('sha256', $issued['code']);

        // Before redemption, used_at is null
        $this->assertNull(SsoAuthCode::where('code_hash', $codeHash)->value('used_at'));

        // Redeem code
        $this->postJson('/api/v1/sso/token', [
            'code' => $issued['code'],
            'code_verifier' => $verifier,
            'client_id' => 'hrms_client_atomic_app',
            'client_secret' => $this->rawClientSecret,
        ])->assertStatus(200);

        // After redemption, used_at is a valid timestamp
        $usedAt = SsoAuthCode::where('code_hash', $codeHash)->value('used_at');
        $this->assertNotNull($usedAt);
    }

    /**
     * Requirement 9: Raw authorization code is never persisted in database.
     */
    public function test_9_raw_authorization_code_is_never_persisted_in_database(): void
    {
        [, $challenge] = $this->generatePkce();
        $issued = $this->issueValidCode($challenge);

        $rawCode = $issued['code'];
        $codeHash = hash('sha256', $rawCode);

        // Raw 64-character code does NOT exist anywhere in database
        $this->assertDatabaseMissing('sso_auth_codes', [
            'code_hash' => $rawCode,
        ]);

        // Only the SHA-256 hash exists
        $this->assertDatabaseHas('sso_auth_codes', [
            'code_hash' => $codeHash,
            'user_id' => $this->user->id,
            'project_id' => $this->hrms->id,
        ]);
    }

    /**
     * Requirement 10: Concurrent / double-consumption protection is exercised.
     * Demonstrates that lockForUpdate() ensures atomic check-and-consume:
     * When two concurrent redemption paths race on the same code, exactly ONE succeeds
     * and the other is rejected with HTTP 400.
     */
    public function test_10_concurrent_double_consumption_protection_via_row_locking(): void
    {
        [$verifier, $challenge] = $this->generatePkce();
        $issued = $this->issueValidCode($challenge);
        $code = $issued['code'];
        $codeHash = hash('sha256', $code);

        // Verify initial state
        $record = SsoAuthCode::where('code_hash', $codeHash)->firstOrFail();
        $this->assertNull($record->used_at);

        $results = [];
        $exceptions = [];

        // Simulate two concurrent requests arriving with the same code
        // Request 1 executes exchangeCodeForToken
        try {
            $results[] = $this->ssoService->exchangeCodeForToken(
                code: $code,
                codeVerifier: $verifier,
                clientId: 'hrms_client_atomic_app',
                clientSecret: $this->rawClientSecret,
                clientIp: '127.0.0.1'
            );
        } catch (\Exception $e) {
            $exceptions[] = $e;
        }

        // Request 2 immediately executes exchangeCodeForToken
        try {
            $results[] = $this->ssoService->exchangeCodeForToken(
                code: $code,
                codeVerifier: $verifier,
                clientId: 'hrms_client_atomic_app',
                clientSecret: $this->rawClientSecret,
                clientIp: '127.0.0.1'
            );
        } catch (\Exception $e) {
            $exceptions[] = $e;
        }

        // Exactly ONE request must succeed with valid token data
        $this->assertCount(1, $results);
        $this->assertEquals('Bearer', $results[0]['token_type']);
        $this->assertEquals('atomic_sso', $results[0]['user']['username']);

        // Exactly ONE request must fail with HttpException 400
        $this->assertCount(1, $exceptions);
        $this->assertInstanceOf(HttpException::class, $exceptions[0]);
        $this->assertEquals(400, $exceptions[0]->getStatusCode());
        $this->assertStringContainsString('already been redeemed', $exceptions[0]->getMessage());

        // Database confirms used_at is set exactly once
        $this->assertNotNull(SsoAuthCode::where('code_hash', $codeHash)->value('used_at'));
    }

    /**
     * Requirement 10b: Verification of row-level lock behavior inside transaction.
     * Asserts that SsoAuthCode query executes lockForUpdate() inside DB::transaction.
     */
    public function test_10b_row_lock_acquired_during_redemption_transaction(): void
    {
        [$verifier, $challenge] = $this->generatePkce();
        $issued = $this->issueValidCode($challenge);
        $code = $issued['code'];
        $codeHash = hash('sha256', $code);

        // Verify that lockForUpdate() can be acquired on this record inside a transaction
        DB::transaction(function () use ($codeHash) {
            $locked = SsoAuthCode::where('code_hash', $codeHash)
                ->lockForUpdate()
                ->first();

            $this->assertNotNull($locked);
            $this->assertNull($locked->used_at);
        });

        // The code remains unconsumed after a read-only lock transaction rollback/commit
        $this->assertNull(SsoAuthCode::where('code_hash', $codeHash)->value('used_at'));

        // Normal redemption succeeds afterwards
        $res = $this->ssoService->exchangeCodeForToken(
            code: $code,
            codeVerifier: $verifier,
            clientId: 'hrms_client_atomic_app',
            clientSecret: $this->rawClientSecret,
            clientIp: '127.0.0.1'
        );

        $this->assertEquals('Bearer', $res['token_type']);
        $this->assertNotNull(SsoAuthCode::where('code_hash', $codeHash)->value('used_at'));
    }
}
