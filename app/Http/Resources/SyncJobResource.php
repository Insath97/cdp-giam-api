<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SyncJobResource extends JsonResource
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
            'idempotency_key' => $this->idempotency_key,
            'user_id' => $this->user_id,
            'project_id' => $this->project_id,
            'project' => [
                'id' => $this->project?->id,
                'code' => $this->project?->code,
                'name' => $this->project?->name,
            ],
            'operation' => $this->operation,
            'payload' => $this->payload,
            'status' => $this->status,
            'attempt_count' => $this->attempt_count,
            'max_attempts' => $this->max_attempts,
            'last_error' => $this->last_error,
            'http_status_code' => $this->http_status_code,
            'next_retry_at' => $this->next_retry_at?->toIso8601String(),
            'processed_at' => $this->processed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
