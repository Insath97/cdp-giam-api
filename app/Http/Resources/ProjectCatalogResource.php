<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectCatalogResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'project_id' => $this->id,
            'project_code' => $this->code,
            'project_name' => $this->name,
            'last_sync_catalog_at' => $this->integration?->last_sync_catalog_at?->toIso8601String(),
            'modules' => $this->modules->map(fn ($m) => [
                'id' => $m->id,
                'external_module_id' => $m->external_module_id,
                'code' => $m->code,
                'name' => $m->name,
                'description' => $m->description,
                'is_active' => (bool) $m->is_active,
            ]),
            'roles' => $this->roles->map(fn ($r) => [
                'id' => $r->id,
                'external_role_id' => $r->external_role_id,
                'code' => $r->code,
                'name' => $r->name,
                'description' => $r->description,
                'is_active' => (bool) $r->is_active,
            ]),
            'assignable_roles' => $this->roles->where('is_active', true)->map(fn ($r) => [
                'id' => $r->id,
                'external_role_id' => $r->external_role_id,
                'code' => $r->code,
                'name' => $r->name,
                'description' => $r->description,
                'is_active' => true,
            ])->values(),
            'permission_groups' => $this->permissionGroups->map(fn ($g) => [
                'id' => $g->id,
                'external_group_id' => $g->external_group_id,
                'code' => $g->code,
                'name' => $g->name,
                'description' => $g->description,
                'permissions' => $g->permissions->map(fn ($p) => [
                    'id' => $p->id,
                    'external_permission_id' => $p->external_permission_id,
                    'code' => $p->code,
                    'name' => $p->name,
                    'description' => $p->description,
                ]),
            ]),
            'permissions' => $this->permissions->map(fn ($p) => [
                'id' => $p->id,
                'external_permission_id' => $p->external_permission_id,
                'code' => $p->code,
                'name' => $p->name,
                'description' => $p->description,
                'is_active' => (bool) $p->is_active,
            ]),
        ];
    }
}
