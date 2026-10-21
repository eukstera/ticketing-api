<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Priority
 */
class PriorityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'level' => $this->level,
            'sla_days' => (int) $this->sla_days,
            'department_id' => $this->department_id === null ? null : (int) $this->department_id,
            'status' => (bool) $this->status,
        ];
    }
}
