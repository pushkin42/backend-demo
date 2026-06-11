<?php

namespace App\Services\B2C\Goods;

use Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class OZONImporter extends BaseImporter implements MarketplaceGoodsImporterInterface
{

    protected function parseOzonRichContent(array $richData): array
    {
        $result = [];

        if (!isset($richData['content']) || !is_array($richData['content'])) {
            return $result;
        }

        foreach ($richData['content'] as $widget) {
            if (!isset($widget['blocks']) || !is_array($widget['blocks'])) {
                continue;
            }

            foreach ($widget['blocks'] as $block) {
                $titleParts = [];
                $textParts = [];

                if (isset($block['title']['items']) && is_array($block['title']['items'])) {
                    foreach ($block['title']['items'] as $item) {
                        if (isset($item['content'])) {
                            $titleParts[] = $item['content'];
                        }
                    }
                }

                if (isset($block['text']['items']) && is_array($block['text']['items'])) {
                    foreach ($block['text']['items'] as $item) {
                        if (isset($item['content'])) {
                            $textParts[] = $item['content'];
                        }
                        // Если это перенос строки, добавляем пробел или перенос
                        if (isset($item['type']) && $item['type'] === 'br') {
                            $textParts[] = "\n";
                        }
                    }
                }

                $cleanTitle = implode('', $titleParts);
                $cleanTitle = strip_tags($cleanTitle);
                $cleanTitle = trim(str_replace("\u{A0}", ' ', $cleanTitle));

                $cleanText = implode('', $textParts);
                $cleanText = strip_tags($cleanText);
                $cleanText = preg_replace('/[ \t]+/', ' ', $cleanText);
                $cleanText = trim(str_replace("\u{A0}", ' ', $cleanText));

                if ($cleanTitle !== '' || $cleanText !== '') {
                    $result[] = [
                        'title' => $cleanTitle,
                        'text' => $cleanText
                    ];
                }
            }
        }

        return $result;
    }

    public function getProps()
    {
        // /v1/product/info/description
        // {offer_id: <string>}
        /**
         * 7370 - материал обоев
         * 6468,13169 - длина рулона
         * 6467 - ширина рулона
         * 11002 - модификаторы (моющиеся...)
         * 4191 - описание товара краткое
         * 11254 - описание товара полное (JSON)
         * 6504 - 140 (плотность обоев)
         * 6391 - тип рисунка
         * 12421 - тип стыковки
         * 8470 - материал покрытия
         * 8468 - материал основания
         * 11848 - назначение
         * 21845 1500 (вес нетто в граммах)
         * 10096,10097 - цвет
         * 10400 - гарантия
         * 4389 - страна происхождения
         * 4384 - количество рулонов
         * 22232 - категория OZON
         * 12141 - C50 (класс пожарной безопасности)
         * 4180 - полное название
         * 4383 - 1400 (вес упаковки в граммах)
         * 9024 - артикул в нашей базе
         * 22270 - тип производства
         */


        $fn = function () {
            $pr = (array_values($this->getProductList()));

            $r = $this->clientInstance()
                ->withBody(json_encode(['filter' => ['product_id' => $pr], 'limit' => 1000, 'visibility' => 'ALL']))
                ->post("{$this->url}/v4/product/info/attributes");
            return $r->successful() ? $r->json() : null;
        };

        $ret = cache2()->remember("b2c_props", $fn, $this->getCacheTime());

        $result = $ret['result'] ?? null;
        if (!$result) return [];

        $retdata = [];

        foreach ($result as $key => $item) {
            $attributes = collect(data_get($item, 'attributes', []))
                ->whereIn('id', [4180, 4191, 4383, 4384, 4389, 6391, 6467, 6468, 6504, 7370, 8468, 8470, 9024, 10096, 10097, 10400, 11002, 11254, 11848, 12141, 12421, 13169, 21845, 22232, 22270])
                ->mapWithKeys(function ($v, $k) {
                    $vv = Arr::map($v['values'], fn($v) => $v['value']);
                    if (count($vv) <= 1) $vv = head($vv);
                    return [$v['id'] => $vv];
                });

            $rd = ($this->parseOzonRichContent(rescue(fn() => json_decode($attributes[11254], true), [])));
            $description = collect($rd)
                ->mapWithKeys(function ($v, $k) {
                    $t = trim($v['title']);
                    if (empty($t)) $t = '#' . md5($v['text']);
                    return [$t => str($v['text'])->remove("\n")->trim()->value()];
                })->toArray();

            $dl = [
                4180 => 'name',
                4191 => 'short_description',
                7370 => 'material',
                6468 => 'length',
                6467 => 'width',
                13169 => 'area_wl',
                6504 => 'density',
                6391 => 'picture_type',
                8470 => 'top_material',
                8468 => 'base_material',
                11848 => 'purpose',
            ];

            $retdata[$item['offer_id']] = [
                'attributes' => $attributes->except(11254)
                    ->mapWithKeys(function ($v, $k) use ($dl) {
                        $key = data_get($dl, $k, $k);
                        return [$key => $v];
                    })->toArray(),
                'description' => $description,
            ];
        }

        return $retdata;
    }

    public function getGoods(): Collection
    {
        $fn = function () {
            $rfn = fn() => array_values($this->getProductList() ?? []);
            $productIds = $rfn();//rescue(, []);

            if (empty($productIds)) {
                return collect();
            }

            //
            $c = $this->clientInstance()->withBody(json_encode(
                ['product_id' => $productIds]
            ))->post("{$this->url}/v3/product/info/list");

            if (!$c->successful()) {
                return collect();
            } else {
                $props = $this->getProps();
                return collect($c->json('items') ?? [])
                    ->map(function ($item) use ($props) {
                        $s = collect(data_get($item, 'stocks.stocks'));
                        $p = $s->sum('present');
                        $r = $s->sum('reserved');
                        return array_merge($item,
                            ['available' => rescue(fn() => $p - $r, 0)],
                            ['properties' => $props[$item['offer_id']] ?? []],
                        );
                    });
            }
        };

        $r = cache2()->remember("b2c_goods_list", $fn, $this->getCacheTime());
        return $r;
    }

    protected function getProductList(): array
    {

        $fn = function () {
            $lastId = null;

            $c = clone $this->clientInstance();
            $b = [
                'filter' => [
                    'visibility' => 'IN_SALE',
                ],
                'limit' => 1000,
            ];


            $ret = [];

            while ($lastId !== '') {
                $r = $c->withBody(json_encode($b))->post("{$this->url}/v3/product/list");
                if (!$r->successful()) {
                    return [];
                } else {
                    $data = $r->json('result');
                    $lastId = trim(data_get($data, 'last_id', ''));
                    if ($lastId) {
                        $b['last_id'] = $lastId;
                    }

                    $ret = array_merge($ret, data_get($data, 'items'));
                }
            }

            return collect($ret)
                ->reject(fn($v) => $v['archived'] === true)
                ->pluck('product_id', 'offer_id')
                ->toArray();
        };


        $r = cache2()->remember("b2c_products_list", $fn, $this->getCacheTime());
        if (count($r)) return $r;
        else {
            cache2()->forget("b2c_products_list");
            dd("No items available for synchronization");
        }

    }

    protected function getStocks(): Collection
    {
        /**
         * "limit": 1000,
         * "filter": {},
         * "cursor": "WzQ0NDk2NjI1MjQsNDQ0OTY2MjUyNF0="
         */

        $fn = function () {

            $lastId = null;

            $c = clone $this->clientInstance();
            $b = [
                'filter' => [
                    'visibility' => 'IN_SALE',
                ],
                'limit' => 1000,
            ];


            $ret = [];

            while ($lastId !== '') {
                $r = $c->withBody(json_encode($b))->post("{$this->url}/v4/product/info/stocks");
                if (!$r->successful()) {
                    dd($r->getBody()->getContents());
                    return [];
                } else {
                    $data = $r->json();
                    $lastId = trim(data_get($data, 'cursor', ''));
                    if ($lastId) {
                        $b['cursor'] = $lastId;
                    }

                    $ret = array_merge($ret, data_get($data, 'items'));
                }
            }

            return collect(Arr::map($ret, function ($item) {
                $a = collect(data_get($item, 'stocks', []));
                $totalAvailable = $a->sum('present');
                $totalReserved = $a->sum('reserved');
                $available = $totalAvailable - $totalReserved;
                return [
                    'product_id' => $item['product_id'],
                    'offer_id' => $item['offer_id'],
                    'available' => $available,
                ];
            }));

        };

        return cache2()->remember('b2c_stocks', $fn, $this->getCacheTime());
    }

}
