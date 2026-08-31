<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateApplicationRequest;
use App\Http\Requests\UpdateApplicationRequest;
use App\Models\Application;
use App\Traits\ActivityLogTrait;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class ApplicationController extends Controller implements HasMiddleware
{
    use ActivityLogTrait;

    /**
     * Define the middleware for this controller.
     */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:Application Index', only: ['index', 'show']),
            new Middleware('permission:Application Index|User Create|User Update', only: ['getActiveList']),
            new Middleware('permission:Application Create', only: ['store']),
            new Middleware('permission:Application Update', only: ['update']),
            new Middleware('permission:Application Delete', only: ['destroy']),
            new Middleware('permission:Application Toggle Status', only: ['toggleStatus']),
        ];
    }

    /**
     * Display a listing of applications.
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $query = Application::query();

            if ($request->has('search') && $request->search != '') {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'LIKE', "%{$search}%")
                      ->orWhere('code', 'LIKE', "%{$search}%");
                });
            }

            $applications = $query->orderBy('name', 'asc')->paginate($perPage);

            return response()->json([
                'status' => 'success',
                'message' => 'Applications retrieved successfully',
                'data' => $applications,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve applications',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Store a newly created application.
     */
    public function store(CreateApplicationRequest $request)
    {
        try {
            $data = $request->validated();
            $application = Application::create($data);

            $this->logActivity('CREATE', 'Application', "Created application: {$application->name}", $data);

            return response()->json([
                'status' => 'success',
                'message' => 'Application created successfully',
                'data' => $application,
            ], 201);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create application',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Display the specified application.
     */
    public function show(string $id)
    {
        try {
            $application = Application::with([
                'modules',
                'roles' => function ($query) {
                    $query->orderBy('name');
                },
                'permissionGroups' => function ($query) {
                    $query->with('permissions')->orderBy('name');
                }
            ])->find($id);

            if (!$application) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Application not found',
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Application retrieved successfully',
                'data' => $application,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve application',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Update the specified application.
     */
    public function update(UpdateApplicationRequest $request, string $id)
    {
        try {
            $application = Application::find($id);

            if (!$application) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Application not found',
                ], 404);
            }

            $data = $request->validated();
            $application->update($data);

            $this->logActivity('UPDATE', 'Application', "Updated application: {$application->name}", $data);

            return response()->json([
                'status' => 'success',
                'message' => 'Application updated successfully',
                'data' => $application,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update application',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Remove the specified application.
     */
    public function destroy(string $id)
    {
        try {
            $application = Application::find($id);

            if (!$application) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Application not found',
                ], 404);
            }

            $name = $application->name;
            $application->delete();

            $this->logActivity('DELETE', 'Application', "Deleted application: {$name}");

            return response()->json([
                'status' => 'success',
                'message' => 'Application deleted successfully',
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete application',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Get active applications list.
     */
    public function getActiveList()
    {
        $user = auth('api')->user();
        $query = Application::query()->where('is_active', true)->orderBy('name');
        if (!$user->hasRole('Super Admin') && !$user->can('Project Access Assign')) {
            $query->whereHas('users', fn($q) => $q->whereKey($user->id));
        }
        return response()->json(['status'=>'success','message'=>'Projects retrieved successfully','data'=>$query->get(['id','name','code','description','app_url','is_active'])]);
    }

    public function catalog(string $id)
    {
        $user=auth('api')->user(); $app=Application::findOrFail($id);
        if(!$user->hasRole('Super Admin') && !$user->applications()->whereKey($app->id)->exists()) return response()->json(['status'=>'error','message'=>'Project access denied'],403);
        $app->load([
            'modules:id,application_id,name,code,description',
            'roles:id,name,guard_name,application_id,is_protected,source',
            'permissionGroups:id,name,description,application_id,source',
            'permissions:id,name,guard_name,group_name,module_id,application_id',
        ]);
        return response()->json(['status'=>'success','message'=>'Project catalog retrieved successfully','data'=>['project'=>$app,'modules'=>$app->modules,'roles'=>$app->roles,'permission_groups'=>$app->permissionGroups,'permissions'=>$app->permissions]]);
    }

    /**
     * Toggle active status.
     */
    public function toggleStatus(string $id)
    {
        try {
            $application = Application::find($id);

            if (!$application) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Application not found',
                ], 404);
            }

            $application->is_active = !$application->is_active;
            $application->save();

            $this->logActivity('TOGGLE_STATUS', 'Application', "Toggled application status: {$application->name} (" . ($application->is_active ? 'Active' : 'Inactive') . ")");

            return response()->json([
                'status' => 'success',
                'message' => 'Application status updated successfully',
                'data' => [
                    'id' => $application->id,
                    'is_active' => $application->is_active,
                ],
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to toggle application status',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Manually trigger user sync for the application.
     */
    public function syncUsers(string $id)
    {
        try {
            $application = Application::find($id);

            if (!$application) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Application not found',
                ], 404);
            }

            $syncService = new \App\Services\ProjectSyncService();
            $success = $syncService->syncUsersToProject($application);

            if ($success) {
                $this->logActivity('SYNC_USERS', 'Application', "Manually synced users to application: {$application->name}");
                return response()->json([
                    'status' => 'success',
                    'message' => 'Users synced successfully',
                ], 200);
            } else {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Failed to sync users. Ensure push endpoint is configured and reachable.',
                ], 500);
            }
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to sync users',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error',
            ], 500);
        }
    }
}
