<?php

namespace Pushkin42\LaravelHelpers\CommerceML\Classes\Parsers;

use Pushkin42\LaravelHelpers\CommerceML\Classes\Casters\ProductGroupsFieldCaster;
use Pushkin42\LaravelHelpers\CommerceML\Classes\Casters\ProductPicturesFieldCaster;
use Pushkin42\LaravelHelpers\CommerceML\Classes\Casters\ProductPropsFieldCaster;
use Pushkin42\LaravelHelpers\CommerceML\Classes\Casters\ProductReqFieldCaster;
use Pushkin42\LaravelHelpers\CommerceML\Classes\Casters\ProductTaxesFieldCaster;
use Pushkin42\LaravelHelpers\CommerceML\DTO\BX_ManufacturerDTO;
use Pushkin42\LaravelHelpers\CommerceML\DTO\BX_ProductDTO;

class BX_GoodsParser extends BX_BaseParser
{
    protected ?string $rootKey = 'Товары';
    protected ?string $dtoClass = BX_ProductDTO::class;

    protected array $keyMap = [
        'Штрихкод' => 'b_code',
        'Артикул' => 'article',
        'Картинка' => 'pictures',
        'Описание' => 'description',
        'Страна' => 'country',
        'Изготовитель' => 'manufacturer',
        'Наименование' => 'name',
        'БазоваяЕдиница' => 'base_measure_id',
        'Группы' => 'groups',
        'СтавкиНалогов' => 'taxes',
        'ЗначенияСвойств' => 'props',
        'ЗначенияРеквизитов' => 'req',
        'Ширина' => 'width',
        'Длина' => 'length',
        'Высота' => 'height',
    ];

    protected array $casts = [
        'manufacturer' => BX_ManufacturerDTO::class,
        'groups' => ProductGroupsFieldCaster::class,
        'taxes' => ProductTaxesFieldCaster::class,
        'props' => ProductPropsFieldCaster::class,
        'req' => ProductReqFieldCaster::class,
        'pictures' => ProductPicturesFieldCaster::class
    ];

}
