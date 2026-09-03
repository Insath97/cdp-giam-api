<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OptimisticLockException extends Exception
{
    public function __construct(string $message = 'Record has been modified by another process. Please refresh and try again.')
    {
        parent::__construct($message, 409);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'error' => 'CONFLICT',
            'message' => $this->getMessage(),
        ], 409);
    }
}
