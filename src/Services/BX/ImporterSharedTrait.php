<?php

namespace Pushkin42\LaravelHelpers\CommerceML\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Pushkin42\LaravelHelpers\CommerceML\DTO\BaseParserDTO;
use Pushkin42\LaravelHelpers\CommerceML\DTO\BX_ProductDTO;
use Pushkin42\LaravelHelpers\CommerceML\Enums\BX_ImportDestination;
use Pushkin42\LaravelHelpers\CommerceML\Enums\BX_ItemImportResult;
use Pushkin42\LaravelHelpers\CommerceML\Interfaces\BX_FileImporterInterface;
use Throwable;

trait ImporterSharedTrait
{
    public function resolveDestination($destinationType, $destination): mixed
    {
        if ($destinationType === BX_ImportDestination::ELOQUENT) {
            // пишем в модель
            if (!class_exists($destination)) {
                throw new InvalidArgumentException("Класс $destination не существует");
            }
            if (!is_subclass_of($destination, Model::class)) {
                throw new InvalidArgumentException('Неверный тип переменной назначения');
            }
            return new $destination();
        }
        if ($destinationType === BX_ImportDestination::TABLE) {
            // пишем в таблицу
            if (!is_string($destination)) {
                throw new InvalidArgumentException("Неверный тип переменной назначения");
            }
            if (!Schema::hasTable($destination)) {
                throw new InvalidArgumentException("Таблица $destination не существует");
            }

            return DB::table($destination);
        }

        if ($destinationType === BX_ImportDestination::FILE) {
            // пишем в файл
            if (!is_resource($destination) && !is_string($destination)) {
                throw new InvalidArgumentException("Неверный тип переменной назначения");
            }
            if (is_string($destination)) {
                if (!touch($destination)) {
                    throw new InvalidArgumentException("Не удалось создать файл $destination");
                }
                if (!is_writeable($destination)) {
                    throw new InvalidArgumentException("Невозможно писать в файл $destination");
                }

                return fopen($destination, 'a');
            }
        }

        return null;
    }

    public function getEloquentFields(mixed $conn, mixed $table): array
    {
        return collect(Schema::connection($conn)->getColumns($table))
            ->reject(fn($v, $k) => isset($v['generation']))
            ->pluck('name')->values()->toArray();
    }

    public function getEloquentMeta(string $destination): array
    {
        if (!class_exists($destination)) {
            throw new InvalidArgumentException("Класс $destination не существует");
        }

        $destination = new $destination();
        $conn = $destination->getConnectionName();
        $table = $destination->getTable();
        return array($destination, $conn, $table);
    }

    protected function getEloquentItemImportResult(Model $item): BX_ItemImportResult
    {
        if (!$item->wasChanged()) {
            return BX_ItemImportResult::IGNORED;
        } else {
            if ($item->wasRecentlyCreated) {
                return BX_ItemImportResult::CREATED;
            } elseif (class_uses($item, SoftDeletes::class) && method_exists($item, 'trashed') && $item->trashed()) {
                return BX_ItemImportResult::DELETED;
            } else {
                return BX_ItemImportResult::UPDATED;
            }
        }
    }

    protected function importToArray($destination, BaseParserDTO $item, BX_FileImporterInterface $importer, array $extra = []): null|Throwable|array
    {
        if (is_subclass_of($destination, Model::class)) {
            $existing = data_get($extra, 'existing', []);
            data_forget($extra, 'existing');

            $xml_id = $item->xml_id;
            $key = (isset($existing[$xml_id])) ? 'update' : 'insert';

            list($destination, $conn, $table) = $this->getEloquentMeta($destination);

            $fields = $this->getEloquentFields($conn, $table);

            $arr = $item->toArray();
            $dataFields = Arr::only($arr, $fields);
            $extraFields = Arr::except($arr, $fields);

            $dataFields = Arr::mapWithKeys(Arr::except($dataFields, ['xml_id']), function ($v, $k) use ($item) {
                return [$k => $item->$k];
            });

            $dataFields = array_merge($dataFields, $extra);

            return ['method' => $key, 'data' => $dataFields, 'extra' => $extraFields];
        }

        return null;
    }

    protected function handleItemImport($destination, BaseParserDTO $item, BX_FileImporterInterface $importer, array $extra = []): Throwable|array
    {
        $ret = [];
        $target = null;

        if (is_subclass_of($destination, Model::class)) {
            list($destination, $conn, $table) = $this->getEloquentMeta($destination);

            $fields = $this->getEloquentFields($conn, $table);

            $dataFields = Arr::only($item->toArray(), $fields);
            $dataFields = Arr::mapWithKeys(Arr::except($dataFields, ['xml_id']), function ($v, $k) use ($item) {
                return [$k => $item->$k];
            });

            $dataFields = array_merge($dataFields, $extra);

            $destination::unguard();
            try {
                $newItem = $destination::query()->updateOrCreate(
                    [
                        'xml_id' => $item->xml_id
                    ],
                    $dataFields
                );

                $ir = $this->getEloquentItemImportResult($newItem);

                if (in_array($ir, [BX_ItemImportResult::DELETED, BX_ItemImportResult::CREATED])) {
                    return ['xml_id' => $item->xml_id, 'target' => $newItem, 'result' => $ir];
                }

                $ret = [
                    'item' => $ir,
                ];

                $am = [];

                if (method_exists($importer, 'afterImport')) {
                    $am = $importer->afterImport($destination, $item, $newItem);
                }

                $ret = array_merge($ret, $am);
                $target = $newItem;
            } catch (Throwable $e) {
                return $e;
            } finally {
                $destination::reguard();
            }

        }

        $uc = collect($ret)->flatten()->groupBy(fn($v) => $v?->value)->get('updated')?->count() ?? 0;

        $r = ($uc > 0) ? BX_ItemImportResult::UPDATED : BX_ItemImportResult::IGNORED;

        return ['xml_id' => $item->xml_id, 'target' => $target, 'result' => $r];
    }
}
