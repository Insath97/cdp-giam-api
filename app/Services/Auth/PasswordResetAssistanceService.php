<?php

namespace App\Services\Auth;

use App\Mail\PasswordResetAssistanceMail;
use App\Models\PasswordResetRequest;
use App\Models\User;
use App\Services\Audit\AuditLoggerService;
use App\Services\Sync\DataProjectionService;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Service managing administrator-assisted password reset workflows, concurrency row locks,
 * status transitions, and transient credential generation.
 */
class PasswordResetAssistanceService
{
    public function __construct(
        protected PasswordSecurityService $securityService,
        protected AuditLoggerService $auditLogger,
        protected DataProjectionService $projectionService
    ) {}

    /**
     * Public submission of password reset assistance request.
     * Guaranteed enumeration-safe with transactional concurrency locking.
     *
     * @param string $identifier
     * @param string|null $ip
     * @return array<string, string>
     */
    public function submitRequest(string $identifier, ?string $ip = null): array
    {
        DB::transaction(function () use ($identifier, $ip) {
            $user = User::where('username', $identifier)
                ->orWhere('email', $identifier)
                ->first();

            if (! $user || ! $user->is_active || ! $user->can_login) {
                return;
            }

            // Lock user row to prevent concurrent race conditions
            User::where('id', $user->id)->lockForUpdate()->first();

            // Check if there is already a PENDING assistance request
            $hasPending = PasswordResetRequest::where('user_id', $user->id)
                ->where('status', 'PENDING')
                ->lockForUpdate()
                ->exists();

            if (! $hasPending) {
                PasswordResetRequest::create([
                    'user_id' => $user->id,
                    'status' => 'PENDING',
                    'requested_at' => now(),
                    'ip_address' => $ip,
                ]);

                $this->auditLogger->log(
                    action: 'PASSWORD_RESET_ASSISTANCE_REQUESTED',
                    entityType: 'PasswordResetRequest',
                    entityId: (string) $user->id,
                    actorUserId: null,
                    metadata: ['ip' => $ip]
                );
            }
        });

        // Always return enumeration-safe response
        return [
            'status' => 'success',
            'message' => 'If an eligible account matches that identifier, your assistance request has been submitted for administrator review.',
        ];
    }

    /**
     * Authoritative admin approval of assistance request.
     * Uses DB::transaction, lockForUpdate(), re-checks PENDING, updates password hash,
     * sets must_change_password = true, preserves lifetime self_service_reset_count.
     *
     * @param int $requestId
     * @param User $actor
     * @return PasswordResetRequest
     * @throws HttpException
     */
    public function approveRequest(int $requestId, User $actor): PasswordResetRequest
    {
        $tempPassword = null;
        $targetUser = null;
        $requestModel = null;

        DB::transaction(function () use ($requestId, $actor, &$tempPassword, &$targetUser, &$requestModel) {
            $requestModel = PasswordResetRequest::where('id', $requestId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($requestModel->status !== 'PENDING') {
                throw new HttpException(409, "Invalid state transition: Assistance request is already {$requestModel->status}.");
            }

            $targetUser = User::where('id', $requestModel->user_id)
                ->lockForUpdate()
                ->firstOrFail();

            // Generate temporary password satisfying policy
            $tempPassword = $this->securityService->generateTemporaryPassword();

            // Update user password hash, set must_change_password = true, preserve self_service_reset_count
            $targetUser->password = Hash::make($tempPassword);
            $targetUser->must_change_password = true;
            $targetUser->password_changed_at = now();
            $targetUser->failed_login_attempts = 0;
            $targetUser->lockout_until = null;
            $targetUser->save();

            // Update request status to APPROVED
            $requestModel->status = 'APPROVED';
            $requestModel->reviewed_by_user_id = $actor->id;
            $requestModel->reviewed_at = now();
            $requestModel->save();

            $this->auditLogger->log(
                action: 'PASSWORD_RESET_ASSISTANCE_APPROVED',
                entityType: 'PasswordResetRequest',
                entityId: (string) $requestModel->id,
                actorUserId: $actor->id,
                metadata: [
                    'target_user_id' => $targetUser->id,
                    'target_username' => $targetUser->username,
                ]
            );
        });

        // AFTER COMMIT: External side-effects outside DB transaction boundary
        // 1. Dispatch credential verifier (bcrypt hash) sync to active projects
        $this->projectionService->syncCredentialChange($targetUser, $targetUser->password);

        // 2. Dispatch email (synchronous/in-memory only, NO queue/jobs table persistence of plaintext)
        $this->sendAssistanceEmailSafe($targetUser, $tempPassword);

        return $requestModel->fresh(['user', 'reviewer']);
    }

    /**
     * Authoritative admin rejection of assistance request.
     *
     * @param int $requestId
     * @param string|null $notes
     * @param User $actor
     * @return PasswordResetRequest
     * @throws HttpException
     */
    public function rejectRequest(int $requestId, ?string $notes, User $actor): PasswordResetRequest
    {
        return DB::transaction(function () use ($requestId, $notes, $actor) {
            $requestModel = PasswordResetRequest::where('id', $requestId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($requestModel->status !== 'PENDING') {
                throw new HttpException(409, "Invalid state transition: Assistance request is already {$requestModel->status}.");
            }

            $requestModel->status = 'REJECTED';
            $requestModel->resolution_notes = $notes;
            $requestModel->reviewed_by_user_id = $actor->id;
            $requestModel->reviewed_at = now();
            $requestModel->save();

            $this->auditLogger->log(
                action: 'PASSWORD_RESET_ASSISTANCE_REJECTED',
                entityType: 'PasswordResetRequest',
                entityId: (string) $requestModel->id,
                actorUserId: $actor->id,
                metadata: [
                    'target_user_id' => $requestModel->user_id,
                    'notes' => $notes,
                ]
            );

            return $requestModel->fresh(['user', 'reviewer']);
        });
    }

    /**
     * Safely deliver assistance email without logging plaintext password.
     */
    protected function sendAssistanceEmailSafe(User $user, string $tempPassword): void
    {
        $frontendUrl = config('app.frontend_url') ?? env('FRONTEND_URL', 'http://localhost:3000');
        $loginUrl = "{$frontendUrl}/login";

        try {
            if (config('mail.default') === 'smtp' && empty(config('mail.mailers.smtp.username'))) {
                // Real Outlook delivery blocked by configuration
                return;
            }

            Mail::to($user->email)->send(new PasswordResetAssistanceMail($user, $tempPassword, $loginUrl));
        } catch (Exception $e) {
            // Do not log temporary password on mail failure
        }
    }
}
