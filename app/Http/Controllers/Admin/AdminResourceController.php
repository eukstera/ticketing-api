<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListAdminResourceRequest;
use App\Traits\ApiResponseTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Shared list / create / update / soft-deactivate flow for the admin lookup
 * resources. Concrete controllers only describe their model, request and
 * resource, plus any guard that blocks a state change.
 */
abstract class AdminResourceController extends Controller
{
    use ApiResponseTrait;

    /**
     * @return class-string<Model>
     */
    abstract protected function modelClass(): string;

    /**
     * @return class-string<\Illuminate\Foundation\Http\FormRequest>
     */
    abstract protected function requestClass(): string;

    /**
     * @return class-string<\Illuminate\Http\Resources\Json\JsonResource>
     */
    abstract protected function resourceClass(): string;

    /** Plural key under result, e.g. "departments". */
    abstract protected function listKey(): string;

    /** Singular key under result, e.g. "department". */
    abstract protected function itemKey(): string;

    /** Human name used in messages, e.g. "Department". */
    abstract protected function noun(): string;

    /** Column that ?search matches against. */
    abstract protected function searchColumn(): string;

    /**
     * Payload field => database column, where they differ.
     *
     * @return array<string, string>
     */
    protected function columnMap(): array
    {
        return [];
    }

    protected function applyFilters(Builder $query, ListAdminResourceRequest $request): void
    {
        //
    }

    /**
     * Return a response to block an update, or null to allow it.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function guardUpdate(Model $row, array $attributes): ?JsonResponse
    {
        return null;
    }

    /**
     * Return a response to block deactivation, or null to allow it.
     */
    protected function guardDeactivate(Model $row): ?JsonResponse
    {
        return null;
    }

    public function index(): JsonResponse
    {
        Gate::authorize('admin');

        /** @var ListAdminResourceRequest $request */
        $request = app(ListAdminResourceRequest::class);

        $query = $this->modelClass()::query();

        if ($request->filled('search')) {
            $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $request->validated('search'));

            $query->whereRaw(
                $query->getQuery()->getGrammar()->wrap($this->searchColumn())." like ? escape '!'",
                ["%{$escaped}%"]
            );
        }

        match ($request->validated('status', 'all')) {
            'active' => $query->where('status', true),
            'inactive' => $query->where('status', false),
            default => null,
        };

        $this->applyFilters($query, $request);

        $paginator = $query->orderBy('id')
            ->paginate($request->integer('per_page', 15))
            ->through(fn (Model $row) => $this->present($row));

        return $this->success("{$this->noun()} list retrieved", [
            'result' => [$this->listKey() => $paginator->toArray()],
        ]);
    }

    public function store(): JsonResponse
    {
        Gate::authorize('admin');

        $attributes = $this->attributesFrom(app($this->requestClass())->validated());
        $attributes['status'] ??= true;

        $row = $this->modelClass()::query()->create($attributes);

        return $this->success("{$this->noun()} created", [
            'result' => [$this->itemKey() => $this->present($row)],
        ], 201);
    }

    public function update(string $id): JsonResponse
    {
        Gate::authorize('admin');

        $row = $this->modelClass()::query()->find($id);

        if ($row === null) {
            return $this->notFound();
        }

        $attributes = $this->attributesFrom(app($this->requestClass())->validated());

        if ($blocked = $this->guardUpdate($row, $attributes)) {
            return $blocked;
        }

        if (array_key_exists('status', $attributes) && ! $attributes['status'] && $row->status
            && $blocked = $this->guardDeactivate($row)) {
            return $blocked;
        }

        $row->update($attributes);

        return $this->success("{$this->noun()} updated", [
            'result' => [$this->itemKey() => $this->present($row->refresh())],
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        Gate::authorize('admin');

        $row = $this->modelClass()::query()->find($id);

        if ($row === null) {
            return $this->notFound();
        }

        if ($row->status && $blocked = $this->guardDeactivate($row)) {
            return $blocked;
        }

        $row->update(['status' => false]);

        return $this->success("{$this->noun()} deactivated", [
            'result' => [$this->itemKey() => $this->present($row->refresh())],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function attributesFrom(array $payload): array
    {
        $attributes = [];

        foreach ($payload as $field => $value) {
            $attributes[$this->columnMap()[$field] ?? $field] = $value;
        }

        if (array_key_exists('status', $attributes)) {
            $attributes['status'] = (bool) $attributes['status'];
        }

        return $attributes;
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(Model $row): array
    {
        $resource = $this->resourceClass();

        return (new $resource($row))->resolve();
    }

    protected function notFound(): JsonResponse
    {
        return $this->error("{$this->noun()} not found.", 404);
    }
}
