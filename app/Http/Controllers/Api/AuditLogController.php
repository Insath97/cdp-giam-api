<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AuditLogController extends Controller
{
    /**
     * Display a paginated listing of audit logs with optional filtering.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = AuditLog::with(['actor', 'project'])->orderBy('id', 'desc');

        if ($request->filled('action')) {
            $actions = is_array($request->input('action'))
                ? $request->input('action')
                : explode(',', $request->input('action'));
            $query->whereIn('action', array_map('trim', $actions));
        }

        if ($request->filled('entity_type')) {
            $query->where('entity_type', $request->input('entity_type'));
        }

        if ($request->filled('actor_user_id')) {
            $query->where('actor_user_id', $request->integer('actor_user_id'));
        }

        if ($request->filled('project_id')) {
            $query->where('project_id', $request->integer('project_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', strtoupper($request->input('status')));
        }

        if ($request->filled('from_date')) {
            $query->where('created_at', '>=', $request->date('from_date'));
        }

        if ($request->filled('to_date')) {
            $query->where('created_at', '<=', $request->date('to_date'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('action', 'like', "%{$search}%")
                  ->orWhere('entity_id', 'like', "%{$search}%")
                  ->orWhere('ip_address', 'like', "%{$search}%");
            });
        }

        $perPage = min(max($request->integer('per_page', 20), 1), 100);
        $logs = $query->paginate($perPage);

        return AuditLogResource::collection($logs);
    }

    /**
     * Display the specified audit log entry.
     */
    public function show(int $id): AuditLogResource
    {
        $log = AuditLog::with(['actor', 'project'])->findOrFail($id);

        return new AuditLogResource($log);
    }
}
