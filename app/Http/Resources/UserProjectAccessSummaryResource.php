<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserProjectAccessSummaryResource extends JsonResource
{
    /**
     * Transform the resource into a summary array for list views.
     * Note: Does NOT expose project base_url or api_base_url.
     * Does NOT return full permission catalogs to keep 50+ project listings lightweight.
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
                'status' => $this->project->status,
            ],
            'status' => $this->status,
            'version' => $this->version,
            'assigned_by' => $this->assigned_by,
            'assigned_at' => $this->assigned_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'roles' => $this->roles->map(fn ($r) => [
                'id' => $r->id,
                'external_role_id' => $r->external_role_id,
                'code' => $r->code,
                'name' => $r->name,
            ])->values()->all(),
            'assigned_roles_count' => $this->roles->count(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
