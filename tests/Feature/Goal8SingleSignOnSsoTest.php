<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Project;
use App\Models\ProjectRole;
use App\Models\SsoAuthCode;
use App\Models\User;
use App\Models\UserProjectAccess;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

class Goal8SingleSignOnSsoTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Project $hrms;
    protected ProjectRole $hrmsRole;
    protected string $rawClientSecret;
    protected string $validRedirectUri;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->rawClientSecret = 'hrms-super-secret-123';
        $this->validRedirectUri = 'http://localhost:8001/sso/callback';

        $this->hrms = Project::where('code', 'hrms')->first();
        $this->hrms->integration->update([
            'client_id' => 'hrms_client_app',
            'client_secret' => $this->rawClientSecret,
            'redirect_uris' => [$this->validRedirectUri],
            'sso_enabled' => true,
        ]);

        $employee = Employee::create([
            'employee_code' => 'EMP8001',
            'f_name' => 'John',
            'l_name' => 'SSO',
            'full_name' => 'John SSO',
            'name_with_initials' => 'J. SSO',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '951112223V',
            'date_of_birth' => '1995-01-01',
            'email' => 'john.sso@example.com',
            'phone' => '+94112345678',
            'address_line_1' => 'SSO Street',
            'city' => 'Colombo',
            'phone_primary' => '+94771122334',
            'start_date' => '2026-01-01',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'department_code' => 'DEP01',
            'designation_code' => 'DES01',
        ]);

        $this->user = User::create([
            'employee_code' => 'EMP8001',
            'name' => 'John SSO',
            'username' => 'john_sso',
            'email' => 'john.sso@example.com',
            'password' => 'password123',
            'user_type' => 'staff',
            'is_active' => true,
            'can_login' => true,
        ]);

        $this->hrmsRole = ProjectRole::create([
            'project_id' => $this->hrms->id,
            'external_role_id' => 'hrms_analyst',
            'code' => 'hrms_analyst',
            'name' => 'HR Analyst',
            'is_active' => true,
        ]);

        // Assign ACTIVE access
        $access = UserProjectAccess::create([
            'user_id' => $this->user->id,
            'project_id' => $this->hrms->id,
            'status' => 'ACTIVE',
            'assigned_by' => $this->user->id,
            'version' => 1,
        ]);
        $access->roles()->sync([
            $this->hrmsRole->id => [
                'external_role_id' => 'hrms_analyst',
                'assigned_at' => now(),
            ],
        ]);
    }

    /**
     * Helper to generate RFC 7636 PKCE code_verifier and code_challenge.
     */
    protected function generatePkce(): array
    {
        $verifier = Str::random(64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return [$verifier, $challenge];
    }

    /**
     * Test 1: Full successful SSO authorization code issuance and server-to-server token exchange.
     */
    public function test_successful_sso_authorization_and_token_exchange(): void
    {
        $this->actingAs($this->user, 'web');
        [$verifier, $challenge] = $this->generatePkce();

        // 1. Authorize handoff
        $authResponse = $this->postJson('/api/v1/sso/authorize', [
            'project_id' => $this->hrms->id,
            'redirect_uri' => $this->validRedirectUri,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);

        $authResponse->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'expires_in' => 60,
                ],
            ]);

        $code = $authResponse->json('data.authorization_code');
        $this->assertNotEmpty($code);
        $this->assertStringContainsString("code={$code}", $authResponse->json('data.redirect_url'));

        // Verify SHA-256 hash stored in DB
        $this->assertDatabaseHas('sso_auth_codes', [
            'code_hash' => hash('sha256', $code),
            'user_id' => $this->user->id,
            'project_id' => $this->hrms->id,
            'used_at' => null,
        ]);

        // 2. Server-to-server token exchange by downstream project backend
        $tokenResponse = $this->postJson('/api/v1/sso/token', [
            'code' => $code,
            'code_verifier' => $verifier,
            'client_id' => 'hrms_client_app',
            'client_secret' => $this->rawClientSecret,
        ]);

        $tokenResponse->assertStatus(200)
            ->assertJson([
                'token_type' => 'Bearer',
                'user' => [
                    'externalRef' => 'EMP8001',
                    'username' => 'john_sso',
                    'email' => 'john.sso@example.com',
                ],
            ]);

        // Verify roles list contains hrms_analyst
        $this->assertEquals(['hrms_analyst'], $tokenResponse->json('user.roles'));

        // Verify code marked used
        $this->assertNotNull(SsoAuthCode::where('code_hash', hash('sha256', $code))->value('used_at'));
    }

    /**
     * Test 2: Redirect URI validation uses exact match against pre-registered allowlist.
     */
    public function test_exact_redirect_uri_allowlist_validation(): void
    {
        $this->actingAs($this->user, 'web');
        [, $challenge] = $this->generatePkce();

        // Attempt with unauthorized path on same domain
        $response = $this->postJson('/api/v1/sso/authorize', [
            'project_id' => $this->hrms->id,
            'redirect_uri' => 'http://localhost:8001/sso/unauthorized-endpoint',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['redirect_uri']);

        // Attempt with attacker URL
        $response2 = $this->postJson('/api/v1/sso/authorize', [
            'project_id' => $this->hrms->id,
            'redirect_uri' => 'https://attacker.com/steal-code',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);

        $response2->assertStatus(422)
            ->assertJsonValidationErrors(['redirect_uri']);
    }

    /**
     * Test 3: Reject any code_challenge_method other than S256.
     */
    public function test_code_challenge_method_rejects_anything_other_than_s256(): void
    {
        $this->actingAs($this->user, 'web');
        [, $challenge] = $this->generatePkce();

        // Attempt with 'plain'
        $response = $this->postJson('/api/v1/sso/authorize', [
            'project_id' => $this->hrms->id,
            'redirect_uri' => $this->validRedirectUri,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'plain', // INVALID: Only S256 allowed!
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['code_challenge_method']);
    }

    /**
     * Test 4: Rate limiting and lockout on /sso/token client authentication failures (5 attempts / 15 mins).
     */
    public function test_sso_token_rate_limiting_and_lockout_on_auth_failures(): void
    {
        RateLimiter::clear('sso_token_lockout:ip:hrms_client_app:127.0.0.1');
        RateLimiter::clear('sso_token_lockout:client:hrms_client_app');
        [, $challenge] = $this->generatePkce();

        // 5 consecutive failed client secret attempts
        for ($i = 0; $i < 5; $i++) {
            $resp = $this->postJson('/api/v1/sso/token', [
                'code' => 'dummy_code',
                'code_verifier' => 'dummy_verifier_12345678901234567890123456789012345',
                'client_id' => 'hrms_client_app',
                'client_secret' => 'WRONG_SECRET',
            ]);
            $resp->assertStatus(401);
        }

        // 6th attempt is locked out with HTTP 429 Too Many Requests
        $lockedOutResponse = $this->postJson('/api/v1/sso/token', [
            'code' => 'dummy_code',
            'code_verifier' => 'dummy_verifier_12345678901234567890123456789012345',
            'client_id' => 'hrms_client_app',
            'client_secret' => $this->rawClientSecret,
        ]);

        $lockedOutResponse->assertStatus(429)
            ->assertSee('Locked out');
    }

    /**
     * Test 4b: Distributed brute-force attack across rotating IPs locks out client_id globally.
     */
    public function test_distributed_client_lockout_across_ip_rotation(): void
    {
        RateLimiter::clear('sso_token_lockout:client:hrms_client_app');

        // 10 failed attempts across 10 distinct rotating IP addresses (1 failure per IP)
        for ($i = 1; $i <= 10; $i++) {
            $ip = "192.168.1.{$i}";
            RateLimiter::clear("sso_token_lockout:ip:hrms_client_app:{$ip}");

            $resp = $this->withServerVariables(['REMOTE_ADDR' => $ip])
                ->postJson('/api/v1/sso/token', [
                    'code' => 'dummy_code',
                    'code_verifier' => 'dummy_verifier_12345678901234567890123456789012345',
                    'client_id' => 'hrms_client_app',
                    'client_secret' => 'WRONG_SECRET',
                ]);
            $resp->assertStatus(401);
        }

        // 11th attempt from a brand-new 11th IP address is blocked by the global client_id lockout
        $blockedResp = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.99'])
            ->postJson('/api/v1/sso/token', [
                'code' => 'dummy_code',
                'code_verifier' => 'dummy_verifier_12345678901234567890123456789012345',
                'client_id' => 'hrms_client_app',
                'client_secret' => $this->rawClientSecret,
            ]);

        $blockedResp->assertStatus(429)
            ->assertSee('Locked out');
    }

    /**
     * Test 5: /sso/logout-telemetry requires authenticated project credentials.
     */
    public function test_logout_telemetry_requires_authenticated_project_credentials(): void
    {
        // Unauthenticated attempt
        $badResp = $this->postJson('/api/v1/sso/logout-telemetry', [
            'client_id' => 'hrms_client_app',
            'client_secret' => 'INVALID_SECRET',
            'external_ref' => 'EMP8001',
        ]);
        $badResp->assertStatus(401);

        // Authenticated attempt
        $goodResp = $this->postJson('/api/v1/sso/logout-telemetry', [
            'client_id' => 'hrms_client_app',
            'client_secret' => $this->rawClientSecret,
            'external_ref' => 'EMP8001',
            'session_id' => 'sess_hrms_abc123',
        ]);

        $goodResp->assertStatus(200)
            ->assertJson([
                'status' => 'acknowledged',
            ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'SSO_LOGOUT_TELEMETRY',
            'entity_type' => 'ProjectSession',
            'entity_id' => 'EMP8001',
        ]);
    }

    /**
     * Test 6: /sso/token re-validates user_project_access.status = ACTIVE at redemption time.
     */
    public function test_token_redemption_revalidates_active_status_closing_race_window(): void
    {
        $this->actingAs($this->user, 'web');
        [$verifier, $challenge] = $this->generatePkce();

        // Issue valid code
        $authResponse = $this->postJson('/api/v1/sso/authorize', [
            'project_id' => $this->hrms->id,
            'redirect_uri' => $this->validRedirectUri,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);
        $code = $authResponse->json('data.authorization_code');

        // Admin revokes access during the 60-second window before redemption
        UserProjectAccess::where('user_id', $this->user->id)
            ->where('project_id', $this->hrms->id)
            ->update(['status' => 'REVOKED']);

        // Downstream project attempts token redemption
        $tokenResponse = $this->postJson('/api/v1/sso/token', [
            'code' => $code,
            'code_verifier' => $verifier,
            'client_id' => 'hrms_client_app',
            'client_secret' => $this->rawClientSecret,
        ]);

        $tokenResponse->assertStatus(403)
            ->assertSee('no longer ACTIVE');

        // Code is burned and cannot be retried
        $this->assertNotNull(SsoAuthCode::where('code_hash', hash('sha256', $code))->value('used_at'));
    }

    /**
     * Test 7: Authorization code is single-use and cannot be redeemed twice.
     */
    public function test_authorization_code_is_single_use(): void
    {
        $this->actingAs($this->user, 'web');
        [$verifier, $challenge] = $this->generatePkce();

        $authResponse = $this->postJson('/api/v1/sso/authorize', [
            'project_id' => $this->hrms->id,
            'redirect_uri' => $this->validRedirectUri,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);
        $code = $authResponse->json('data.authorization_code');

        // 1st redemption succeeds
        $this->postJson('/api/v1/sso/token', [
            'code' => $code,
            'code_verifier' => $verifier,
            'client_id' => 'hrms_client_app',
            'client_secret' => $this->rawClientSecret,
        ])->assertStatus(200);

        // 2nd redemption fails
        $replayResponse = $this->postJson('/api/v1/sso/token', [
            'code' => $code,
            'code_verifier' => $verifier,
            'client_id' => 'hrms_client_app',
            'client_secret' => $this->rawClientSecret,
        ]);

        $replayResponse->assertStatus(400)
            ->assertSee('already been redeemed');
    }

    /**
     * Test 8: Authorization code expires after 60 seconds.
     */
    public function test_authorization_code_expires_after_60_seconds(): void
    {
        $this->actingAs($this->user, 'web');
        [$verifier, $challenge] = $this->generatePkce();

        $authResponse = $this->postJson('/api/v1/sso/authorize', [
            'project_id' => $this->hrms->id,
            'redirect_uri' => $this->validRedirectUri,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);
        $code = $authResponse->json('data.authorization_code');

        // Travel 65 seconds into the future
        $this->travel(65)->seconds();

        $tokenResponse = $this->postJson('/api/v1/sso/token', [
            'code' => $code,
            'code_verifier' => $verifier,
            'client_id' => 'hrms_client_app',
            'client_secret' => $this->rawClientSecret,
        ]);

        $tokenResponse->assertStatus(400)
            ->assertSee('expired');
    }

    /**
     * Test 9: PKCE verifier mismatch is rejected.
     */
    public function test_pkce_verifier_mismatch_rejected(): void
    {
        $this->actingAs($this->user, 'web');
        [, $challenge] = $this->generatePkce();

        $authResponse = $this->postJson('/api/v1/sso/authorize', [
            'project_id' => $this->hrms->id,
            'redirect_uri' => $this->validRedirectUri,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);
        $code = $authResponse->json('data.authorization_code');

        $tokenResponse = $this->postJson('/api/v1/sso/token', [
            'code' => $code,
            'code_verifier' => 'WRONG_VERIFIER_STRING_123456789012345678901234567890',
            'client_id' => 'hrms_client_app',
            'client_secret' => $this->rawClientSecret,
        ]);

        $tokenResponse->assertStatus(400)
            ->assertSee('PKCE');
    }

    /**
     * Test 10: User cannot initiate SSO for a project where access is REVOKED or not active.
     */
    public function test_user_cannot_initiate_sso_for_non_active_project_access(): void
    {
        $this->actingAs($this->user, 'web');
        [, $challenge] = $this->generatePkce();

        // Update access to REVOKED
        UserProjectAccess::where('user_id', $this->user->id)
            ->where('project_id', $this->hrms->id)
            ->update(['status' => 'REVOKED']);

        $response = $this->postJson('/api/v1/sso/authorize', [
            'project_id' => $this->hrms->id,
            'redirect_uri' => $this->validRedirectUri,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);

        $response->assertStatus(403)
            ->assertSee('Access denied');
    }

    /**
     * Test 11: Browser GET /sso/authorize responds with HTTP 302 redirect and preserves state.
     */
    public function test_sso_browser_get_request_responds_with_http_302_redirect_and_preserves_state(): void
    {
        $this->actingAs($this->user, 'web');
        [$verifier, $challenge] = $this->generatePkce();

        $response = $this->get('/api/v1/sso/authorize?' . http_build_query([
            'project_id' => $this->hrms->id,
            'redirect_uri' => $this->validRedirectUri,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'state' => 'xyz-oauth-csrf-state-12345',
        ]));

        $response->assertStatus(302);
        $redirectLocation = $response->headers->get('Location');
        $this->assertStringStartsWith($this->validRedirectUri, $redirectLocation);
        $this->assertStringContainsString('code=', $redirectLocation);
        $this->assertStringContainsString('state=xyz-oauth-csrf-state-12345', $redirectLocation);
        // Ensure code_verifier is NOT leaked in the redirect Location header
        $this->assertStringNotContainsString('code_verifier', $redirectLocation);
    }

    /**
     * Test 12: Browser GET /sso/authorize redirects with access_denied error when access is not ACTIVE.
     */
    public function test_sso_browser_get_request_redirects_with_access_denied_error_when_unauthorized(): void
    {
        $this->actingAs($this->user, 'web');
        [, $challenge] = $this->generatePkce();

        UserProjectAccess::where('user_id', $this->user->id)
            ->where('project_id', $this->hrms->id)
            ->update(['status' => 'REVOKED']);

        $response = $this->get('/api/v1/sso/authorize?' . http_build_query([
            'project_id' => $this->hrms->id,
            'redirect_uri' => $this->validRedirectUri,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'state' => 'test-error-state',
        ]));

        $response->assertStatus(302);
        $redirectLocation = $response->headers->get('Location');
        $this->assertStringStartsWith($this->validRedirectUri, $redirectLocation);
        $this->assertStringContainsString('error=access_denied', $redirectLocation);
        $this->assertStringContainsString('state=test-error-state', $redirectLocation);
    }
}
