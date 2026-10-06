<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Draft\SaveDraftRequest;
use App\Services\User\DraftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DraftController extends Controller
{
    public function __construct(
        protected DraftService $draftService
    ) {}

    public function store(SaveDraftRequest $request): JsonResponse
    {
        $draft = $this->draftService->saveDraft(
            creator: $request->user(),
            formData: $request->input('form_data'),
            step: $request->integer('current_step'),
            draftToken: $request->input('draft_token')
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Draft saved successfully.',
            'data' => [
                'draft_token' => $draft->draft_token,
                'current_step' => $draft->current_step,
                'form_data' => $draft->form_data,
                'expires_at' => $draft->expires_at->toIso8601String(),
            ],
        ], 201);
    }

    public function show(Request $request, string $token): JsonResponse
    {
        $draft = $this->draftService->getDraft($token, $request->user());

        if (! $draft) {
            return response()->json([
                'status' => 'error',
                'code' => 'NOT_FOUND',
                'message' => 'Draft not found or has expired.',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'draft_token' => $draft->draft_token,
                'current_step' => $draft->current_step,
                'form_data' => $draft->form_data,
                'expires_at' => $draft->expires_at->toIso8601String(),
            ],
        ]);
    }

    public function destroy(Request $request, string $token): JsonResponse
    {
        $deleted = $this->draftService->deleteDraft($token, $request->user());

        return response()->json([
            'status' => $deleted ? 'success' : 'error',
            'message' => $deleted ? 'Draft discarded successfully.' : 'Draft not found.',
        ], $deleted ? 200 : 404);
    }
}
