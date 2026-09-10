<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Service providing high-fidelity compliance audit logging with automatic
 * request correlation, contextual actor binding, and recursive sensitive data masking.
 */
class AuditLoggerService
{
    /**
     * Keys subjected to automatic redaction.
     *
     * @var list<string>
     */
    protected static array $sensitiveKeys = [
        'password',
        'password_confirmation',
        'secret',
        'client_secret',
        'encrypted_client_secret',
        'remember_token',
        'token',
        'authorization',
        'api_key',
        'plain_text_key',
        'key_hash',
        'x-api-key',
    ];

    /**
     * Record an audit log entry with automatic metadata enrichment and sensitive data masking.
     *
     * @param string $action
     * @param string $entityType
     * @param string $entityId
     * @param array<string, mixed>|null $beforeData
     * @param array<string, mixed>|null $afterData
     * @param string $status
     * @param int|null $projectId
     * @param array<string, mixed>|null $metadata
     * @param int|null $actorUserId
     * @param Request|null $request
     * @return AuditLog
     */
    public function log(
        string $action,
        string $entityType,
        string $entityId,
        ?array $beforeData = null,
        ?array $afterData = null,
        string $status = 'SUCCESS',
        ?int $projectId = null,
        ?array $metadata = null,
        ?int $actorUserId = null,
        ?Request $request = null
    ): AuditLog {
        $req = $request ?? request();

        $actorId = $actorUserId ?? Auth::id();
        $ip = $req ? $req->ip() : '127.0.0.1';
        $userAgent = $req ? Str::limit($req->userAgent(), 255, '') : null;
        $requestId = $req ? ($req->header('X-Correlation-ID') ?? $req->header('X-Request-ID') ?? (string) Str::uuid()) : (string) Str::uuid();

        return AuditLog::create([
            'actor_user_id' => $actorId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'project_id' => $projectId,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'request_id' => $requestId,
            'before_data' => $beforeData ? $this->maskSensitiveFields($beforeData) : null,
            'after_data' => $afterData ? $this->maskSensitiveFields($afterData) : null,
            'status' => $status,
            'metadata' => $metadata ? $this->maskSensitiveFields($metadata) : null,
            'created_at' => now(),
        ]);
    }

    /**
     * Recursively mask sensitive keys in payload arrays with [REDACTED].
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function maskSensitiveFields(array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            $lowerKey = strtolower((string) $key);
            $isSensitive = false;

            foreach (self::$sensitiveKeys as $sensitive) {
                if (str_contains($lowerKey, $sensitive)) {
                    $isSensitive = true;
                    break;
                }
            }

            if ($isSensitive) {
                $sanitized[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->maskSensitiveFields($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }
}
