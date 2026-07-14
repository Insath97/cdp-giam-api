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
            new Middleware('permission:Application Index', only: ['index', 'show', 'getActiveList']),
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
            $application = Application::with('modules')->find($id);

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
        try {
            $applications = Application::where('is_active', true)->orderBy('name', 'asc')->get(['id', 'name', 'code']);

            return response()->json([
                'status' => 'success',
                'message' => 'Active applications retrieved successfully',
                'data' => $applications,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve active applications',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error',
            ], 500);
        }
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
}
