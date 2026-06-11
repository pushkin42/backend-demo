<?php

namespace App\Services\BX\Helpers;

use App\Models\DefaultModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

class ImportCacheHelper
{
    public function __construct(
        protected CacheArrayAdapter $caches,
    ) {
    }

    public function getXmlIdFor(DefaultModel|Model $model, ?int $id = null): ?string
    {
        if (!$id) {
            return null;
        }

        $modelClass = $model::class;
        $cacheKey = "xml_id_cache:$modelClass:$id";

        if (array_key_exists($cacheKey, $this->caches->toArray())) {
            return $this->caches[$cacheKey];
        }

        $query = $model->newQuery();

        if ($this->usesSoftDeletes($model)) {
            $query->withTrashed();
        }

        $result = $query->find($id);

        if (!$result?->xml_id) {
            return null;
        }

        return $this->caches[$cacheKey] = $result->xml_id;
    }

    public function getCacheFor(string|DefaultModel $model): Collection
    {
        $modelClass = $this->getModelName($model);

        if (isset($this->caches[$modelClass])) {
            return $this->caches[$modelClass];
        }

        $query = is_string($model)
            ? $model::query()
            : $model->newQuery();

        if ($this->usesSoftDeletes($model)) {
            $query->withTrashed();
        }

        return $this->caches[$modelClass] = $query->pluck('id', 'xml_id');
    }

    public function cacheModel(
        string|Model|Builder|QueryBuilder $model,
        string $key = 'xml_id',
        array|string $values = 'id',
    ): ?array {
        $values = is_string($values) ? [$values] : $values;

        [$cacheKey, $query] = $this->resolveQueryAndCacheKey($model);

        if (isset($this->caches[$cacheKey])) {
            return $this->caches[$cacheKey];
        }

        if ($this->usesSoftDeletes($model)) {
            $query->withTrashed();
        }

        $fields = array_values(array_merge([$key], $values));

        $result = $query
            ->select($fields)
            ->get()
            ->map(function ($item) use ($key, $values) {
                if (array_is_list($values)) {
                    return $item;
                }

                $item = (array) $item;
                $keys = array_keys(array_merge([$key => null], $values));

                return array_combine($keys, $item);
            })
            ->toArray();

        return $this->caches[$cacheKey] = $result;
    }

    public function addToCacheFor(
        string|DefaultModel $model,
        string|DefaultModel $xmlId,
        int|string|null $id = null,
    ): void {
        if ($xmlId instanceof DefaultModel) {
            $id = $xmlId->id ?? null;
            $xmlId = $xmlId->xml_id ?? null;
        }

        if (!$xmlId || !$id) {
            return;
        }

        data_set(
            $this->caches,
            $this->makeXmlIdCachePath($model, $xmlId),
            $id
        );
    }

    public function removeFromCacheFor(string|DefaultModel $model, string|DefaultModel $xmlId): void
    {
        if ($xmlId instanceof DefaultModel) {
            $xmlId = $xmlId->xml_id;
        }

        data_forget(
            $this->caches,
            $this->makeXmlIdCachePath($model, $xmlId)
        );
    }

    public function purgeCacheFor(string|DefaultModel $model): void
    {
        unset($this->caches[$this->getModelName($model)]);
    }

    public function getItemIdFromXmlId(
        string|DefaultModel $model,
        ?string $xmlId,
        ?int $default = null,
    ): ?int {
        if ($xmlId === null) {
            return $default;
        }

        $value = data_get(
            $this->caches,
            $this->makeXmlIdCachePath($model, $xmlId),
            $default
        );

        return $value === null ? null : (int) $value;
    }

    public function getModelName(string|DefaultModel $model): string
    {
        return $model instanceof DefaultModel
            ? $model::class
            : $model;
    }

    private function makeXmlIdCachePath(string|DefaultModel $model, string $xmlId): string
    {
        return $this->getModelName($model) . '.' . $xmlId;
    }

    private function resolveQueryAndCacheKey(string|Model|Builder|QueryBuilder $model): array
    {
        if ($model instanceof Builder) {
            return [$model->getModel()::class . ':cacheModel', $model];
        }

        if ($model instanceof QueryBuilder) {
            return ["{$model->from}:cacheModel", $model];
        }

        $modelClass = $this->getModelName($model);

        $query = is_string($model)
            ? $model::query()
            : $model->newQuery();

        return ["$modelClass:table:cacheModel", $query];
    }

    private function usesSoftDeletes(string|Model|Builder|QueryBuilder $model): bool
    {
        if ($model instanceof Builder) {
            $model = $model->getModel();
        }

        if ($model instanceof QueryBuilder) {
            return false;
        }

        $class = is_string($model) ? $model : $model::class;

        return in_array(SoftDeletes::class, class_uses_recursive($class), true);
    }
}
