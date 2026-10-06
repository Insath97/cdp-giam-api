<?php

namespace App\Http\Controllers\Api\Integrations;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectEmployeeResource;
use App\Models\Employee;
use App\Models\OrgBranch;
use App\Models\OrgDepartment;
use App\Models\OrgDesignation;
use App\Models\OrgProvince;
use App\Models\OrgRegion;
use App\Models\OrgZone;
use App\Models\Project;
use App\Models\ProjectApiKey;
use App\Services\Audit\AuditLoggerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProjectResourceController extends Controller
{
    public function __construct(
        protected AuditLoggerService $auditLogger
    ) {}

    /**
     * Paginated Employee collection for authorized project consumption.
     *
     * Applies conditional eager loading: only relationships explicitly requested
     * in the project's allowed_resource_fields are loaded from the database.
     *
     * @param Request $request
     * @return AnonymousResourceCollection
     */
    public function employees(Request $request): AnonymousResourceCollection
    {
        /** @var Project $project */
        $project = $request->attributes->get('authenticated_project');
        /** @var ProjectApiKey|null $apiKey */
        $apiKey = $request->attributes->get('authenticated_api_key');

        // Bounded pagination: min 1, max 100, default 25
        $perPage = (int) $request->input('per_page', 25);
        $perPage = max(1, min(100, $perPage));

        $allowedFields = $project?->integration?->allowed_resource_fields['employees'] ?? [];
        $relationsToLoad = $this->resolveEmployeeRelationsToLoad(is_array($allowedFields) ? $allowedFields : []);

        $query = Employee::query();

        // Performance: Eager-load ONLY the organizational relationships needed
        if (!empty($relationsToLoad)) {
            $query->with($relationsToLoad);
        }

        // Safe query filters
        if ($request->filled('department_code')) {
            $query->where('department_code', (string) $request->input('department_code'));
        }

        if ($request->filled('designation_code')) {
            $query->where('designation_code', (string) $request->input('designation_code'));
        }

        if ($request->filled('branch_code')) {
            $query->where('branch_code', (string) $request->input('branch_code'));
        }

        if ($request->has('is_active')) {
            $isActive = filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($isActive !== null) {
                $query->where('is_active', $isActive);
            }
        }

        if ($request->filled('search')) {
            $searchTerm = '%' . addcslashes((string) $request->input('search'), '%_') . '%';
            $query->where(function ($sub) use ($searchTerm) {
                $sub->where('employee_code', 'LIKE', $searchTerm)
                    ->orWhere('full_name', 'LIKE', $searchTerm)
                    ->orWhere('email', 'LIKE', $searchTerm);
            });
        }

        $paginated = $query->orderBy('employee_code', 'asc')->paginate($perPage);

        // Compliance audit without sensitive credential or PII leakage
        $this->auditLogger->log(
            action: 'PROJECT_RESOURCE_ACCESSED',
            entityType: 'Employee',
            entityId: 'COLLECTION',
            beforeData: null,
            afterData: null,
            status: 'SUCCESS',
            projectId: $project->id,
            metadata: [
                'project_code' => $project->code,
                'key_id' => $apiKey?->key_id,
                'resource' => 'employees',
                'scope' => 'employees:read',
                'returned_count' => $paginated->count(),
                'total_count' => $paginated->total(),
                'page' => $paginated->currentPage(),
                'per_page' => $perPage,
                'eager_loaded' => $relationsToLoad,
            ],
            actorUserId: null,
            request: $request
        );

        return ProjectEmployeeResource::collection($paginated);
    }

    /**
     * Retrieve a single Employee record by canonical employee_code.
     *
     * @param Request $request
     * @param string $employeeCode
     * @return ProjectEmployeeResource|JsonResponse
     */
    public function showEmployee(Request $request, string $employeeCode): ProjectEmployeeResource|JsonResponse
    {
        /** @var Project $project */
        $project = $request->attributes->get('authenticated_project');
        /** @var ProjectApiKey|null $apiKey */
        $apiKey = $request->attributes->get('authenticated_api_key');

        $allowedFields = $project?->integration?->allowed_resource_fields['employees'] ?? [];
        $relationsToLoad = $this->resolveEmployeeRelationsToLoad(is_array($allowedFields) ? $allowedFields : []);

        $query = Employee::query()->where('employee_code', $employeeCode);

        if (!empty($relationsToLoad)) {
            $query->with($relationsToLoad);
        }

        $employee = $query->first();

        if (!$employee) {
            return response()->json([
                'error' => 'Not Found',
                'message' => "Employee with code '{$employeeCode}' not found.",
            ], 404);
        }

        // Compliance audit
        $this->auditLogger->log(
            action: 'PROJECT_RESOURCE_ACCESSED',
            entityType: 'Employee',
            entityId: $employeeCode,
            beforeData: null,
            afterData: null,
            status: 'SUCCESS',
            projectId: $project->id,
            metadata: [
                'project_code' => $project->code,
                'key_id' => $apiKey?->key_id,
                'resource' => 'employees',
                'scope' => 'employees:read',
                'employee_code' => $employeeCode,
                'eager_loaded' => $relationsToLoad,
            ],
            actorUserId: null,
            request: $request
        );

        return new ProjectEmployeeResource($employee);
    }

    /**
     * Departments reference resource endpoint.
     */
    public function departments(Request $request): JsonResponse
    {
        return $this->handleReferenceResource(
            request: $request,
            resourceKey: 'departments',
            modelClass: OrgDepartment::class,
            scope: 'departments:read'
        );
    }

    /**
     * Designations reference resource endpoint.
     */
    public function designations(Request $request): JsonResponse
    {
        return $this->handleReferenceResource(
            request: $request,
            resourceKey: 'designations',
            modelClass: OrgDesignation::class,
            scope: 'designations:read'
        );
    }

    /**
     * Branches reference resource endpoint.
     */
    public function branches(Request $request): JsonResponse
    {
        return $this->handleReferenceResource(
            request: $request,
            resourceKey: 'branches',
            modelClass: OrgBranch::class,
            scope: 'branches:read'
        );
    }

    /**
     * Regions reference resource endpoint.
     */
    public function regions(Request $request): JsonResponse
    {
        return $this->handleReferenceResource(
            request: $request,
            resourceKey: 'regions',
            modelClass: OrgRegion::class,
            scope: 'regions:read'
        );
    }

    /**
     * Zones reference resource endpoint.
     */
    public function zones(Request $request): JsonResponse
    {
        return $this->handleReferenceResource(
            request: $request,
            resourceKey: 'zones',
            modelClass: OrgZone::class,
            scope: 'zones:read'
        );
    }

    /**
     * Provinces reference resource endpoint.
     */
    public function provinces(Request $request): JsonResponse
    {
        return $this->handleReferenceResource(
            request: $request,
            resourceKey: 'provinces',
            modelClass: OrgProvince::class,
            scope: 'provinces:read'
        );
    }

    /**
     * Generic, reusable reference resource handler for organizational master entities.
     *
     * @param Request $request
     * @param string $resourceKey
     * @param class-string $modelClass
     * @param string $scope
     * @return JsonResponse
     */
    protected function handleReferenceResource(
        Request $request,
        string $resourceKey,
        string $modelClass,
        string $scope
    ): JsonResponse {
        /** @var Project $project */
        $project = $request->attributes->get('authenticated_project');
        /** @var ProjectApiKey|null $apiKey */
        $apiKey = $request->attributes->get('authenticated_api_key');

        // Deny-by-default: Read project's allowed_resource_fields for this specific resource
        $allowedFields = $project?->integration?->allowed_resource_fields[$resourceKey] ?? null;

        if (!is_array($allowedFields) || empty($allowedFields)) {
            return response()->json([
                'data' => [],
            ]);
        }

        $query = $modelClass::query();

        // Safe filtering
        if ($request->has('is_active')) {
            $isActive = filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($isActive !== null) {
                $query->where('is_active', $isActive);
            }
        }

        $fillable = (new $modelClass)->getFillable();

        if ($request->filled('department_code') && in_array('department_code', $fillable, true)) {
            $query->where('department_code', (string) $request->input('department_code'));
        }
        if ($request->filled('region_code') && in_array('region_code', $fillable, true)) {
            $query->where('region_code', (string) $request->input('region_code'));
        }
        if ($request->filled('zonal_code') && in_array('zonal_code', $fillable, true)) {
            $query->where('zonal_code', (string) $request->input('zonal_code'));
        }
        if ($request->filled('province_code') && in_array('province_code', $fillable, true)) {
            $query->where('province_code', (string) $request->input('province_code'));
        }
        if ($request->filled('city') && in_array('city', $fillable, true)) {
            $query->where('city', (string) $request->input('city'));
        }

        if ($request->filled('search')) {
            $searchTerm = '%' . addcslashes((string) $request->input('search'), '%_') . '%';
            $query->where(function ($sub) use ($searchTerm) {
                $sub->where('code', 'LIKE', $searchTerm)
                    ->orWhere('name', 'LIKE', $searchTerm);
            });
        }

        $query->orderBy('code', 'asc');

        $isPaginated = $request->has('per_page') || $request->has('page');
        $perPage = max(1, min(100, (int) $request->input('per_page', 25)));

        if ($isPaginated) {
            $paginator = $query->paginate($perPage);
            $items = $paginator->items();
        } else {
            $items = $query->take(250)->get();
        }

        // Project fields (Deny-by-default)
        $projected = array_map(function ($item) use ($allowedFields) {
            $row = [];
            $rawAttributes = $item->getAttributes();
            foreach ($allowedFields as $field) {
                if (array_key_exists($field, $rawAttributes)) {
                    $row[$field] = $item->{$field};
                }
            }
            return $row;
        }, is_array($items) ? $items : $items->all());

        // Audit logging
        $this->auditLogger->log(
            action: 'PROJECT_RESOURCE_ACCESSED',
            entityType: class_basename($modelClass),
            entityId: 'COLLECTION',
            beforeData: null,
            afterData: null,
            status: 'SUCCESS',
            projectId: $project->id,
            metadata: [
                'project_code' => $project->code,
                'key_id' => $apiKey?->key_id,
                'resource' => $resourceKey,
                'scope' => $scope,
                'count' => count($projected),
            ],
            actorUserId: null,
            request: $request
        );

        if ($isPaginated) {
            return response()->json([
                'data' => $projected,
                'links' => [
                    'first' => $paginator->url(1),
                    'last' => $paginator->url($paginator->lastPage()),
                    'prev' => $paginator->previousPageUrl(),
                    'next' => $paginator->nextPageUrl(),
                ],
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
            ]);
        }

        return response()->json([
            'data' => $projected,
        ]);
    }

    /**
     * Map allowed employee field scopes to corresponding Eloquent relationships
     * for conditional eager loading.
     *
     * @param array<string> $allowedFields
     * @return array<string>
     */
    protected function resolveEmployeeRelationsToLoad(array $allowedFields): array
    {
        $relationMap = [
            'department' => 'department',
            'department_name' => 'department',
            'designation' => 'designation',
            'designation_name' => 'designation',
            'branch' => 'branch',
            'branch_name' => 'branch',
            'region' => 'region',
            'region_name' => 'region',
            'zone' => 'zone',
            'zone_name' => 'zone',
            'province' => 'province',
            'province_name' => 'province',
        ];

        $relations = [];
        foreach ($allowedFields as $field) {
            if (isset($relationMap[$field])) {
                $relations[] = $relationMap[$field];
            }
        }

        return array_values(array_unique($relations));
    }
}
