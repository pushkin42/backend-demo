<?php

namespace App\DTO\Data\Order;

use App\DTO\Data\AbstractDataDTO;
use App\DTO\Data\AddressPart;
use App\Services\Global\Data\Kladr\KladrService;


class OrderDeliveryDataDTO extends AbstractDataDTO
{

    protected ?string $index = null;
    protected ?AddressPart $region = null;
    protected ?AddressPart $city = null;
    protected ?AddressPart $street = null;
    protected ?AddressPart $house = null;
    protected ?string $apartment = null;

    protected ?string $formalizedAddress;

    public function __construct(
        mixed $data
    )
    {
        $fa = data_get($data, 'formalizedAddress');
        $data = data_get($data, 'address');

        parent::__construct($data);

        $this->region = AddressPart::make($this->kladrLookupName($data, 'region'));
        $this->city = AddressPart::make($this->kladrLookupName($data, 'city'));
        $this->street = AddressPart::make($this->kladrLookupName($data, 'street'));
        $this->house = AddressPart::make($this->kladrLookupName($data, 'house'));

        $this->formalizedAddress = $fa;
    }

    protected function kladrLookupName($data, string $entity, ?string $id = null): ?array
    {
        if (!$data) return null;

        $id = $id ?? data_get($data, $entity);

        $ks = app(KladrService::class);
        $tmp = data_get($data, $entity);
        if ($tmp) {
            $uf = ucfirst(strtolower($entity));
            $func = "get{$uf}NameById";
            return [
                'kladr_id' => $tmp,
                'value' => $ks->$func($id) ?? null,
            ];
        } else {
            return null;
        }
    }
}
