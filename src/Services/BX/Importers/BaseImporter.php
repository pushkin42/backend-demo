<?php

namespace App\Services\BX\Importers;

use App\Enums\BX_ImportResult;
use App\Models\DefaultModel;
use App\Traits\HasCustomLoggerTrait;
use App\Traits\InteractsWithBitrixDataTrait;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;
use Throwable;

abstract class BaseImporter
{

    use HasCustomLoggerTrait, InteractsWithBitrixDataTrait;

    public function __construct()
    {
        $this->initializeLogger('bx_import');
    }

    public abstract function import(array $item): BX_ImportResult;

    protected function prepareItem(array $item, $model): array
    {
        // метод перекрывается в вышестоящих классах, здесь просто возвращаем что дали
        return $item;
    }

    protected function log($item, ?string $s = null, $op = 'info'): void
    {
        if ($op === 'warn') $op = 'warning';

        if (!method_exists($this->logger, $op)) {
            $op = 'warning';
        }

        if ($s) $this->logger->$op($s);
        else {
            $cl = class_basename($this);
            $this->logger->$op("Сообщение от импортера $cl:");
        }
        $this->logger->$op($item);
    }

    protected function getImportResult(?DefaultModel $r): BX_ImportResult
    {
        if (!$r) {
            return BX_ImportResult::BX_IMPORT_RESULT_ERROR;
        }

        if ($r->deleted_at ?? null) {
            return BX_ImportResult::BX_IMPORT_RESULT_DELETED;
        }

        if ($r->wasRecentlyCreated) {
            $r->save();
            return BX_ImportResult::BX_IMPORT_RESULT_ADDED;
        } elseif ($r->wasChanged()) {
            $r->save();
            return BX_ImportResult::BX_IMPORT_RESULT_UPDATED;
        }

        return BX_ImportResult::BX_IMPORT_RESULT_IGNORED;
    }

    protected function doImport(string|DefaultModel|Closure $model, ?array $item = null, mixed $withApiVersion = null): mixed
    {

        if (is_callable($model)) {
            if ($item !== null) {
                throw new InvalidArgumentException('Нельзя передавать $item вместе с вызываемым $model');
            }
            return $model();
        }

        if ($item === null) {
          throw new InvalidArgumentException('Не переданы данные для импорта');
        }

        $modelClass = is_string($model) ? $model : $model::class;

        try {
            $modelClass::unguard();

            if ($withApiVersion !== null && $withApiVersion !== false) {
                if (is_int($withApiVersion)) {
                    $m = array_merge($item, ['api_version' => $withApiVersion]);
                } else {
                    $m = array_merge($item, ['api_version' => config('bx.minimum_api_version')]);
                }
            } else {
                $m = $item;
            }


            $r = $modelClass::query()->withTrashed()->updateOrCreate(
                [
                    'xml_id' => $item['xml_id'],
                ],
                $m
            );

            if ($r && $r->id && !$r->deleted_at) {
                $this->importCacheHelper?->addToCacheFor($modelClass, $r->xml_id ?? $item['xml_id'], $r->id);
            }

            if (data_get($item, 'deleted_at')) {
                $this->importCacheHelper?->deleteFromCacheFor($modelClass, $item['xml_id']);
                return BX_ImportResult::BX_IMPORT_RESULT_DELETED;
            }
            if ($r && $r->wasRecentlyCreated) {
                return BX_ImportResult::BX_IMPORT_RESULT_ADDED;
            }
            if ($r && !$r->wasRecentlyCreated && $r->wasChanged()) {
                return BX_ImportResult::BX_IMPORT_RESULT_UPDATED;
            }
            return BX_ImportResult::BX_IMPORT_RESULT_IGNORED;
        } catch (UniqueConstraintViolationException $exception) {
            $this->logger->error("Дубликат: [$modelClass]: {$item['xml_id']}: " . $exception->getMessage());
            return BX_ImportResult::BX_IMPORT_RESULT_ERROR_DUPLICATE;
        } catch (Throwable $exception) {
            $this->logger->error("Ошибка импорта: [$modelClass]: {$item['xml_id']}: " . $exception->getMessage());
            return BX_ImportResult::BX_IMPORT_RESULT_ERROR;
        } finally {
            $modelClass::reguard();
        }
    }
}
