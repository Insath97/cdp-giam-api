<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_code' => $this->employee_code,
            'f_name' => $this->f_name,
            'l_name' => $this->l_name,
            'full_name' => $this->full_name,
            'name_with_initials' => $this->name_with_initials,
            'employee_type' => $this->employee_type,
            'id_type' => $this->id_type,
            'id_number' => $this->id_number,
            'date_of_birth' => $this->date_of_birth?->format('Y-m-d'),
            'email' => $this->email,
            'phone' => $this->phone,
            'address_line_1' => $this->address_line_1,
            'city' => $this->city,
            'state' => $this->state,
            'country' => $this->country,
            'postal_code' => $this->postal_code,
            'phone_primary' => $this->phone_primary,
            'phone_secondary' => $this->phone_secondary,
            'have_whatsapp' => $this->have_whatsapp,
            'whatsapp_number' => $this->whatsapp_number,
            'start_date' => $this->start_date?->format('Y-m-d'),
            'end_date' => $this->end_date?->format('Y-m-d'),
            'is_active' => $this->is_active,
            'province' => $this->whenLoaded('province', fn () => [
                'code' => $this->province->code,
                'name' => $this->province->name,
            ], ['code' => $this->province_code]),
            'zone' => $this->whenLoaded('zone', fn () => [
                'code' => $this->zone->code,
                'name' => $this->zone->name,
            ], ['code' => $this->zonal_code]),
            'region' => $this->whenLoaded('region', fn () => [
                'code' => $this->region->code,
                'name' => $this->region->name,
            ], ['code' => $this->region_code]),
            'branch' => $this->branch_code ? ($this->whenLoaded('branch', fn () => [
                'code' => $this->branch->code,
                'name' => $this->branch->name,
            ], ['code' => $this->branch_code])) : null,
            'department' => $this->whenLoaded('department', fn () => [
                'code' => $this->department->code,
                'name' => $this->department->name,
            ], ['code' => $this->department_code]),
            'designation' => $this->whenLoaded('designation', fn () => [
                'code' => $this->designation->code,
                'name' => $this->designation->name,
            ], ['code' => $this->designation_code]),
            'reporting_manager_code' => $this->reporting_manager_code,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'username' => $this->user->username,
                'user_type' => $this->user->user_type,
                'is_active' => $this->user->is_active,
                'can_login' => $this->user->can_login,
            ]),
            'version' => $this->version,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
