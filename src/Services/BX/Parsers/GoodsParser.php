<?php

namespace App\Services\BX\Parsers;

use Arr;

class GoodsParser extends BaseParser
{

    public function parse($data): ?array
    {
        if (!is_array($data)) {
            return null;
        }

        $this->ensureDataIsCorrectArray($data);

        $md5 = (md5(json_encode($data)));
        $key = "bx:goods_parser_{$md5}_results";

        return cache2()->for($this)->remember(
            name: $key,
            value: function () use ($data) {
                $ret = [];
                if (data_has($data, 'Ид')) {
                    $data = [$data]; // одиночный товар
                }

                foreach ($data as $item) {
                    $xmlId = data_get($item, 'Ид');
                    if (!$xmlId) continue;

                    $sc = data_get($item, 'Штрихкод');
                    $sc = is_array($sc) ? null : trim((string)($sc));

                    $article = data_get($item, 'Артикул');
                    $article = is_array($article) ? null : trim((string)$article);

                    $name = $this->getFieldValue($item, 'Наименование');

                    $current = collect([
                        'xml_id' => $xmlId,
                        'version' => $this->getFieldValue($item, 'НомерВерсии'),
                        'deleted_at' => $this->getDeletedAtField($item),
                        'article' => $article,
                        'name' => $name,
                        'base_measure_id' => $this->getFieldValue($item, 'БазоваяЕдиница'),
                        'preview_text' => $this->getFieldValue($item, 'Описание'),
                        'b_code' => $sc,
                        'data' => [
                            'country' => $this->getFieldValue($item, 'Страна'),
                            'manufacturer' => $this->getFieldValue($item, 'Изготовитель'),
                            'dimensions' => [
                                'w' => $this->getFieldValue($item, 'Ширина', 0),
                                'h' => $this->getFieldValue($item, 'Длина', 0),
                                'l' => $this->getFieldValue($item, 'Высота', 0),

                            ]
                        ],

                        'groups' => Arr::flatten($this->getFieldValue($item, 'Группы', [])),
                        'properties' => $this->parseProps($this->getFieldValue($item, 'ЗначенияСвойств.ЗначенияСвойства', [])),
                        'req' => $this->parseReq($this->getFieldValue($item, 'ЗначенияРеквизитов.ЗначениеРеквизита', [])),
                        'pictures' => Arr::flatten($this->getFieldValue($item, 'Картинки.Картинка', [$this->getFieldValue($item, 'Картинка') ?? null])),

                    ])->reject(fn($v) => $v === null);

                    $ret[$xmlId] = $current->toArray();
                }

                return $ret;
            },
            ttl: now()->addMinutes(5)
        );

    }

    protected function parseProps(array $props): array
    {
        return $this->parseArray($props, 'Ид', 'Значение');
    }

    private function parseArray(array $data, string $key, string $value)
    {
        return collect(Arr::mapWithKeys($data, function ($v, $k) use ($key, $value) {
            $pr = data_get($v, $key, $k);
            $zn = data_get($v, $value, $v);
            if ($zn === null || $zn === [] || $zn === '[]' || $zn === '00000000-0000-0000-0000-000000000000') {
                return [$pr => null];
            }
            return rescue(fn() => [$pr => $zn], [$k => $v]);
        }))->reject(function ($v) {
            return $v === null;
        })->mapWithKeys(function ($v, $k) {
            if ($v === 'true') $v = true;
            if ($v === 'false') $v = false;
            if (is_numeric($v)) $v = floatval($v);
            if (is_string($v)) $v = trim($v);
            return [$k => $v];
        })->toArray();
    }

    protected function parseReq(array $req): array
    {
        return $this->parseArray($req, 'Наименование', 'Значение');
    }
}
