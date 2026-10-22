<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\ReportSignatoryRequest;
use App\Http\Resources\Admin\ReportSignatoryResource;
use App\Models\ReportSignatory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class ReportSignatoryController extends AdminResourceController
{
    protected function modelClass(): string
    {
        return ReportSignatory::class;
    }

    protected function requestClass(): string
    {
        return ReportSignatoryRequest::class;
    }

    protected function resourceClass(): string
    {
        return ReportSignatoryResource::class;
    }

    protected function listKey(): string
    {
        return 'signatories';
    }

    protected function itemKey(): string
    {
        return 'signatory';
    }

    protected function noun(): string
    {
        return 'Signatory';
    }

    protected function searchColumn(): string
    {
        return 'name';
    }

    /**
     * The default must stay active, and un-defaulting is done by choosing
     * another default instead.
     *
     * @param  ReportSignatory  $row
     */
    protected function guardUpdate(Model $row, array $attributes): ?JsonResponse
    {
        $willBeDefault = $attributes['is_default'] ?? $row->is_default;
        $willBeActive = $attributes['status'] ?? $row->status;

        if ($row->is_default && ! $willBeDefault) {
            return $this->error('Set another signatory as default first.', 422);
        }

        if ($willBeDefault && ! $willBeActive) {
            return $this->inactiveDefaultError();
        }

        return null;
    }

    private function inactiveDefaultError(): JsonResponse
    {
        return $this->error('The default signatory must be active.', 422);
    }

    /**
     * @param  ReportSignatory  $row
     */
    protected function guardDeactivate(Model $row): ?JsonResponse
    {
        return $row->is_default ? $this->error('Set another signatory as default first.', 422) : null;
    }

    public function store(): JsonResponse
    {
        Gate::authorize('admin');

        $input = app(ReportSignatoryRequest::class)->validated();

        if (($input['is_default'] ?? false) && ! ($input['status'] ?? true)) {
            return $this->inactiveDefaultError();
        }

        $response = parent::store();

        $this->keepSingleDefault($response);

        return $response;
    }

    public function update(string $id): JsonResponse
    {
        $response = parent::update($id);

        $this->keepSingleDefault($response);

        return $response;
    }

    /**
     * Clears is_default on every other row after a successful save that
     * made one signatory the default.
     */
    private function keepSingleDefault(JsonResponse $response): void
    {
        $signatory = $response->getData(true)['result']['signatory'] ?? null;

        if ($response->isSuccessful() && $signatory !== null && $signatory['is_default']) {
            ReportSignatory::query()->whereKeyNot($signatory['id'])->where('is_default', true)->update(['is_default' => false]);
        }
    }
}
