<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateModuleRequest;
use App\Http\Requests\UpdateModuleRequest;
use App\Models\Module;
use App\Traits\ActivityLogTrait;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class ModuleController extends Controller implements HasMiddleware
{
    use ActivityLogTrait;

    /**
     * Define the middleware for this controller.
     */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:Module Index', only: ['index', 'show']),
            new Middleware('permission:Module Create', only: ['store']),
            new Middleware('permission:Module Update', only: ['update']),
            new Middleware('permission:Module Delete', only: ['destroy']),
        ];
    }

    /**
     * Display a listing of modules.
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $query = Module::with('application');

            if ($request->has('application_id')) {
                $query->where('application_id', $request->application_id);
            }

            if ($request->has('search') && $request->search != '') {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'LIKE', "%{$search}%")
                      ->orWhere('code', 'LIKE', "%{$search}%");
                });
            }

            $modules = $query->orderBy('name', 'asc')->paginate($perPage);

            return response()->json([
                'status' => 'success',
                'message' => 'Modules retrieved successfully',
                'data' => $modules,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve modules',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Store a newly created module.
     */
    public function store(CreateModuleRequest $request)
    {
        try {
            $data = $request->validated();
            $module = Module::create($data);

            $this->logActivity('CREATE', 'Module', "Created module: {$module->name} for Application ID: {$module->application_id}", $data);

            return response()->json([
                'status' => 'success',
                'message' => 'Module created successfully',
                'data' => $module->load('application'),
            ], 201);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create module',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Display the specified module.
     */
    public function show(string $id)
    {
        try {
            $module = Module::with(['application', 'permissions'])->find($id);

            if (!$module) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Module not found',
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Module retrieved successfully',
                'data' => $module,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve module',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Update the specified module.
     */
    public function update(UpdateModuleRequest $request, string $id)
    {
        try {
            $module = Module::find($id);

            if (!$module) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Module not found',
                ], 404);
            }

            $data = $request->validated();
            $module->update($data);

            $this->logActivity('UPDATE', 'Module', "Updated module: {$module->name}", $data);

            return response()->json([
                'status' => 'success',
                'message' => 'Module updated successfully',
                'data' => $module->load('application'),
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update module',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Remove the specified module.
     */
    public function destroy(string $id)
    {
        try {
            $module = Module::find($id);

            if (!$module) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Module not found',
                ], 404);
            }

            $name = $module->name;
            $module->delete();

            $this->logActivity('DELETE', 'Module', "Deleted module: {$name}");

            return response()->json([
                'status' => 'success',
                'message' => 'Module deleted successfully',
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete module',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error',
            ], 500);
        }
    }
}
