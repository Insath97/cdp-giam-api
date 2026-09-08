<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'base_url' => $this->base_url,
            'icon_url' => $this->icon_url,
            'status' => $this->status,
            'integration' => new ProjectIntegrationResource($this->whenLoaded('integration')),
            'modules_count' => $this->whenCounted('modules'),
            'roles_count' => $this->whenCounted('roles'),
            'permissions_count' => $this->whenCounted('permissions'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
