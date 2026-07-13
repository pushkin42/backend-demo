<?php

namespace Pushkin42\LaravelHelpers\CommerceML\Classes\Factories;

use Pushkin42\LaravelHelpers\CommerceML\Classes\Parsers\BX_BaseParser;
use Pushkin42\LaravelHelpers\CommerceML\Classes\Parsers\BX_GoodsParser;
use Pushkin42\LaravelHelpers\CommerceML\Classes\Parsers\BX_GroupsParser;
use Pushkin42\LaravelHelpers\CommerceML\Classes\Parsers\BX_OffersParser;
use Pushkin42\LaravelHelpers\CommerceML\Classes\Parsers\BX_PriceListsParser;
use Pushkin42\LaravelHelpers\CommerceML\Classes\Parsers\BX_PricesParser;
use Pushkin42\LaravelHelpers\CommerceML\Classes\Parsers\BX_ProductPropertiesParser;
use Pushkin42\LaravelHelpers\CommerceML\Classes\Parsers\BX_RestsParser;
use Pushkin42\LaravelHelpers\CommerceML\Classes\Parsers\BX_StoragesParser;
use Pushkin42\LaravelHelpers\CommerceML\Classes\Parsers\BX_UnitsParser;
use Pushkin42\LaravelHelpers\CommerceML\Interfaces\BX_FileParserInterface;
use Pushkin42\LaravelHelpers\CommerceML\Interfaces\BX_IncomingFileDataInterface;

class BX_FileParserFactory
{
    protected array $supports = [
        BX_GoodsParser::class,
        BX_RestsParser::class,
        BX_PricesParser::class,
        BX_ProductPropertiesParser::class,
        BX_PriceListsParser::class,
        BX_UnitsParser::class,
        BX_StoragesParser::class,
        BX_GroupsParser::class,
        BX_OffersParser::class,
    ];

    public static function make(BX_IncomingFileDataInterface $fileData): ?BX_FileParserInterface
    {
        $c = new static();
        foreach ($c->supports as $parser) {
            if (!class_exists($parser)) continue;
            /**
             * @var BX_BaseParser $cl
             */
            $cl = new $parser($fileData);
            if ($cl->supports()) {
                return $cl;
            }
        }

        return null;
    }
}
