<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class GiamSecurityController extends Controller
{
    /**
     * GET /api/v1/giam-security/pin/status
     * Returns whether the authenticated user has a PIN set up.
     */
    public function status(): JsonResponse
    {
        $userId = Auth::id();
        $pinRecord = DB::table('giam_pins')->where('user_id', $userId)->first();

        return response()->json([
            'is_setup'     => (bool) $pinRecord,
            'is_locked'    => false,
            'locked_until' => null,
        ]);
    }

    /**
     * POST /api/v1/giam-security/pin/setup
     * Set up a 4-digit PIN for GIAM Internal access.
     */
    public function setup(Request $request): JsonResponse
    {
        $request->validate([
            'pin'              => 'required|digits:4',
            'pin_confirmation' => 'required|same:pin',
        ]);

        $userId = Auth::id();

        DB::table('giam_pins')->updateOrInsert(
            ['user_id' => $userId],
            [
                'pin_hash'   => Hash::make($request->pin),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        return response()->json([
            'status'    => 'success',
            'message'   => 'PIN set up successfully.',
            'pin_token' => base64_encode("giam-pin-{$userId}-" . now()->timestamp),
        ]);
    }

    /**
     * POST /api/v1/giam-security/pin/verify
     * Verify the user's GIAM PIN.
     */
    public function verify(Request $request): JsonResponse
    {
        $request->validate([
            'pin' => 'required|digits:4',
        ]);

        $userId = Auth::id();
        $pinRecord = DB::table('giam_pins')->where('user_id', $userId)->first();

        // If no PIN set up yet, auto-verify (first time access)
        if (!$pinRecord) {
            return response()->json([
                'status'    => 'success',
                'message'   => 'Verified.',
                'pin_token' => base64_encode("giam-pin-{$userId}-" . now()->timestamp),
            ]);
        }

        if (!Hash::check($request->pin, $pinRecord->pin_hash)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Incorrect PIN. Please try again.',
            ], 401);
        }

        return response()->json([
            'status'    => 'success',
            'message'   => 'PIN verified successfully.',
            'pin_token' => base64_encode("giam-pin-{$userId}-" . now()->timestamp),
        ]);
    }

    /**
     * POST /api/v1/giam-security/pin/reset/{userId}
     * Reset a user's PIN (Admin only).
     */
    public function reset(int $userId): JsonResponse
    {
        DB::table('giam_pins')->where('user_id', $userId)->delete();

        return response()->json([
            'status'  => 'success',
            'message' => 'PIN reset successfully.',
        ]);
    }
}
