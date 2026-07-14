<?php

namespace App\Services\Global\Data\Kladr;

use Arr;
use DB;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

/**
 * Классификатор оформлен в виде следующих DBF-файлов:
 * файл Kladr.dbf – содержит записи с объектами первых четырех уровней классификации (регионы; районы (улусы); города, поселки городского типа, сельсоветы; сельские населенные пункты);
 * Структура кодового обозначения в блоке "Код":
 * СС РРР ГГГ ППП АА, где
 * СС – код субъекта Российской Федерации (региона), коды регионов представлены в Приложении 2 к Описанию классификатора адресов Российской Федерации (КЛАДР);
 * РРР – код района;
 * ГГГ – код города;
 * ППП – код населенного пункта,
 * АА – признак актуальности адресного объекта.
 *
 * Признак актуальности может принимать следующие значения:
 * 00 – актуальный объект (его наименование, подчиненность соответствуют состоянию на данный момент адресного пространства).
 * 01-50 – объект был переименован, в данной записи приведено одно из прежних его наименований (актуальный адресный объект присутствует в базе данных с тем же кодом, но с признаком актуальности "00”;
 * 51 – объект был переподчинен или влился в состав другого объекта (актуальный адресный объект определяется по базе Altnames.dbf);
 * 52-98 – резервные значения признака актуальности;
 * 99 – адресный объект не существует, т.е., нет соответствующего ему актуального адресного объекта.
 *
 * Файл Street.dbf – содержит записи с объектами пятого уровня классификации (улицы городов и населенных пунктов);
 * Структура кодового обозначения в блоке "Код":
 * СС РРР ГГГ ППП УУУУ АА, где
 * СС – код субъекта Российской Федерации (региона), коды регионов представлены в Приложении 2 к Описанию классификатора адресов Российской Федерации (КЛАДР);
 * РРР – код района;
 * ГГГ – код города;
 * ППП – код населенного пункта;
 * УУУУ – код улицы;
 * АА – признак актуальности наименования адресного объекта.
 *
 * файл Doma.dbf – содержит записи с объектами шестого уровня классификации (номера домов улиц городов и населенных пунктов);
 * файл Flat.dbf – содержит записи с объектами седьмого уровня классификации (номера квартир домов);
 * файл Socrbase.dbf – содержит записи с краткими наименованиями типов адресных объектов;
 * файл Altnames.dbf –содержит сведения о соответствии кодов записей со старыми и новыми наименованиями адресных объектов, а также сведения о соответствии кодов адресных объектов до и после их переподчинения.
 */
class KladrService
{
    private string $mainKladrTable = 'KLADR_KLADR';
    private string $streetKladrTable = 'KLADR_STREET';
    private string $houseKladrTable = 'KLADR_DOMA';

    private string $socrKladrTable = 'KLADR_SOCRBASE';

    public function allRegions(): Collection
    {
        return $this->allRegionsQuery()->get();
    }

    protected function allRegionsQuery(): Builder
    {
        return $this->getQuery($this->mainKladrTable)
            ->whereLike('code', '__00000000000')
            ->orderBy('name');
    }

    private function getQuery(?string $table = null, ?string $mainTable = null): Connection|Builder
    {
        $mainTable = $mainTable ?? $this->mainKladrTable;

        $ret = DB::connection('kladr');
        if ($table) return $ret->table($table)
            ->join($this->socrKladrTable, "$this->socrKladrTable.scname", "$mainTable.socr")
            ->distinct()
            ->addSelect(["$mainTable.id", "$mainTable.code", "$mainTable.name"])
            ->addSelect("$this->socrKladrTable.socrname")
            ->addSelect(DB::raw("TRIM(CONCAT(name, ' ', LOWER($this->socrKladrTable.socrname))) AS name_with_type"))
            ->orderBy('code');
        else return $ret;
    }

    public function getRegionNameById(mixed $regionId, string $field = 'name_with_type'): ?string
    {
        return $this->getRegionById($regionId)->$field ?? null;
    }

    public function getRegionById(mixed $regionId)
    {
        $regionId = str($regionId)->substr(0, 2);
        return $this->allRegionsQuery()->whereLike('code', "$regionId%")->first();
    }

    public function getStreetNameById(mixed $streetId, string $field = 'name_with_type'): ?string
    {
        return $this->getStreetById($streetId)->$field ?? null;
    }

    public function getStreetById(mixed $streetId)
    {
        return $this->allStreetsQuery()->whereLike('code', "$streetId%")->first();
    }

    public function allStreetsQuery(): Builder
    {
        return $this->getQuery($this->streetKladrTable, 'KLADR_STREET');
    }

    public function getBuildingById(mixed $buildingId, ?string $buildingNumber = null): ?object
    {
        $buildingId = (string)$buildingId;

        [$code, $number] = array_pad(explode(':', $buildingId, 2), 2, null);

        $number ??= $buildingNumber;

        $house = DB::connection('kladr')
            ->table($this->houseKladrTable)
            ->where('code', $code)
            ->first();

        if (!$house) {
            return null;
        }

        return (object)[
            'id' => $number ? "{$house->code}:{$number}" : $house->code,
            'code' => $house->code,
            'number' => $number,
            'index' => $house->index ?? null,
            'name' => $house->name,
        ];
    }

    public function getCityNameById(mixed $cityId, string $field = 'name_with_type'): ?string
    {
        return $this->getCityById($cityId)->$field ?? null;
    }

    public function getCityById(mixed $cityId)
    {
        return $this->allCitiesQuery()->whereLike('code', "$cityId%")->first();
    }

    public function allCitiesQuery(): Builder
    {
        return $this->getQuery($this->mainKladrTable);
    }

    public function allCities(?string $regionId = null): Collection
    {

        if ($regionId) {
            $regionId = str($regionId)->substr(0, 2)->trim();
        }

        $q = $this->allCitiesQuery()
            ->when($regionId, fn($q) => $q
                ->whereLike('code', "{$regionId}00000____00")
                ->whereNotLike('code', "{$regionId}00000000000")
            );

        return $q->get()->unique('code')->sortBy('name')->values();
    }

    public function allStreets(string $city): Collection
    {
        //6300000100000 - город
        //63000001000006700 - улица (пр карла маркса)
        //63000001000151500 - 20 км Московского шоссе
        //две последние цифры всегда 00

        $qq = str($city)->substr(0, 8)
            ->trim()
            ->value();

        return $this->allStreetsQuery()
            ->when($city, fn($q) => $q->whereLike('code', "{$qq}_______00"))
            ->get()->unique('code')->sortBy('name')->values();
    }

    public function allHouses(string $street): Collection
    {
        $r = $this->getQuery($this->houseKladrTable, 'KLADR_DOMA')
            ->whereLike('code', "{$street}%")
            ->get()->unique('code')->sortBy('name')
            ->mapWithKeys(function ($item) {
                $names = explode(',', $item->name);
                $ret = [];
                foreach ($names as $n) {
                    $n = trim($n);
                    if (intval($n) == $n) $n = intval($n);
                    $ret["{$item->code}:{$n}"] = $n;
                }
                return [$item->code => Arr::sort($ret)];
            })->flatMap(function ($item) {
                return $item;
            });

        return collect($r->all())->sort();
    }

    public function getIndex(string $houseFullId): ?string
    {
        $houseFullId = str($houseFullId)->beforeLast(':')->trim();

        return DB::connection('kladr')->table($this->houseKladrTable)
            ->whereLike('code', $houseFullId)->first()?->index;

    }
}
