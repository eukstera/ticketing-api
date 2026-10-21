<?php

namespace App\Http\Resources;

use App\Models\Priority;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Priority
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
            'department_id' => (int) $this->department_id,
        ];
    }
}
