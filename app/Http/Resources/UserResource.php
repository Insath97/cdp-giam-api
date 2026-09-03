<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_code' => $this->employee_code,
            'name' => $this->name,
            'username' => $this->username,
            'email' => $this->email,
            'user_type' => $this->user_type,
            'is_active' => $this->is_active,
            'can_login' => $this->can_login,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'last_login_ip' => $this->last_login_ip,
            'failed_login_attempts' => $this->failed_login_attempts,
            'lockout_until' => $this->lockout_until?->toIso8601String(),
            'employee' => $this->whenLoaded('employee', fn () => [
                'id' => $this->employee->id,
                'full_name' => $this->employee->full_name,
                'department_code' => $this->employee->department_code,
                'designation_code' => $this->employee->designation_code,
                'branch_code' => $this->employee->branch_code,
            ]),
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->pluck('name')),
            'permissions' => $this->whenLoaded('permissions', fn () => $this->getAllPermissions()->pluck('name')),
            'version' => $this->version,
            'credential_delivery' => $this->when(isset($this->credential_delivery), fn () => $this->credential_delivery),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
