<?php

namespace App\Http\Resources\Admin;

use App\Models\Designation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Designation
 */
class DesignationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'designation' => $this->designation,
            'department_id' => (int) $this->department_id,
            'status' => (bool) $this->status,
        ];
    }
}
