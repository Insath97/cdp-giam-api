<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PasswordResetRequest;
use App\Services\Auth\PasswordResetAssistanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PasswordResetAssistanceAdminController extends Controller
{
    public function __construct(
        protected PasswordResetAssistanceService $assistanceService
    ) {}

    /**
     * List password reset requests.
     * Gated by USER_VIEW.
     */
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');
        $query = PasswordResetRequest::with(['user.employee', 'reviewer']);

        if ($status && in_array($status, ['PENDING', 'APPROVED', 'REJECTED'])) {
            $query->where('status', $status);
        }

        $requests = $query->orderByDesc('requested_at')->paginate(15);

        return response()->json($requests);
    }

    /**
     * Approve assistance request.
     * Gated by USER_UPDATE.
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        $updated = $this->assistanceService->approveRequest($id, $request->user());

        return response()->json([
            'status' => 'success',
            'message' => 'Password reset assistance approved and temporary credentials generated.',
            'data' => $updated,
        ]);
    }

    /**
     * Reject assistance request.
     * Gated by USER_UPDATE.
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        $notes = $request->input('resolution_notes');
        $updated = $this->assistanceService->rejectRequest($id, $notes, $request->user());

        return response()->json([
            'status' => 'success',
            'message' => 'Password reset assistance request rejected.',
            'data' => $updated,
        ]);
    }
}
