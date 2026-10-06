<?php

namespace App\Http\Resources;

use App\Models\Project;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resource transformer for external Project API consumption of Employee master data.
 *
 * Implements strict deny-by-default projection. Only fields explicitly configured
 * in the authenticated project's integration `allowed_resource_fields['employees']`
 * are serialized.
 *
 * Supports safe nested organizational representations for approved relationships
 * (department, designation, branch, region, zone, province) without exposing internal
 * database primary keys or sensitive employee PII.
 */
class ProjectEmployeeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Project|null $project */
        $project = $request->attributes->get('authenticated_project');

        $allowedFields = $project?->integration?->allowed_resource_fields['employees'] ?? [];

        // Deny-by-default: If no resource field allowlist is configured, return empty object
        if (!is_array($allowedFields) || empty($allowedFields)) {
            return [];
        }

        $result = [];

        foreach ($allowedFields as $field) {
            // 1. Approved nested organizational relationships (safe representations)
            if ($field === 'department') {
                $result['department'] = $this->department ? [
                    'code' => $this->department->code,
                    'name' => $this->department->name,
                ] : null;
                continue;
            }

            if ($field === 'designation') {
                $result['designation'] = $this->designation ? [
                    'code' => $this->designation->code,
                    'name' => $this->designation->name,
                ] : null;
                continue;
            }

            if ($field === 'branch') {
                $result['branch'] = $this->branch ? [
                    'code' => $this->branch->code,
                    'name' => $this->branch->name,
                    'city' => $this->branch->city,
                ] : null;
                continue;
            }

            if ($field === 'region') {
                $result['region'] = $this->region ? [
                    'code' => $this->region->code,
                    'name' => $this->region->name,
                ] : null;
                continue;
            }

            if ($field === 'zone') {
                $result['zone'] = $this->zone ? [
                    'code' => $this->zone->code,
                    'name' => $this->zone->name,
                ] : null;
                continue;
            }

            if ($field === 'province') {
                $result['province'] = $this->province ? [
                    'code' => $this->province->code,
                    'name' => $this->province->name,
                ] : null;
                continue;
            }

            // 2. Scalar string convenience fields
            if ($field === 'department_name') {
                $result['department_name'] = $this->department?->name;
                continue;
            }

            if ($field === 'designation_name') {
                $result['designation_name'] = $this->designation?->name;
                continue;
            }

            if ($field === 'branch_name') {
                $result['branch_name'] = $this->branch?->name;
                continue;
            }

            if ($field === 'region_name') {
                $result['region_name'] = $this->region?->name;
                continue;
            }

            if ($field === 'zone_name') {
                $result['zone_name'] = $this->zone?->name;
                continue;
            }

            if ($field === 'province_name') {
                $result['province_name'] = $this->province?->name;
                continue;
            }

            // 3. Direct model attributes (e.g. employee_code, full_name, email, department_code, etc.)
            $attributes = $this->resource instanceof \Illuminate\Database\Eloquent\Model
                ? $this->resource->getAttributes()
                : [];

            if (array_key_exists($field, $attributes)) {
                $value = $this->resource->{$field};

                if ($value instanceof CarbonInterface) {
                    $value = $value->toDateString();
                }

                $result[$field] = $value;
            }
        }

        return $result;
    }
}
