<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Requests\User\UpdateUserStatusRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Audit\AuditLoggerService;
use App\Services\User\UserCreationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UserController extends Controller
{
    public function __construct(
        protected UserCreationService $userCreationService,
        protected AuditLoggerService $auditLogger
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = User::with(['employee', 'roles', 'permissions']);

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('username', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('employee_code', 'like', "%{$search}%");
            });
        }

        if ($userType = $request->input('user_type')) {
            $query->where('user_type', $userType);
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->has('can_login')) {
            $query->where('can_login', $request->boolean('can_login'));
        }

        $users = $query->orderBy('created_at', 'desc')
                       ->paginate($request->integer('per_page', 15));

        return UserResource::collection($users);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = $this->userCreationService->createUser(
            $request->validated(),
            $request->user()
        );

        return (new UserResource($user))
            ->response()
            ->setStatusCode(201);
    }

    public function show(int|string $id): UserResource
    {
        $user = User::with(['employee', 'roles', 'permissions'])
            ->where('id', $id)
            ->orWhere('username', $id)
            ->firstOrFail();

        return new UserResource($user);
    }

    public function update(UpdateUserRequest $request, int|string $id): UserResource
    {
        $user = User::where('id', $id)
            ->orWhere('username', $id)
            ->firstOrFail();

        $updated = $this->userCreationService->updateUser(
            $user,
            $request->validated(),
            $request->user()
        );

        return new UserResource($updated);
    }

    public function updateStatus(UpdateUserStatusRequest $request, int|string $id): UserResource
    {
        $user = User::where('id', $id)
            ->orWhere('username', $id)
            ->firstOrFail();

        $updated = $this->userCreationService->updateUserStatus(
            $user,
            $request->validated(),
            $request->user()
        );

        return new UserResource($updated);
    }

    public function destroy(Request $request, int|string $id): JsonResponse
    {
        $user = User::where('id', $id)
            ->orWhere('username', $id)
            ->firstOrFail();

        $beforeData = $user->toArray();
        $user->update(['is_active' => false, 'can_login' => false]);
        $user->delete();

        $this->auditLogger->log(
            action: 'USER_DEACTIVATED',
            entityType: 'User',
            entityId: (string) $user->id,
            beforeData: $beforeData,
            afterData: null,
            status: 'SUCCESS',
            actorUserId: $request->user()?->id
        );

        return response()->json([
            'status' => 'success',
            'message' => 'User deactivated successfully.',
        ]);
    }

    public function resendCredentials(Request $request, int|string $id): JsonResponse
    {
        $user = User::where('id', $id)
            ->orWhere('username', $id)
            ->firstOrFail();

        $result = $this->userCreationService->resendCredentials($user, $request->user());

        return response()->json($result);
    }
}
