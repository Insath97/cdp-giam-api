<?php

namespace App\Services\Integration;

use App\Models\Project;
use App\Models\ProjectApiKey;
use DateTimeInterface;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Service managing the generation, validation, rotation, and revocation
 * of inbound Project API keys for project-to-GIAM resource consumption.
 */
class ProjectApiKeyService
{
    /**
     * Generate a new structured Project API key.
     *
     * Format: giam_{key_id}_{secret}
     * - Recognizable prefix: giam_
     * - Public key identifier: 16-character alphanumeric (for O(1) indexed lookup)
     * - High-entropy secret: 40-character cryptographically secure random string
     *
     * Only the HMAC-SHA-256 derived hash is persisted. The plaintext key is
     * returned exactly once upon issuance.
     *
     * @param Project $project
     * @param string $name
     * @param DateTimeInterface|null $expiresAt
     * @return array{api_key: ProjectApiKey, plain_text_key: string}
     */
    public function generateKey(Project $project, string $name, ?DateTimeInterface $expiresAt = null): array
    {
        // 16-character alphanumeric public identifier
        $keyId = Str::lower(Str::random(16));

        // High-entropy random secret (40 characters)
        $secret = Str::random(40);

        // Structured token: giam_<key_id>_<secret>
        $plainTextKey = "giam_{$keyId}_{$secret}";

        // Secure HMAC-SHA-256 hash using configured pepper or app key
        $keyHash = $this->hashSecret($secret);

        $apiKey = ProjectApiKey::create([
            'project_id' => $project->id,
            'name' => $name,
            'key_id' => $keyId,
            'key_hash' => $keyHash,
            'expires_at' => $expiresAt,
            'revoked_at' => null,
            'last_used_at' => null,
        ]);

        return [
            'api_key' => $apiKey,
            'plain_text_key' => $plainTextKey,
        ];
    }

    /**
     * Parse structured token into key_id and secret components.
     *
     * @param string $token
     * @return array{key_id: string, secret: string}|null
     */
    public function parseToken(string $token): ?array
    {
        $token = trim($token);

        if (!preg_match('/^giam_([a-zA-Z0-9]{12,32})_([a-zA-Z0-9]{32,64})$/', $token, $matches)) {
            return null;
        }

        return [
            'key_id' => $matches[1],
            'secret' => $matches[2],
        ];
    }

    /**
     * Validate an inbound plaintext API key against the database using indexed lookup
     * and constant-time HMAC comparison.
     *
     * @param string $token
     * @return ProjectApiKey|null
     */
    public function validateToken(string $token): ?ProjectApiKey
    {
        $parsed = $this->parseToken($token);

        if (!$parsed) {
            return null;
        }

        $apiKey = ProjectApiKey::with(['project.integration'])
            ->where('key_id', $parsed['key_id'])
            ->first();

        if (!$apiKey) {
            return null;
        }

        $expectedHash = $this->hashSecret($parsed['secret']);

        if (!hash_equals($apiKey->key_hash, $expectedHash)) {
            return null;
        }

        return $apiKey;
    }

    /**
     * Revoke an active API key immediately.
     *
     * @param ProjectApiKey $apiKey
     * @return ProjectApiKey
     */
    public function revokeKey(ProjectApiKey $apiKey): ProjectApiKey
    {
        $apiKey->update([
            'revoked_at' => now(),
        ]);

        return $apiKey;
    }

    /**
     * Compute HMAC-SHA-256 for a secret using the server-side pepper or APP_KEY.
     *
     * @param string $secret
     * @return string
     */
    public function hashSecret(string $secret): string
    {
        $pepper = config('services.project_api_keys.pepper') ?: config('app.key');

        if (empty($pepper)) {
            throw new InvalidArgumentException('Server-side encryption key or pepper is not configured.');
        }

        return hash_hmac('sha256', $secret, $pepper);
    }
}
