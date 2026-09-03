<?php

namespace App\Services\Sync;

use App\Models\SyncJob;
use App\Models\UserProjectAccess;
use App\Services\Audit\AuditLoggerService;
use App\Services\Integration\ProjectClientFactory;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProvisioningSyncWorker
{
    public function __construct(
        protected ProjectClientFactory $clientFactory,
        protected AuditLoggerService $auditLogger
    ) {}

    public function process(SyncJob $syncJob): bool
    {
        $project = $syncJob->project;
        $user = $syncJob->user;
        $integration = $project?->integration;

        if (! $project || ! $integration) {
            $syncJob->update([
                'status' => 'FAILED',
                'last_error' => 'Missing project or integration configuration.',
                'processed_at' => now(),
            ]);

            return false;
        }

        // Prevent concurrent processing race conditions via atomic transition
        $affected = SyncJob::where('id', $syncJob->id)
            ->whereIn('status', ['PENDING', 'RETRYING'])
            ->update([
                'status' => 'PROCESSING',
                'attempt_count' => DB::raw('attempt_count + 1'),
            ]);

        if ($affected === 0) {
            // Already claimed or being processed by another worker
            return false;
        }

        $syncJob->refresh();
        $newAttempt = $syncJob->attempt_count;

        $externalRef = $syncJob->payload['giam_user_id'] ?? $syncJob->payload['externalRef'] ?? $user?->id ?? $user?->employee_code ?? 'unknown';

        // Resolve endpoint URI
        $hasNamespace = str_ends_with(rtrim($integration->api_base_url, '/'), '/api/giam/integration');
        $prefix = $hasNamespace ? '' : '/api/giam/integration';

        $endpoint = match ($syncJob->operation) {
            'CREATE_USER' => "{$prefix}/users",
            'UPDATE_USER' => "{$prefix}/users/{$externalRef}",
            'ASSIGN_ACCESS' => "{$prefix}/users/{$externalRef}/access",
            'REVOKE_ACCESS' => "{$prefix}/users/{$externalRef}",
            default => "{$prefix}/users/{$externalRef}",
        };

        // Attach idempotency key to payload for downstream deduplication
        $outboundPayload = array_merge($syncJob->payload, [
            'idempotencyKey' => $syncJob->idempotency_key,
        ]);

        $startTime = microtime(true);
        $statusCode = null;
        $responseBody = null;
        $errorMessage = null;

        try {
            $client = $this->clientFactory->make($project, timeoutSeconds: 10)
                ->withHeaders([
                    'X-GIAM-Idempotency-Key' => $syncJob->idempotency_key,
                ]);

            $response = match ($syncJob->operation) {
                'CREATE_USER' => $client->post($endpoint, $outboundPayload),
                'UPDATE_USER' => $client->put($endpoint, $outboundPayload),
                'ASSIGN_ACCESS' => $client->patch($endpoint, $outboundPayload),
                'REVOKE_ACCESS' => $client->delete($endpoint, $outboundPayload),
                default => throw new Exception("Unsupported operation [{$syncJob->operation}]"),
            };

            $statusCode = $response->status();
            $responseBody = Str::limit($response->body(), 2000, '');

            if ($response->successful()) {
                $syncJob->update([
                    'status' => 'SUCCESS',
                    'http_status_code' => $statusCode,
                    'response_body' => $responseBody,
                    'last_error' => null,
                    'next_retry_at' => null,
                    'processed_at' => now(),
                ]);

                // Update business state in user_project_access
                $access = UserProjectAccess::where('user_id', $syncJob->user_id)
                    ->where('project_id', $syncJob->project_id)
                    ->first();

                if ($access) {
                    if (in_array($syncJob->operation, ['CREATE_USER', 'ASSIGN_ACCESS'])) {
                        $access->status = 'ACTIVE';
                    } elseif ($syncJob->operation === 'REVOKE_ACCESS') {
                        $access->status = 'REVOKED';
                    }
                    $access->save();
                }

                // Securely push bcrypt verifier to dedicated credential endpoint upon CREATE_USER or ASSIGN_ACCESS
                if (in_array($syncJob->operation, ['CREATE_USER', 'ASSIGN_ACCESS']) && $user && ! empty($user->password)) {
                    try {
                        $client->put("{$prefix}/users/{$user->id}/credential", [
                            'password_verifier' => $user->password,
                        ]);
                    } catch (\Exception $e) {
                        \Illuminate\Support\Facades\Log::warning("Initial credential push deferred for user {$user->id}: " . $e->getMessage());
                    }
                }

                $this->auditLogger->log(
                    action: 'PROVISIONING_SYNC_SUCCESS',
                    entityType: 'SyncJob',
                    entityId: (string) $syncJob->id,
                    beforeData: null,
                    afterData: [
                        'operation' => $syncJob->operation,
                        'http_status' => $statusCode,
                        'idempotency_key' => $syncJob->idempotency_key,
                        'latency_ms' => round((microtime(true) - $startTime) * 1000),
                    ],
                    status: 'SUCCESS',
                    projectId: $project->id,
                    actorUserId: null
                );

                return true;
            }

            $errorMessage = "Downstream API responded with HTTP {$statusCode}: {$responseBody}";
        } catch (Exception $e) {
            $errorMessage = $e->getMessage();
        }

        // Handle Failure & Stated Exponential Backoff: 1m, 5m, 15m, 1h, then FAILED
        $isMaxAttempts = ($newAttempt >= $syncJob->max_attempts);
        $nextStatus = $isMaxAttempts ? 'FAILED' : 'RETRYING';
        $nextRetryAt = null;

        if (! $isMaxAttempts) {
            $delays = [
                1 => 60,    // 1m
                2 => 300,   // 5m
                3 => 900,   // 15m
                4 => 3600,  // 1h
            ];
            $delaySeconds = $delays[$newAttempt] ?? 3600;
            $nextRetryAt = now()->addSeconds($delaySeconds);
        }

        $syncJob->update([
            'status' => $nextStatus,
            'http_status_code' => $statusCode,
            'response_body' => $responseBody,
            'last_error' => $errorMessage,
            'next_retry_at' => $nextRetryAt,
            'processed_at' => $isMaxAttempts ? now() : null,
        ]);

        $this->auditLogger->log(
            action: $isMaxAttempts ? 'PROVISIONING_SYNC_PERMANENTLY_FAILED' : 'PROVISIONING_SYNC_FAILED',
            entityType: 'SyncJob',
            entityId: (string) $syncJob->id,
            beforeData: null,
            afterData: [
                'operation' => $syncJob->operation,
                'attempt' => $newAttempt,
                'max_attempts' => $syncJob->max_attempts,
                'status' => $nextStatus,
                'http_status' => $statusCode,
                'idempotency_key' => $syncJob->idempotency_key,
                'next_retry_at' => $nextRetryAt?->toIso8601String(),
            ],
            status: 'FAILED',
            projectId: $project->id,
            metadata: ['error' => $errorMessage]
        );

        return false;
    }
}
