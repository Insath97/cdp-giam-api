<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\StoreEmployeeRequest;
use App\Http\Requests\Employee\UpdateEmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use App\Services\Audit\AuditLoggerService;
use App\Services\User\UserCreationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EmployeeController extends Controller
{
    public function __construct(
        protected UserCreationService $userCreationService,
        protected AuditLoggerService $auditLogger
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Employee::with(['province', 'zone', 'region', 'branch', 'department', 'designation', 'user']);

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('employee_code', 'like', "%{$search}%")
                  ->orWhere('full_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('id_number', 'like', "%{$search}%");
            });
        }

        if ($department = $request->input('department_code')) {
            $query->where('department_code', $department);
        }

        if ($province = $request->input('province_code')) {
            $query->where('province_code', $province);
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $employees = $query->orderBy('created_at', 'desc')
                           ->paginate($request->integer('per_page', 15));

        return EmployeeResource::collection($employees);
    }

    public function store(StoreEmployeeRequest $request): JsonResponse
    {
        $employee = $this->userCreationService->createEmployee(
            $request->validated(),
            $request->user()
        );

        return (new EmployeeResource($employee))
            ->response()
            ->setStatusCode(201);
    }

    public function show(int|string $id): EmployeeResource
    {
        $employee = Employee::with(['province', 'zone', 'region', 'branch', 'department', 'designation', 'user', 'reportingManager'])
            ->where('id', $id)
            ->orWhere('employee_code', $id)
            ->firstOrFail();

        return new EmployeeResource($employee);
    }

    public function update(UpdateEmployeeRequest $request, int|string $id): EmployeeResource
    {
        $employee = Employee::where('id', $id)
            ->orWhere('employee_code', $id)
            ->firstOrFail();

        $updated = $this->userCreationService->updateEmployee(
            $employee,
            $request->validated(),
            $request->user()
        );

        return new EmployeeResource($updated);
    }

    public function destroy(Request $request, int|string $id): JsonResponse
    {
        $employee = Employee::where('id', $id)
            ->orWhere('employee_code', $id)
            ->firstOrFail();

        $beforeData = $employee->toArray();
        $employee->update(['is_active' => false]);
        $employee->delete();

        // Also deactivate linked user if present
        if ($employee->user) {
            $employee->user->update(['is_active' => false, 'can_login' => false]);
        }

        $this->auditLogger->log(
            action: 'EMPLOYEE_DEACTIVATED',
            entityType: 'Employee',
            entityId: $employee->employee_code,
            beforeData: $beforeData,
            afterData: null,
            status: 'SUCCESS',
            actorUserId: $request->user()?->id
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Employee deactivated successfully.',
        ]);
    }
}
