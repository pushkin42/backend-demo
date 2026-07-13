<?php

namespace Pushkin42\LaravelHelpers\CommerceML\Classes\Parsers;

use Illuminate\Support\LazyCollection;
use Pushkin42\LaravelHelpers\CommerceML\Classes\Casters\ProductPropertiesArrayCaster;
use Pushkin42\LaravelHelpers\CommerceML\Classes\Casters\ProductRestsFieldCaster;
use Pushkin42\LaravelHelpers\CommerceML\DTO\BX_ProductPropertiesDTO;
use Pushkin42\LaravelHelpers\CommerceML\DTO\BX_ProductQuantityDTO;
use Pushkin42\LaravelHelpers\CommerceML\DTO\BX_StorageUnitDTO;
use Pushkin42\LaravelHelpers\CommerceML\Enums\BX_PropReferenceType;

class BX_UnitsParser extends BX_BaseParser
{
    protected ?string $rootKey = 'Классификатор';
    protected ?string $subKey = 'ЕдиницыИзмерения';

    protected ?string $dtoClass = BX_StorageUnitDTO::class;

    protected array $keyMap = [
        'НаименованиеКраткое' => 'name',
        'Код' => 'code',
        'НаименованиеПолное' => 'full_name',
        'МеждународноеСокращение' => 'name_mn'
    ];

    protected array $casts = [
    ];

}
