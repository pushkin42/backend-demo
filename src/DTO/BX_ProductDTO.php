<?php

namespace Pushkin42\LaravelHelpers\CommerceML\DTO;

use InvalidArgumentException;

class BX_ProductDTO extends BaseParserDTO
{

    public function __construct(
        public ?string          $xml_id,
        public ?string          $version,
        public ?string          $deleted_at,
        public ?string          $b_code,
        public readonly ?string $article,
        public readonly string  $name,
        public readonly ?string $base_measure_id,
        public readonly ?array  $groups = [],
        public readonly ?string $description,
        public readonly ?array  $pictures = [],
        public readonly ?string $country,
        public readonly ?BX_ManufacturerDTO $manufacturer,
        public readonly ?array              $props = [],
        public readonly ?array              $taxes = [],
        public ?array                       $req = [],
    )
    {
        $bc = trim(data_get($req, 'b_code'));
        if (!$this->b_code) {
            $this->b_code = $bc;
        }
        if ($this->b_code == $bc) {
            data_forget($this->req, 'b_code');
        }

        parent::__construct();
    }
}
