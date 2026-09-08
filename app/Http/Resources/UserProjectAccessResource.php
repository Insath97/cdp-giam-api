<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserProjectAccessResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     * Note: Does NOT expose project base_url or api_base_url.
     * Clearly distinguishes assigned project roles from direct per-user permission overrides.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'project_id' => $this->project_id,
            'project' => [
                'id' => $this->project->id,
                'code' => $this->project->code,
                'name' => $this->project->name,
                'description' => $this->project->description,
                'status' => $this->project->status,
            ],
            'status' => $this->status,
            'version' => $this->version,
            'assigned_by' => $this->assigned_by,
            'assigned_at' => $this->assigned_at?->toIso8601String(),
            'revoked_by' => $this->revoked_by,
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'revocation_reason' => $this->revocation_reason,
            'roles' => $this->roles->map(fn ($r) => [
                'id' => $r->id,
                'external_role_id' => $r->external_role_id,
                'code' => $r->code,
                'name' => $r->name,
                'description' => $r->description,
            ])->values()->all(),
            'direct_permissions' => $this->permissions->map(fn ($p) => [
                'id' => $p->id,
                'external_permission_id' => $p->external_permission_id,
                'code' => $p->code,
                'name' => $p->name,
                'is_granted' => (bool) ($p->pivot->is_granted ?? true),
                'assigned_at' => $p->pivot->assigned_at ? (is_string($p->pivot->assigned_at) ? $p->pivot->assigned_at : $p->pivot->assigned_at->toIso8601String()) : null,
            ])->values()->all(),
            'assigned_roles_count' => $this->roles->count(),
            'direct_permission_overrides_count' => $this->permissions->count(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
