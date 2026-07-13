<?php

namespace Pushkin42\LaravelHelpers\CommerceML\DTO;

final class BX_ManufacturerDTO extends BaseParserDTO
{
    protected array $keyMap = [
        'Наименование' => 'name',
        'ОфициальноеНаименование' => 'officialName',
    ];

    public function __construct(
        public ?string  $xml_id,
        public string  $name,
        public ?string $officialName,
    )
    {
        parent::__construct();
    }

}
