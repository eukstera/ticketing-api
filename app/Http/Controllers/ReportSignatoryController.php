<?php

namespace App\Http\Controllers;

use App\Http\Resources\Admin\ReportSignatoryResource;
use App\Models\ReportSignatory;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;

class ReportSignatoryController extends Controller
{
    use ApiResponseTrait;

    /**
     * Active signatories for the report download dropdown, default first.
     */
    public function index(): JsonResponse
    {
        $signatories = ReportSignatory::query()
            ->where('status', true)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return $this->success('Signatory list retrieved', [
            'result' => ['signatories' => ReportSignatoryResource::collection($signatories)->resolve()],
        ]);
    }
}
