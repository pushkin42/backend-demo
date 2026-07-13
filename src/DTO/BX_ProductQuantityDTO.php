<?php

namespace Pushkin42\LaravelHelpers\CommerceML\DTO;

final class BX_ProductQuantityDTO extends BaseParserDTO
{
    public function __construct(
        public string  $xml_id,
        public array   $rests,
    )
    {
        parent::__construct();
    }

}
